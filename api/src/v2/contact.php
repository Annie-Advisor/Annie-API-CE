<?php
/* contact.php
 * Copyright (c) 2019-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 *
 * NB! This contact API also gets most recent support needs. For efficiency.
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/auth.php';

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET';//PUT, POST, DELETE
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
    $sql = "
      SELECT 1 WHERE (1=0
        or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_uid) in (select annieuser from $dbschm.usageright_coordinator)
        -- survey is NOT set but user still has access
        or (:auth_uid) in (select annieuser from $dbschm.usageright_provider)
        or (
          -- student is NOT set but user still has access
          (:auth_uid) in (select annieuser from $dbschm.usageright_teacher)
          --cant negate providers due to no category because of no supportneed via survey
        )
      )
    ";
    $sth = $anniedb->getDbh()->prepare($sql);
    $sth->execute(array(':auth_uid' => $auth_uid));
    if ($sth->rowCount() <= 0) {
      http_response_code(401);
      echo json_encode(array("status"=>"UNAUTHORIZED"));
      exit;
    }
    $ret = $anniedb->selectContact($key,$auth_uid);
    if ($ret !== false) {
      http_response_code(200);
      echo json_encode($ret);
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