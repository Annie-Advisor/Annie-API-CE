<?php
/* auth.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Part of any backend script between AnnieUI and Annie database.
 * Authentication
 * It is important and convenient that the check is against same auth as UI.
 * First we check for Basic Auth for communication between systems.
 *
 * Requires settings to be read in.
 */

/*
$valid_user = $settings['api']['user'];
$valid_pass = $settings['api']['pass'];
$valid_passwords = array($valid_user => $valid_pass);
*/
$valid_passwords = array();
foreach ($settings['api'] as $apikey => $user) {
  $apipass = null;
  if (substr($apikey, 0, 4) === "user") {
    $apipass = str_replace("user", "pass", $apikey);
    $valid_passwords[$user] = $settings['api'][$apipass];
  }
}
$valid_users = array_keys($valid_passwords);

$validated = false;

if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
  $user = $_SERVER['PHP_AUTH_USER'];
  $pass = $_SERVER['PHP_AUTH_PW'];

  $validated = (in_array($user, $valid_users)) && ($pass == $valid_passwords[$user]);
}

if ($validated) {
  $auth_uid = strtolower($user);
} else {
  //header('HTTP/1.0 401 Unauthorized');
  //die ("Not authorized");
  // the same as frontend relies on!
  require_once 'my_app_specific_simplesaml_dir/lib/_autoload.php';
  $as = new \SimpleSAML\Auth\Simple('my_app_specific_saml');
  $as->requireAuth();
  foreach ($as->getAttributes() as $k => $v) {
    // nb! $v is an array but we get the first one in any case
    switch ($k) {
      case 'uid':
      case 'urn:mpass.id:uid':
      case 'MPASS-10-MPASSUID':
        //error_log("DEBUG: lib/auth: v[0]=".$v[0]);
        $auth_uid = strtolower($v[0]);
        error_log("INFO: lib/auth: auth_uid=$auth_uid");
        break;
        // no default
    }
  }
}
// If arrives here, is a valid user.

// included file, no end tag