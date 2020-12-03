<?php
/* contactsurvey.php
 * Copyright (c) 2019,2020 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 */

require_once('settings.php');//->settings,db*
require_once('auth.php');

require_once('anniedb.php');
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass);

require 'http_response_code.php';

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
$input = file_get_contents('php://input');

// resolve input to same array type for both content-types
// (and assume application/json if not given)
if (isset($_SERVER['CONTENT_TYPE'])
 &&($_SERVER['CONTENT_TYPE'] == "application/x-www-form-urlencoded; charset=utf-8"
  ||$_SERVER['CONTENT_TYPE'] == "application/x-www-form-urlencoded")
) {
  // parse url encoded to a object
  parse_str(urldecode($input), $parsed);
  // to json string keeping unicode
  $input = json_encode($parsed, JSON_UNESCAPED_UNICODE);
  // to array
  $input = json_decode($input);
} else {
  // not quite sure these are needed!
  $input = json_decode(json_encode(json_decode($input), JSON_UNESCAPED_UNICODE));
}

$key = null;
if (count($request)>=1) {
  $key = array_shift($request);
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    $ret = $anniedb->selectContactsurvey($key);
    if ($ret !== false) {
      http_response_code(200);
      echo json_encode($ret);
    }
    break;
  case 'PUT':
  case 'POST':
    if ($key && $input) {
      $ret = $anniedb->insertContactsurvey($key,$input);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK", "id"=>$ret));
      } else {
        echo json_encode(array("status"=>"FAILED"));
      }
    }
    break;
  case 'DELETE':
    if ($key) {
      $ret = $anniedb->deleteContactsurvey($key);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else {
        echo json_encode(array("status"=>"FAILED"));
      }
    }
    break;
}

?>