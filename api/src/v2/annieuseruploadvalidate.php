<?php
/* annieuseruploadvalidate.php
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

//
//
//

// selectAnnieuserData
// fetch every annieuser in database
function selectAnnieuserData() {
  global $anniedb, $dbschm;
  $sql = "SELECT id, meta, iv, superuser, notifications, validuntil FROM $dbschm.annieuser";
  // excecute SQL statement
  $sth = $anniedb->getDbh()->prepare($sql);
  $sth->execute();
  // for return
  $ret = array();
  $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $rownum => $row) {
    $iv = null;
    if (array_key_exists('iv', $row)) {
      $iv = base64_decode($row['iv']);
    }
    if (array_key_exists('meta', $row)) {
      $dec_meta = json_decode(decrypt($row['meta'],$iv));
      // for searching/accessing via userid:
      $ret[$row["id"]] = array(
        "id" => $row["id"],
        "meta" => $dec_meta,
        //"iv" => $row["iv"],
        "superuser" => $row["superuser"],
        "notifications" => $row["notifications"],
        "validuntil" => $row["validuntil"]
      );
    }
  }
  return $ret;
}
$dbannieuserdata = selectAnnieuserData();

// selectConfigCountryCode
// just fetch sms.countryCode from config
function selectConfigCountryCode() {
  global $anniedb, $dbschm;
  $sql = "SELECT value FROM $dbschm.config WHERE segment='sms' AND field='countryCode'";
  $sth = $anniedb->getDbh()->prepare($sql);
  $sth->execute();
  return $sth->fetchAll(PDO::FETCH_ASSOC)[0]['value'];
}
$countrycode = selectConfigCountryCode();

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

    // data processing steps

    // step 1 of 3
    // check to see if a file upload should be done
    // - produces:
    $uploadOk = 0;
    $target_file = null;
    if ($_FILES) {
      $step = 1;
      $library_dir = "my_app_specific_library_dir/";
      $target_dir = $library_dir."uploads/";
      if ($_FILES["fileup"]["name"]) {
        $target_file = $target_dir . basename($_FILES["fileup"]["name"]);
      }

      $filename = basename($_FILES["fileup"]["name"]);
      $filetype = pathinfo($target_file,PATHINFO_EXTENSION);
      $filenoext = preg_replace("/[^a-z0-9_]+/i","",pathinfo($target_file,PATHINFO_FILENAME));

      $uploadOk = 1; // it is fine, at least for a moment
      // Allow certain file formats
      $allowed_types = Array("xlsx", "XLSX"); // case-sensitive, limited variations!
      if(!in_array($filetype, $allowed_types, true)) {
        //no need to error_log("ERROR: annieuseruploadvalidate: only XLSX files are allowed.");
        $uploadOk = 0;
        http_response_code(400);
        echo json_encode((object)array("status"=>"FAILED", "message"=>"File extension mismatch. Only xlsx or XLSX allowed."));
      }
      // if everything is ok, try to upload file
      if ($uploadOk == 1) {
        if (move_uploaded_file($_FILES["fileup"]["tmp_name"], $target_file)) {
          //ok, do nothing
          $uploadOk = $uploadOk;
        } else {
          error_log("ERROR: annieuseruploadvalidate: there was an error uploading file.");
          $uploadOk = 0;
          http_response_code(400);
          echo json_encode((object)array("status"=>"FAILED", "message"=>"File could not be uploaded. See server logs for more info."));
        }
      }
    }

    // step 2 of 3
    // read excel file to a json we like to use
    // - depends on $uploadOk and $target_file
    // - produces:
    $exceldata = null; // json object (see below)
    if ($uploadOk && $target_file) {
      $step = 2;
      // execute python script which is so much better in handling excel files
      // data of interest will be in $exceldata
      exec(
        '/usr/bin/python3 '.$library_dir.'annieuserxl.py --quiet --source='.escapeshellarg($target_file).' --countrycode='.$countrycode
        ,$rawexceldata
      );
      // get rid of root array since there is only one object (with array inside)
      // make php object to access data easier
      $exceldata = json_decode($rawexceldata[0]);
    }

    // output result json object
    $result = (object)array(
      "status" => "N/A",
      "meta" => (object)array(
        "update" => 0,
        "insert" => 0,
        "errors" => 0
      ),
      "annieusers" => array(),
      "errors" => array()
    );
    if ($exceldata && array_key_exists('rows', $exceldata)) {
      // make indexed objects (for duplicate check)
      $duplicatephonenumbers = (object)array();
      $duplicateuserids = (object)array();
      $duplicateemails = (object)array();
      foreach ($exceldata->rows as $d) {
        $newdata = (object)array(
          "data" => $d,
          "insert" => null
        );

        // error checking
        $goestoerror = false;
        // check for userid
        if (!array_key_exists('userid', $d->meta) || empty($d->meta->userid)) {
          $goestoerror = true;
          $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
          $newdata->error.= "no userid";
          unset($newdata->insert);
        }
        // check for email
        if (!array_key_exists('email', $d->meta) || empty($d->meta->email)) {
          $goestoerror = true;
          $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
          $newdata->error.= "no email";
          unset($newdata->insert);
        }
        // check for firstname
        if (!array_key_exists('firstname', $d->meta) || empty($d->meta->firstname)) {
          $goestoerror = true;
          $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
          $newdata->error.= "no firstname";
          unset($newdata->insert);
        }
        // check for lastname
        if (!array_key_exists('lastname', $d->meta) || empty($d->meta->lastname)) {
          $goestoerror = true;
          $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
          $newdata->error.= "no lastname";
          unset($newdata->insert);
        }
        // check for phonenumber
        //if (!array_key_exists('phonenumber', $d->meta) || empty($d->meta->phonenumber)) {
        //  $goestoerror = true;
        //  $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
        //  $newdata->error.= "no phonenumber";
        //  unset($newdata->insert);
        //}

        // duplicate data check. check key existance here separately
        // check for duplicate phonenumbers within data
        if (array_key_exists('phonenumber', $d->meta) && !empty($d->meta->phonenumber)) {
          if (array_key_exists($d->meta->phonenumber, $duplicatephonenumbers)) {
            $goestoerror = true;
            $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
            $newdata->error.= "duplicate phonenumber";
            unset($newdata->insert);
          } else {
            $duplicatephonenumbers->{$d->meta->phonenumber} = $d->id;
          }
        }
        // check for duplicate userids within data
        if (array_key_exists('userid', $d->meta) && !empty($d->meta->userid)) {
          if (array_key_exists($d->meta->userid, $duplicateuserids)) {
            $goestoerror = true;
            $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
            $newdata->error.= "duplicate userid";
            unset($newdata->insert);
          } else {
            $duplicateuserids->{$d->meta->userid} = $d->id;//umm. okay dude
          }
        }
        // check for duplicate emails within data
        if (array_key_exists('email', $d->meta) && !empty($d->meta->email)) {
          if (array_key_exists($d->meta->email, $duplicateemails)) {
            $goestoerror = true;
            $newdata->error = (array_key_exists('error', $newdata) ? $newdata->error."; " : "");
            $newdata->error.= "duplicate email";
            unset($newdata->insert);
          } else {
            $duplicateemails->{$d->meta->email} = $d->id;
          }
        }
        // - error checking

        // where to:
        if ($goestoerror == true) {
          array_push($result->errors,$newdata);
          $result->meta->errors++;
        } else {
          if (array_key_exists($d->meta->userid, $dbannieuserdata)) {
            $newdata->insert = false;
            $result->meta->update++;
            $newdata->data->{'id'} = $dbannieuserdata[$d->meta->userid]['id'];
          } else {
            $newdata->insert = true;
            $result->meta->insert++;
          }
          array_push($result->annieusers,$newdata);
        }
      }
      $result->status = "OK";
      if (count($result->errors) > 0) {
        if (count($result->annieusers) == 0) {
          $result->status = "FAILED";
        } else {
          $result->status .= "-ish";
        }
      }
      http_response_code(200);
    } else {
      http_response_code(400);
      error_log("ERROR: annieuseruploadvalidate: Could not read data from file.");
    }
    echo json_encode($result);

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