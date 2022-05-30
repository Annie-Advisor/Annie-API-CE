<?php
/* contactuploadsave.php
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
//$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
$dbh = null;
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}

//
//
//

// update, or if doesnt exist insert, a single contact row
function upsertContact($contact) {
  global $cipher, $dbschm, $dbh, $auth_uid;
  if (!$contact) return false;

  if (!array_key_exists('phonenumber', $contact) || empty($contact->phonenumber)) {
    return (object)array("m"=>"ERROR: no phonenumber");
  }
  if (!array_key_exists('firstname', $contact) || empty($contact->firstname)) {
    return (object)array("m"=>"ERROR: no firstname");
  }
  if (!array_key_exists('lastname', $contact) || empty($contact->lastname)) {
    return (object)array("m"=>"ERROR: no lastname");
  }
  if (!array_key_exists('teacheruid', $contact) || empty($contact->teacheruid)) {
    return (object)array("m"=>"ERROR: no teacheruid");
  }

  $updatedby = $auth_uid;
  $annieuser = $contact->teacheruid;

  $ivlen = openssl_cipher_iv_length($cipher);
  $iv = openssl_random_pseudo_bytes($ivlen);
  $enc_contact = encrypt(json_encode($contact),$iv);
  $enc_iv = base64_encode($iv);

  $id = null;
  if (array_key_exists('id',$contact)) {
    $id = $contact->id;
  } else {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = 'CO';//well, Annie you know
    for ($i = 0; $i < 10; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    $id = $randomString;
  }
  $sql = "
    UPDATE $dbschm.contact
    SET contact=:contact, iv=:iv, updated=now(), updatedby=:updatedby
    , annieuser=:annieuser
    WHERE id=:id
  ";
  $sth = $dbh->prepare($sql);
  $sth->bindParam(':contact', $enc_contact);
  $sth->bindParam(':iv', $enc_iv);
  $sth->bindParam(':updatedby', $updatedby);
  $sth->bindParam(':annieuser', $annieuser);
  $sth->bindParam(':id', $id);
  if ($sth->execute() === false) return false;
  // if update "fails" (no rows found), insert
  if ($sth->rowCount() === 0) {
    $sql = "
      INSERT INTO $dbschm.contact (id,contact,iv,updatedby,annieuser)
      VALUES (:id,:contact,:iv,:updatedby,:annieuser)
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':id', $id);
    $sth->bindParam(':contact', $enc_contact);
    $sth->bindParam(':iv', $enc_iv);
    $sth->bindParam(':updatedby', $updatedby);
    $sth->bindParam(':annieuser', $annieuser);
    if ($sth->execute() === false) return false;
    error_log("INFO: cupload: INSERT id=$id by auth_uid=$auth_uid");
    return (object)array("m"=>"INSERT","id"=>$id);
  }
  error_log("INFO: cupload: UPDATE id=$id by auth_uid=$auth_uid");
  return (object)array("m"=>"UPDATE","id"=>$id);
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

    if ($input) {
      // data processing steps

      // step 3 of 3
      // save the data to database
      $dodata = $input;
      if (array_key_exists("contacts", $input)) {
        $dodata = $input->contacts;
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
        $action = upsertContact($d->data);
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