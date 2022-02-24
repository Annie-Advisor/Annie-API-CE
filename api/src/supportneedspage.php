<?php
/* supportneedspage.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 *
 * NB! This supportneed API also gets survey and contact data. For efficiency.
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/auth.php';

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET'; //PUT, POST, DELETE
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
//no $input = file_get_contents('php://input');

$key = null;//nb! key is for contact.id
$history = null;
if (count($request)>=1) {
  $key = array_shift($request);
}
if (count($request)>=1) {
  $history = array_shift($request);
}

// pagination related arguments
// use _SERVER['QUERY_STRING'] instead of _GET
//error_log("QUERY_STRING: ".$_SERVER['QUERY_STRING']);
// get parametees as an array
$getarr = array("category"=>[],"status"=>[],"survey"=>[],"userrole"=>[]);
// split on outer delimiter
$pairs = explode('&', $_SERVER['QUERY_STRING']);
// loop through each pair
foreach ($pairs as $i) {
  if ($i) {
    // split into name and value
    list($name,$value) = explode('=', $i, 2);
    // fix value (htmlspecialchars for extra security)
    $value = urldecode(htmlspecialchars($value));
    // if name already exists
    if( isset($getarr[$name]) ) {
      // stick multiple values into an array
      if( is_array($getarr[$name]) ) {
        $getarr[$name][] = $value;
      }
      else {
        $getarr[$name] = array($getarr[$name], $value);
      }
    } else {// otherwise, simply stick it in a scalar
      $getarr[$name] = array($value);
    }
  }
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    //nb! key is actually contact.id here
    $ret = $anniedb->selectSupportneedsPage($key,$history,$getarr);
    if ($ret !== false) {
      http_response_code(200);
      echo json_encode($ret);
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED"));
    }
    break;
  case 'PUT':
  case 'POST':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>