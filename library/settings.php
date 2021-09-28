<?php
/* settings.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Part of any backend script between AnnieUI and Annie database.
 * Centralized settings reading from ini.
 */

$settings = parse_ini_file('my_app_specific_ini', true);

$dbhost = $settings['database']['host'];
$dbport = $settings['database']['port'];
$dbname = $settings['database']['name'];
$dbschm = $settings['database']['schm'];
$dbuser = $settings['database']['user'];
$dbpass = $settings['database']['pass'];

$salt   = $settings['api']['salt'];

// So, PHP seems to have its own idea of default timezone (for php-cli atleast). Should set it separately in INI.
// Luckily via comment on page: https://www.php.net/manual/en/function.date-default-timezone-set.php
function setTimezone($default) {
    $timezone = "";
   
    // On many systems (Mac, for instance) "/etc/localtime" is a symlink
    // to the file with the timezone info
    if (is_link("/etc/localtime")) {
       
        // If it is, that file's name is actually the "Olsen" format timezone
        $filename = readlink("/etc/localtime");
       
        $pos = strpos($filename, "zoneinfo");
        if ($pos) {
            // When it is, it's in the "/usr/share/zoneinfo/" folder
            $timezone = substr($filename, $pos + strlen("zoneinfo/"));
        } else {
            // If not, bail
            $timezone = $default;
        }
    }
    else {
        // On other systems, like Ubuntu, there's file with the Olsen time
        // right inside it.
        $timezone = file_get_contents("/etc/timezone");
        if (!strlen($timezone)) {
            $timezone = $default;
        }
    }
    date_default_timezone_set($timezone);
}
setTimezone(null); //or with param "Europe/Helsinki"

// included file, no end tag