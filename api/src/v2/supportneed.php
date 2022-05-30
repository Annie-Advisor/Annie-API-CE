<?php
/* supportneed.php
 * Copyright (c) 2021-2022 Annie Advisor
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
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, POST'; // PUT, DELETE
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

$key = null;
if (count($request)>=1) {
  $key = array_shift($request);
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    // parameter is mandatory
    if (empty($key)) {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"key missing"));
    } else {
      $sql = "
        SELECT 1
        FROM $dbschm.supportneed
        WHERE (1=0
          or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
          or (:auth_uid,survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
          or (:auth_uid,survey,category) in (select annieuser,survey,category from $dbschm.usageright_provider)
          or (
            (:auth_uid,contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
            and (survey,category) NOT in (select survey,category from $dbschm.usageright_provider)
          )
        )
        -- check usage right from the latest supportneed (=current)
        AND id = (
          select max(snlast.id)
          from $dbschm.supportneed snlast
          where snlast.contact = supportneed.contact
          and snlast.survey = supportneed.survey
          -- given id is in this request chain
          and :supportneed in (
            select snchain.id from $dbschm.supportneed snchain
            where snchain.contact = supportneed.contact and snchain.survey = supportneed.survey
          )
        )
        LIMIT 1
      ";
      $sth = $anniedb->getDbh()->prepare($sql);
      $sth->bindParam(':auth_uid', $auth_uid);
      if ($key) {
        $sth->bindParam(':supportneed', $key);
      }
      $sth->execute();
      if ($sth->rowCount() <= 0) {
        http_response_code(401);
        echo json_encode(array("status"=>"UNAUTHORIZED"));
        exit;
      }
      $ret = $anniedb->selectSupportneedHistory($key);
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
    // checking here for existing supportneed values is kinda silly
    // since we might be inserting the first one now but as it is
    // supportneeds are created with engine and not via user with API...
    $sql = "
      SELECT 1
      FROM $dbschm.supportneed
      WHERE (1=0
        or (:auth_uid) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_uid,survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
        or (:auth_uid,survey,category) in (select annieuser,survey,category from $dbschm.usageright_provider)
        or (
          (:auth_uid,contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
          and (survey,category) NOT in (select survey,category from $dbschm.usageright_provider)
        )
      )
      LIMIT 1
    ";
    $sth = $anniedb->getDbh()->prepare($sql);
    $sth->execute(array(':auth_uid' => $auth_uid));
    if ($sth->rowCount() <= 0) {
      http_response_code(401);
      echo json_encode(array("status"=>"UNAUTHORIZED"));
      exit;
    }
    if ($input) {
      // pass auth_uid (auth_user) for checking access in lib also
      $ret = $anniedb->insertSupportneed($input,$auth_uid);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK", "id"=>$ret));
      } else {
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED"));
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"input missing"));
      exit;
    }
    break;
  case 'PUT':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>