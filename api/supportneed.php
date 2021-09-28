<?php
/* supportneed.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 *
 * NB! This supportneed API also gets survey and contact data. For efficiency.
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
$input = file_get_contents('php://input');

// resolve input to same array type for both content-types
// (or assume application/json if not x-www-form-urlencoded used below)
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

$key = null;//nb! key is multipurpose! on GET theres contact.id and on PUT/POST there is supportneed.id
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
# get parametees as an array
$getarr = array("category"=>[],"status"=>[],"survey"=>[],"userrole"=>[]);
# split on outer delimiter
$pairs = explode('&', $_SERVER['QUERY_STRING']);
# loop through each pair
foreach ($pairs as $i) {
  if ($i) {
    # split into name and value
    list($name,$value) = explode('=', $i, 2);
    // fix value (htmlspecialchars for extra security)
    $value = urldecode(htmlspecialchars($value));
    //error_log("pairs: ".$name." => ".$value);
    # if name already exists
    if( isset($getarr[$name]) ) {
      # stick multiple values into an array
      if( is_array($getarr[$name]) ) {
        $getarr[$name][] = $value;
      }
      else {
        $getarr[$name] = array($getarr[$name], $value);
      }
    } else {# otherwise, simply stick it in a scalar
      $getarr[$name] = array($value);
    }
  }
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    //to-do-ish: "normal" selectSupportneed?
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
    if ($key && $input) {
      if (!array_key_exists('id', $input)) {
        http_response_code(400); // bad request
        exit;
      }
    }
    // continue to post (no break intentionally)
  case 'POST':
    if ($key && $input) {
      $ret = $anniedb->insertSupportneed($key,$input);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK", "id"=>$ret));
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    } else {
      http_response_code(400); // bad request
      echo json_encode(array("status"=>"FAILED"));
      exit;
    }
    break;
  case 'DELETE':
    if ($key) {
      $ret = $anniedb->deleteSupportneed($key);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    }
    break;
}

?>