<?php
/* supportneedsupportneedcomments.php
 * Copyright (c) 2021-2022 Annie Advisor
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
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET'; //POST, PUT, DELETE
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

// impersonate via get parameter; the long way but used elsewhere also:
// get parameteres as an array
$getarr = array();
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
    // parameter is mandatory
    if (empty($key)) {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"key missing"));
    } else {
      // impersonate
      $impersonate = null;
      if (array_key_exists("impersonate", $getarr)) {
        // value is coming in an array of arrays but only one (1st) is tried
        if ($getarr["impersonate"][0]) {
          // check that auth_uid has permission to do impersonation
          $sth = $anniedb->getDbh()->prepare("select 1 is_superuser from $dbschm.annieuser where id=:auth_uid and superuser and coalesce(validuntil,'9999-09-09') > now()");
          $sth->bindParam(':auth_uid',$auth_uid);
          if (!$sth->execute()) {
            error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
            return false;
          }
          if ($sth->rowCount() > 0) {
            error_log("INFO: supportneedsupportneedcomments: auth_uid=$auth_uid impersonated as ".$getarr["impersonate"][0]);
            $impersonate = $getarr["impersonate"][0];
          } else {
            error_log("INFO: supportneedsupportneedcomments: auth_uid=$auth_uid TRIED TO IMPERSONATE AS ".$getarr["impersonate"][0]." BUT HAS NO RIGHT");
            http_response_code(401);
            echo json_encode(array("status"=>"UNAUTHORIZED"));
            exit;
          }
        }
      }

      // nb! access right check done within SQL
      // nb! key is for supportneed
      $ret = $anniedb->selectSupportneedSupportneedcomments($key,$getarr,$auth_uid,$impersonate);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode($ret);
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    }
    break;
  case 'POST':
  case 'PUT':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>