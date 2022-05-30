<?php
/* annieuseruploadsave.php
 * Copyright (c) 2022 Annie Advisor
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
$dbh = null;
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}

//
//
//

// update, or if doesnt exist insert, a single row
function upsertAnnieuser($id,$annieuser) {
  global $anniedb, $auth_uid;
  if (!$id) return false;

  if (!array_key_exists('createdby', $annieuser)) {
    $annieuser->{'createdby'} = $auth_uid;
  }
  if (!array_key_exists('updatedby', $annieuser)) {
    $annieuser->{'updatedby'} = $auth_uid;
  }

  $ret = $anniedb->updateAnnieuser($id,$annieuser);
  if ($ret === false) {
    $ret = $anniedb->insertAnnieuser($id,$annieuser);
    if ($ret !== false) {
      error_log("INFO: annieuseruploadsave: INSERT id=$id by auth_uid=$auth_uid");
      return (object)array("m"=>"INSERT","id"=>$id);
    }
  } else {
    error_log("INFO: annieuseruploadsave: UPDATE id=$id by auth_uid=$auth_uid");
    return (object)array("m"=>"UPDATE","id"=>$id);
  }
  return false;
}

//
//
//

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, POST';//GET, PUT, DELETE
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

// action based on HTTP method
switch ($method) {
  case 'POST':
    // access/usage right check (copied from annieuser.php)
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
      // data processing steps

      // step 3 of 3
      // save the data to database
      $dodata = $input;
      if (array_key_exists("annieusers", $input)) {
        $dodata = $input->annieusers;
      }
      $result = (object)array(
        "status" => "N/A",
        "meta" => (object)array(
          "insert" => 0,
          "update" => 0,
          "errors" => 0
        ),
        "insertids" => array(),
        "updateids" => array()
      );
      foreach ($dodata as $d) {
        $action = upsertAnnieuser($d->data->id,$d->data);
        if ($action) {
          if ($action->m == "INSERT") {
            $result->meta->insert++;
            array_push($result->insertids, $action->id);
          }
          if ($action->m == "UPDATE") {
            $result->meta->update++;
            array_push($result->updateids, $action->id);
          }
          if ($action->m != "INSERT" and $action->m != "UPDATE") {
            $result->meta->errors++;
            $result->{"message"} = $action->m;//might be overwritten
          }
        } else {
          $result->meta->errors++;
        }
      }
      http_response_code(200);
      if ($result->meta->errors == 0) {
        $result->status = "OK";
      } else {
        $result->status = "FAILED";
      }
      echo json_encode($result);

    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"input missing"));
    }

    break;
  case 'GET':
  case 'PUT':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

?>