<?php
/* annieuser.php
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
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, POST, DELETE';//PUT
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
    // check only overall coordinator status here (all we can do)
    $sql = "
      SELECT 1 WHERE (1=0
        or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_uid) in (select annieuser from $dbschm.usageright_coordinator)
      )
    ";
    $sth = $anniedb->getDbh()->prepare($sql);
    $sth->execute(array(':auth_uid' => $auth_uid));
    if ($sth->rowCount() <= 0) {
      // query self data as exception!
      if (!empty($key) && $key != $auth_uid) {
        http_response_code(401);
        echo json_encode(array("status"=>"UNAUTHORIZED"));
        exit;
      }
      $key = $auth_uid;
    }
    $ret = $anniedb->selectAnnieuser($key);
    if ($ret !== false) {
      http_response_code(200);
      echo json_encode($ret);
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED"));
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
      // nb! key is not used here at all as input may be a list with many objects
      $ret = $anniedb->insertAnnieuser(null,$input);
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
        exit;
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"input missing"));
      exit;
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
      $ret = $anniedb->deleteAnnieuser($key);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK"));
      } else {
        http_response_code(409); // Conflict
        echo json_encode(array("status"=>"FAILED"));
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"key missing"));
    }
    break;
  case 'PUT':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>