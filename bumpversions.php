<?php

// We need the branch and the bump type (weekly. minor, major)
try {
    $shortoptions = 'b:t:p:r:d:';
    $longoptions = array('branch:', 'type:', 'path:', 'rc:', 'date:');

    $options = getopt($shortoptions, $longoptions);
    $branch = get_option_from_options_array($options, 'b', 'branch');
    $type = get_option_from_options_array($options, 't', 'type');
    $path = get_option_from_options_array($options, 'p', 'path');
    $rc = get_option_from_options_array($options, 'r', 'rc');
    $date = get_option_from_options_array($options, 'd', 'date');
    $path = rtrim($path, '/').'/version.php';

    $release = bump_version($path, $branch, $type, $rc, $date);
    $result = 0;
} catch (Exception $ex) {
    $release = $ex->getMessage();
    $result = $ex->getCode();
}
echo $release;
exit($result);



function bump_version($path, $branch, $type, $rc = null, $date = null) {

    validate_branch($branch);
    validate_type($type);
    validate_path($path);

    $versionfile = file_get_contents($path);

    validate_version_file($versionfile, $branch);

    $is19 = ($branch === 'MOODLE_19_STABLE');
    $isstable = branch_is_stable($branch);
    $today = date('Ymd');

    $versionmajorcurrent = null;
    $versionminorcurrent = null;
    $releasecurrent = null;
    $buildcurrent = null;
    $branchcurrent = null;
    $maturitycurrent = null;

    $branchquote = null;
    $releasequote = null;

    $versionmajornew = null;
    $versionminornew = null;
    $releasenew = null;
    $buildnew = null;
    $branchnew = null;
    $maturitynew = null;

    if (!preg_match('#^ *\$version *= *(?P<major>\d{10})\.(?P<minor>\d{2})\d?#m', $versionfile, $matches)) {
        throw new Exception('Could not determine version.', __LINE__);
    }
    $versionmajornew = $versionmajorcurrent = $matches['major'];
    $versionminornew = $versionminorcurrent = $matches['minor'];

    if (!preg_match('#^ *\$release *= *(?P<quote>\'|")(?P<release>[^ \+]+\+?) *\(Build: (?P<build>\d{8})\)\1#m', $versionfile, $matches)) {
        throw new Exception('Could not determine the release.', __LINE__);
    }
    $releasenew = $releasecurrent = $matches['release'];
    $releasequote = $matches['quote'];
    $buildcurrent = $matches['build'];
    $buildnew = empty($date) ? $today : $date; // Observe forced date.

    if (!$is19) {
        if (!preg_match('# *\$branch *= *(?P<quote>\'|")(?P<branch>\d+)\1#m', $versionfile, $matches)) {
            throw new Exception('Could not determine branch.', __LINE__);
        }
        $branchquote = $matches['quote'];
        $branchnew = $branchcurrent = $matches['branch'];
        if (!preg_match('# *\$maturity *= *(?P<maturity>MATURITY_[A-Z]+)#m', $versionfile, $matches)) {
            throw new Exception('Could not determine maturity.', __LINE__);
        }
        $maturitynew = $maturitycurrent = $matches['maturity'];
    }

    if ($isstable) {
        // It's a stable branch.
        if ($type === 'weekly') {
            // It's a stable branch. We need to bump the minor version and add a + if this was the first
            // weekly release after a major or minor release.
            if (strpos($releasenew, '+') === false) {
                // Add the +
                $releasenew .= '+';
            }
            $versionminornew++;
            $maturitynew = 'MATURITY_STABLE';
        } else if ($type === 'minor' || $type === 'major') {
            // If it's minor fine, it's if major then stable gets a minor release.
            // 2.6+ => 2.6.1
            // 2.6.12+ => 2.6.13
            if (strpos($releasenew, '+') !== false) {
                // Strip the +1 off
                $releasenew = substr($releasenew, 0, -1);
            }
            if (preg_match('#^(?P<version>\d+\.\d+)\.(?P<increment>\d+)#', $releasenew, $matches)) {
                $increment = $matches['increment'] + 1;
                $releasenew = $matches['version'].'.'.(string)$increment;
            } else {
                // First minor release on this stable branch. Yay X.Y.1.
                $releasenew .= '.1';
            }
            $versionmajornew = (int)$versionmajornew + 1;
            $versionmajornew = (string)$versionmajornew;
            $versionminornew = '00';
            // Now handle builddate for releases.
            if (empty($date)) { // If no date has been forced, stable minors always are released on Monday.
                if ((int)date('N') !== 1) { // If today is not Monday, calculate next one.
                    $buildnew = date('Ymd', strtotime('next monday'));
                }
            }
        }

    } else {
        // Ok it's the master branch.
        if ($type === 'weekly' || $type === 'minor') {
            // If it's weekly fine, if it's minor the master branch doesn't get a minor release so really it's a weekly anyway.
            // It's the master branch. We need to bump the version, if the version is already higher than today*100 then we need
            // to bump accordingly.
            // We also need to add "dev" to the end of the version if it's not there (first weekly after a major release).
            if (strpos($releasecurrent, 'dev') === false) {
                // Must be immediately after a major release. Bump the release version and set maturity to Alpha.
                $releasenew = (float)$releasenew + 0.1;
                $releasenew = (string)$releasenew.'dev';
                $maturitynew = 'MATURITY_ALPHA';
            }
            list($versionmajornew, $versionminornew) = bump_master_ensure_higher($versionmajornew, $versionminornew);
        } else if ($type === 'beta') {
            $releasenew = preg_replace('#^(\d+.\d+) *(dev)#', '$1', $releasenew);
            $branchnew = str_replace('.', '', $releasenew);
            $releasenew .= 'beta';
            list($versionmajornew, $versionminornew) = bump_master_ensure_higher($versionmajornew, $versionminornew);
            $maturitynew = 'MATURITY_BETA';
        } else if ($type === 'rc') {
            $releasenew = preg_replace('#^(\d+.\d+) *(dev|beta)#', '$1', $releasenew);
            $branchnew = str_replace('.', '', $releasenew);
            $releasenew .= 'rc'.$rc;
            list($versionmajornew, $versionminornew) = bump_master_ensure_higher($versionmajornew, $versionminornew);
            $maturitynew = 'MATURITY_RC';
        } else if ($type === 'on-demand') {
            list($versionmajornew, $versionminornew) = bump_master_ensure_higher($versionmajornew, $versionminornew);
        } else if ($type === 'on-sync') {
            $versionminornew++;
        } else {
            // Awesome major release!
            $releasenew = preg_replace('#^(\d+.\d+) *(dev|beta|rc\d+)#', '$1', $releasenew);
            $branchnew = str_replace('.', '', $releasenew);
            list($versionmajornew, $versionminornew) = bump_master_ensure_higher($versionmajornew, $versionminornew);
            $maturitynew = 'MATURITY_STABLE';
            // Now handle builddate for releases.
            if (empty($date)) { // If no date has been forced, master majors always are released on Monday.
                if ((int)date('N') !== 1) { // If today is not Monday, calculate next one.
                    $buildnew = date('Ymd', strtotime('next Monday'));
                }
            }
        }
    }

    // Replace the old version with the new version.
    if (strlen($versionminornew) === 1) {
        $versionminornew = '0'.$versionminornew;
    }
    $versionfile = str_replace($versionmajorcurrent.'.'.$versionminorcurrent, $versionmajornew.'.'.$versionminornew, $versionfile);
    // Replace the old build with the new build.
    $versionfile = str_replace('Build: '.$buildcurrent, 'Build: '.$buildnew, $versionfile);
    // Replace the old release with the new release if they've changed.
    if ($releasecurrent !== $releasenew) {
        $versionfile = str_replace($releasequote.$releasecurrent, $releasequote.$releasenew, $versionfile);
    }

    if (!$is19) {
        // Replace the branch value if need be.
        if ($branchcurrent !== $branchnew) {
            $versionfile = str_replace($branchquote.$branchcurrent.$branchquote, $branchquote.$branchnew.$branchquote, $versionfile);
        }
        // Replace the maturity value if need be.
        if ($maturitycurrent !== $maturitynew) {
            $versionfile = str_replace('= '.$maturitycurrent, '= '.$maturitynew, $versionfile);
        }
    }

    file_put_contents($path, $versionfile);

    return $releasenew;
}

