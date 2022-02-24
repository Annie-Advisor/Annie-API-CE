<?php
/* followup.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/auth.php';

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, PUT';//POST, DELETE
$headers[]='Access-Control-Allow-Origin: *';
$headers[]='Access-Control-Allow-Credentials: true';
$headers[]='Access-Control-Max-Age: 1728000';
if (isset($_SERVER['REQUEST_METHOD'])) {
  foreach ($headers as $header) header($header);
} else {
  echo json_encode($headers);
}
header('Content-Type: application/json; charset=utf-8');

// get the HTTP method, path and body of the request
$method = $_SERVER['REQUEST_METHOD'];
if ($method=='OPTIONS') {
  http_response_code(200);
  exit;
}

$request = array();
if (isset($_SERVER['PATH_INFO'])) {
  $request = explode('/', trim($_SERVER['PATH_INFO'],'/'));
}
$input = json_decode(file_get_contents('php://input'));

$key = null;
if (count($request)>=1) {
  $key = array_shift($request);
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    $rows = $anniedb->selectSurvey($key,null);
    echo json_encode($rows);
    http_response_code(200);
    break;
  case 'PUT':
    if ($key) {
      $ret = $anniedb->updateFollowupContacts($key,$auth_uid);
      if ($ret === 0) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else if ($ret === 1) {
        http_response_code(204); // No Content
        echo json_encode(array("status"=>"OK", "message"=>"no content")); //doesnt show but no matter
        exit;
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
        exit;
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"argument missing"));
      exit;
    }
    break;
  case 'POST':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>