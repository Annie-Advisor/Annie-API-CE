<?php
/* annieuser.php
 * Copyright (c) 2020-2021 Annie Advisor
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
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, PUT, POST, DELETE';
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
    $ret = $anniedb->selectAnnieuser($key);
    if ($ret !== false) {
      http_response_code(200);
      echo json_encode($ret);
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED"));
    }
    break;
  case 'PUT':
    if ($key && $input) {
      $ret = $anniedb->updateAnnieuser($key,$input);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    }
    break;
  case 'POST':
    if ($input) {//key is actually not mandatory here!
      $ret = $anniedb->insertAnnieuser($key,$input);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK", "id"=>$ret));
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    }
    break;
  case 'DELETE':
    if ($key) {
      $ret = $anniedb->deleteAnnieuser($key);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    }
    break;
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>