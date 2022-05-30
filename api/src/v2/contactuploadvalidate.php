<?php
/* contactuploadvalidate.php
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

// selectContactData
// a copy of anniedb->selectContactId to return them ALL!
// for finding contact via phone number in massive amounts faster
function selectContactData() {
  global $dbschm, $dbh;
  $sql = "SELECT id, contact, iv FROM $dbschm.contact";
  // excecute SQL statement
  $sth = $dbh->prepare($sql);
  $sth->execute();
  // for return
  $ret = array();
  $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $rownum => $row) {
    $iv = null;
    if (array_key_exists('iv', $row)) {
      $iv = base64_decode($row['iv']);
    }
    if (array_key_exists('contact', $row)) {
      $dec_contact = json_decode(decrypt($row['contact'],$iv));
      $dataphonenumber = $dec_contact->{'phonenumber'};
      // for searching/accessing via phonenumber:
      $ret[$dataphonenumber] = array(
        "id" => $row["id"],
        "contact" => $dec_contact,
        "iv" => $row["iv"]
      );
    }
  }
  return $ret;
}
$dbcontactdata = selectContactData();

// selectConfigCountryCode
// just fetch sms.countryCode from config
function selectConfigCountryCode() {
  global $dbschm, $dbh;
  $sql = "SELECT value FROM $dbschm.config WHERE segment='sms' AND field='countryCode'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  return $sth->fetchAll(PDO::FETCH_ASSOC)[0]['value'];
}
$countrycode = selectConfigCountryCode();

// annieuserExists
// does given id exist in annieuser
// for checking given data integrity
function annieuserExists($teacheruid) {
  global $dbschm, $dbh;
  $ret = false;
  $sql = "SELECT count(*) as found FROM $dbschm.annieuser WHERE id=:annieuser and coalesce(validuntil,'9999-09-09') > now()";
  $sth = $dbh->prepare($sql);
  $sth->bindParam(':annieuser', $teacheruid);
  $sth->execute();
  if ($sth->fetchAll(PDO::FETCH_ASSOC)[0]['found'] == 0) {
    $ret = true;
  }
  return $ret;
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
        //no need to error_log("ERROR: contactuploadvalidate: only XLSX files are allowed.");
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
          error_log("ERROR: contactuploadvalidate: there was an error uploading file.");
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
    $contactdata = null; // json object (see below)
    if ($uploadOk && $target_file) {
      $step = 2;
      // execute python script which is so much better in handling excel files
      // data of interest will be in $contactexceldata
      exec(
        '/usr/bin/python3 '.$library_dir.'contactxl.py --quiet --source='.escapeshellarg($target_file).' --countrycode='.$countrycode
        ,$contactexceldata
      );
      // get rid of root array since there is only one object (with array inside)
      // make php object to access data easier
      $contactdata = json_decode($contactexceldata[0]);
    }

    // output result json object
    $result = (object)array(
      "status" => "N/A",
      "meta" => (object)array(
        "update" => 0,
        "insert" => 0,
        "errors" => 0
      ),
      "contacts" => array(),
      "errors" => array()
    );
    if ($contactdata && array_key_exists('data', $contactdata)) {
      // make phonenumber indexed object (duplicate check)
      $duplicatephonenumbers = (object)array();
      foreach ($contactdata->data as $d) {
        $newcontact = (object)array(
          "data" => $d->contact,
          "insert" => null
        );

        // error checking
        $goestoerror = false;
        // check for phonenumber
        if (!array_key_exists('phonenumber', $d->contact) || empty($d->contact->phonenumber)) {
          $goestoerror = true;
          $newcontact->error = (array_key_exists('error', $newcontact) ? $newcontact->error."; " : "");
          $newcontact->error.= "no phonenumber";
          unset($newcontact->insert);
        }
        // check for duplicate phone numbers within data
        if (!$goestoerror) {
          if (array_key_exists($d->contact->phonenumber, $duplicatephonenumbers)) {
            // duplicate phonenumber
            $goestoerror = true;
            $newcontact->error = (array_key_exists('error', $newcontact) ? $newcontact->error."; " : "");
            $newcontact->error.= "duplicate phone number";
            unset($newcontact->insert);
          } else {
            $duplicatephonenumbers->{$d->contact->phonenumber} = $d->id;
          }
        }
        // check for firstname
        if (!array_key_exists('firstname', $d->contact) || empty($d->contact->firstname)) {
          $goestoerror = true;
          $newcontact->error = (array_key_exists('error', $newcontact) ? $newcontact->error."; " : "");
          $newcontact->error.= "no firstname";
          unset($newcontact->insert);
        }
        // check for lastname
        if (!array_key_exists('lastname', $d->contact) || empty($d->contact->lastname)) {
          $goestoerror = true;
          $newcontact->error = (array_key_exists('error', $newcontact) ? $newcontact->error."; " : "");
          $newcontact->error.= "no lastname";
          unset($newcontact->insert);
        }
        // check for teacheruid (exists and points to a valid one)
        if (!array_key_exists('teacheruid', $d->contact) || empty($d->contact->teacheruid)) {
          $goestoerror = true;
          $newcontact->error = (array_key_exists('error', $newcontact) ? $newcontact->error."; " : "");
          $newcontact->error.= "no teacheruid";
          unset($newcontact->insert);
        } else {
          if (annieuserExists($d->contact->teacheruid)) {
            $goestoerror = true;
            $newcontact->error = (array_key_exists('error', $newcontact) ? $newcontact->error."; " : "");
            $newcontact->error.= "teacheruid refers to an unknown";
            unset($newcontact->insert);
          }
        }
        // - error checking

        // where to:
        if ($goestoerror == true) {
          array_push($result->errors,$newcontact);
          $result->meta->errors++;
        } else {
          if (array_key_exists($d->contact->phonenumber, $dbcontactdata)) {
            $newcontact->insert = false;
            $result->meta->update++;
            $newcontact->data->{'id'} = $dbcontactdata[$d->contact->phonenumber]['id'];
          } else {
            $newcontact->insert = true;
            $result->meta->insert++;
          }
          array_push($result->contacts,$newcontact);
        }
      }
      $result->status = "OK";
      if (count($result->errors) > 0) {
        if (count($result->contacts) == 0) {
          $result->status = "FAILED";
        } else {
          $result->status .= "-ish";
        }
      }
      http_response_code(200);
    } else {
      http_response_code(400);
      error_log("ERROR: contactuploadvalidate: Could not read data from file.");
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