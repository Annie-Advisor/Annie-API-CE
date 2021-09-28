<?php
/* delivery.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as delivery report receiver for SMS flow.
 *
 * NB! Authorization done via lower level IP restriction!
 */

require_once '/opt/annie/settings.php';//->settings,db*
//no auth, ip restriction

require_once '/opt/annie/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

require '/opt/annie/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, POST';// PUT, DELETE
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
  $input = json_decode($input);
}
// go based on HTTP method
$areyouokay = true; // status of process here
switch ($method) {
  case 'PUT':
    http_response_code(400); // bad request
    exit;
    break;
  case 'DELETE':
    http_response_code(400); // bad request
    exit;
    break;
  case 'GET':
    //nothing to do?
    http_response_code(200);
    exit;
    break;
  case 'POST':
    if ($input) {
      //error_log("DEBUG: Delivery: input: ".json_encode($input));
      /* Quriiri example:
      {
        "sender":"+358450000001",
        "destination":"+358500000002",
        "status":"DELIVERED",
        "statustime":"2020-10-06T09:24:15Z",
        "smscount":"1",
        "batchid":"6"
      }
      */

      // variables
      $sender = null; // phonenumber
      $destination = null; // phonenumber
      $status = null;
      $statustime = null;
      $batchid = null;
      if (array_key_exists('sender', $input)) {
        $sender = $input->{'sender'};
      }
      if (array_key_exists('destination', $input)) {
        $destination = $input->{'destination'};
      }
      if (array_key_exists('status', $input)) {
        $status = $input->{'status'};
      }
      if (array_key_exists('statustime', $input)) {
        $statustime = $input->{'statustime'};
      }
      if (array_key_exists('batchid', $input)) {
        $batchid = $input->{'batchid'};
      }

      // test for mandatories
      if (!$sender || !$destination || !$status || !$batchid) {
        http_response_code(200); // OK
        // but no need to continue
        exit;
      }

      //nb! quriiri numbers are somehow scuffed "+358..." -> " 358..."
      $destination = trim($destination);
      $sender = trim($sender);

      if (!preg_match('/^[+].*/', $destination)) {
        $destination = "+".$destination;
      }
      if (!preg_match('/^[+].*/', $sender)) {
        $sender = "+".$sender;
      }

      //error_log("DEBUG: Delivery: (edit)destination: ".$destination);
      //error_log("DEBUG: Delivery: (edit)sender: ".$sender);

      // do your thing....

      // direction of message (which is sender and which destination)
      // - search for destination from contacts
      // - then for sender from contacts
      $annienumber = "";
      $contactnumber = "";

      // figure out contactid from destination/sender number
      //echo json_encode(array($destination));
      $contactid = null;
      $contactids = json_decode(json_encode($anniedb->selectContactId($destination)));
      if (count($contactids)>0) {
        $contactnumber = $destination;
        $annienumber = $sender;
        $contactid = $contactids[0]->{'id'};
      } else {
        $contactids = json_decode(json_encode($anniedb->selectContactId($sender)));
        if (count($contactids)>0) {
          $contactnumber = $sender;
          $annienumber = $destination;
          $contactid = $contactids[0]->{'id'};
        } else {
          $areyouokay = false;
          //TODO: errors
          http_response_code(200); // OK
          // but no need to continue
          exit;
        }
      }

      //error_log("DEBUG: Delivery: contactid: ".$contactid);

      // figure out survey (here batchid) from contactnumber if not known from optional parameter through Quriiri
      if (!$batchid) {
        $contactsurveys = json_decode(json_encode($anniedb->selectLastContactsurvey($contactid)));
        $batchid = $contactsurveys[0]->{'survey'};
      }

      //
      // actions
      //

      // update status
      //error_log("DEBUG: Delivery: update: batchid: ".$batchid);
      $areyouokay = $anniedb->updateMessage($batchid,json_decode(json_encode(array(
        "updated"=>$statustime,
        "updatedby"=>"Delivery",
        "status"=>$status
      ))));

      // no output needed or expected
      http_response_code(200); // OK
    } else {
      http_response_code(400); // bad request
      exit;
    }
    break;
}

?>