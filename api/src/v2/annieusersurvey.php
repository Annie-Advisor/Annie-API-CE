<?php
/* annieusersurvey.php
 * Copyright (c) 2020-2022 Annie Advisor
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

// get parameters as an array (names here arent mandatory)
$getarr = array("survey"=>[],"id"=>[]);
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
    // check only overall coordinator status here
    $sql = "
      SELECT 1 WHERE (1=0
        or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_uid) in (select annieuser from $dbschm.usageright_coordinator)
      )
    ";
    $sth = $anniedb->getDbh()->prepare($sql);
    $sth->execute(array(':auth_uid' => $auth_uid));
    if ($sth->rowCount() <= 0) {
      http_response_code(401);
      echo json_encode(array("status"=>"UNAUTHORIZED"));
      exit;
    }
    $ret = $anniedb->selectAnnieusersurvey($key,$getarr,$auth_uid);
    if ($ret !== false) {
      http_response_code(200);
      echo json_encode($ret);
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED"));
    }
    break;
  case 'PUT':
    // check only overall coordinator status here
    $sql = "
      SELECT 1 WHERE (1=0
        or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_uid) in (select annieuser from $dbschm.usageright_coordinator)
      )
    ";
    $sth = $anniedb->getDbh()->prepare($sql);
    $sth->execute(array(':auth_uid' => $auth_uid));
    if ($sth->rowCount() <= 0) {
      http_response_code(401);
      echo json_encode(array("status"=>"UNAUTHORIZED"));
      exit;
    }
    if ($key && $input) {
      $ret = $anniedb->updateAnnieusersurvey($key,$input,$auth_uid);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"input missing"));
    }
    break;
  case 'POST':
    // check only overall coordinator status here
    $sql = "
      SELECT 1 WHERE (1=0
        or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_uid) in (select annieuser from $dbschm.usageright_coordinator)
      )
    ";
    $sth = $anniedb->getDbh()->prepare($sql);
    $sth->execute(array(':auth_uid' => $auth_uid));
    if ($sth->rowCount() <= 0) {
      http_response_code(401);
      echo json_encode(array("status"=>"UNAUTHORIZED"));
      exit;
    }
    if ($input) {
      $ret = $anniedb->insertAnnieusersurvey($input,$auth_uid);//nb! input is treated as list
      if ($ret !== false) {
        // get the overall batch status
        if (array_search("FAILED", array_column($ret, "status"), true) !== false) {
          http_response_code(409); // Conflict
        } else {
          http_response_code(200);
        }
        echo json_encode($ret);
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"input missing"));
    }
    break;
  case 'DELETE':
    // check only overall coordinator status here
    $sql = "
      SELECT 1 WHERE (1=0
        or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_uid) in (select annieuser from $dbschm.usageright_coordinator)
      )
    ";
    $sth = $anniedb->getDbh()->prepare($sql);
    $sth->execute(array(':auth_uid' => $auth_uid));
    if ($sth->rowCount() <= 0) {
      http_response_code(401);
      echo json_encode(array("status"=>"UNAUTHORIZED"));
      exit;
    }
    if ($key) {
      $ret = $anniedb->deleteAnnieusersurvey($key);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else {
        http_response_code(409); // Conflict
        echo json_encode(array("status"=>"FAILED"));
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED"));
    }
    break;
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>