<?php
/* survey.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 */

require_once('/opt/annie/settings.php');//->settings,db*
require_once('/opt/annie/auth.php');

require_once('/opt/annie/anniedb.php');
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

require '/opt/annie/http_response_code.php';

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

// get parameters as an array (names here arent mandatory)
$getarr = array("id"=>[],"status"=>[]);
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
    if (isset($getarr[$name])) {
      // stick multiple values into an array
      if (is_array($getarr[$name])) {
        $getarr[$name][] = $value;
      } else {
        $getarr[$name] = array($getarr[$name], $value);
      }
    } else { // otherwise, simply stick it in
      $getarr[$name] = array($value);
    }
  }
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    $ret = $anniedb->selectSurvey($key,$getarr);
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
      $ret = $anniedb->updateSurvey($key,$input);
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
    if ($key && $input) {
      //nb! "users problem": key (survey.id) must be given since database does not generate ids for survey
      $ret = $anniedb->insertSurvey($key,$input);
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
    // nb! deliberately avoid deleting surveys!
    // surveys are essential part of database and careless deleting will cause serious damage
    // contact database admin or something...
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>