function bump_master_ensure_higher($major, $minor) {
    $today = date('Ymd');
    if ($major >= $today*100) {
        // Version is already past today * 100, increment minor version instead of major version.
        $minor = (int)$minor + 1;
        $minor = (string)$minor;
    } else {
        $major = $today.'00';
        $minor = '00';
    }
    return array($major, $minor);
}

function branch_is_stable($branch) {
    return  (strpos($branch, '_STABLE') !== false);
}

function validate_branch($branch) {
    if (!preg_match('#^(master|MOODLE_(\d+)_STABLE)$#', $branch, $matches)) {
        throw new Exception('Invalid branch given', __LINE__);
    }
    return true;
}

function validate_type($type) {
    $types = array('weekly', 'minor', 'major', 'beta', 'rc', 'on-demand', 'on-sync');
    if (!in_array($type, $types)) {
        throw new Exception('Invalid type given.', __LINE__);
    }
    return true;
}

function validate_path($path) {
    if (file_exists($path) && is_readable($path)) {
        if (is_writable($path)) {
            return true;
        }
        throw new Exception('Path cannot be written to.', __LINE__);
    }
    throw new Exception('Invalid path given.', __LINE__);
}

function validate_version_file($contents, $branch) {
    $hasversion = strpos($contents, '$version ') !== false;
    $hasrelease = strpos($contents, '$release ') !== false;
    $hasbranch = strpos($contents, '$branch ') !== false;
    $hasmaturity = strpos($contents, '$maturity ') !== false;

    if ($hasversion && $hasrelease && $hasbranch && $hasmaturity) {
        return true;
    }
    if ($branch === 'MOODLE_19_STABLE' && $hasversion && $hasrelease) {
        return true;
    }
    throw new Exception('Invalid version file found.', __LINE__);
}

function get_option_from_options_array(array $options, $short, $long) {
    if (!isset($options[$short]) && !isset($options[$long])) {
        throw new Exception("Required option -$short|--$long must be provided.", __LINE__);
    }
    if ((isset($options[$short]) && is_array($options[$short])) ||
        (isset($options[$long]) && is_array($options[$long])) ||
        (isset($options[$short]) && isset($options[$long]))) {
        throw new Exception("Option -$short|--$long specified more than once.", __LINE__);
    }
    return (isset($options[$short])) ? $options[$short] : $options[$long];
}
