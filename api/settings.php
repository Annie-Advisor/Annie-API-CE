<?php
/* settings.php
 * Copyright (c) 2019 Annie Advisor
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

// included file, no end tag