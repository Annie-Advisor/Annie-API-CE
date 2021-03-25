<?php
/* sendsms.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and the next place.
 * With authentication check first.
 */

require_once('settings.php');//->settings,db*,sms*
require_once('auth.php');

require_once('anniedb.php');
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

$smsapiuri = $settings['sms']['apiuri'];
$smsapikey = $settings['sms']['apikey'];
if ( !( isset($smsapiuri) && isset($smsapikey) ) ) {
  die("Failed with settings");
}

require 'http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, POST';//, PUT, DELETE
//$headers[]='Access-Control-Allow-Origin: *';
$headers[]='Access-Control-Allow-Credentials: true';
//$headers[]='Access-Control-Max-Age: 1728000';
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

// parameters from URI
// not really needed if contact key is in input data
$request = array();
if (isset($_SERVER['PATH_INFO'])) {
  $request = explode('/', trim($_SERVER['PATH_INFO'],'/'));
}

$key = null;
if (count($request)>=1) {
  $key = array_shift($request);
}

$input = file_get_contents('php://input');
// assume application/json
// not quite sure this is needed but has something to do with unicode chars
$input = json_decode(json_encode(json_decode($input), JSON_UNESCAPED_UNICODE));
// result is JSON

// resolve vars from input
$destination = null;
$message = null;
if ($input && array_key_exists('to', $input)) {
  $destination = $input->{'to'};
}
if ($input && array_key_exists('body', $input)) {
  $message = $input->{'body'};
}
if ( !( isset($destination) && isset($message) ) ) {
  http_response_code(400); // bad request
  die("Couldn't resolve required data from input");
}

require_once('/opt/sms_api/sender.php');
$sms = new SMS_API\Sender($smsapikey,$smsapiuri);

$areyouokay = true;

// store message, nb! input should have everything needed for db table message
$messageid = $anniedb->insertMessage($key,$input);
if ($messageid === FALSE) {
  error_log("FAILED: SendSMS: DB: insertMessage with key=".$key." input=".json_encode($input));
  $areyouokay = false;
}

// sendSMS
if ($areyouokay) {
  // convert destination to array
  $res = $sms->sendSms(null, array($destination), $message, array("batchid"=>$messageid));
  foreach($res["errors"] as $error) {
    error_log("DEBUG: SendSMS: ERROR: " . $error["message"]);
    $areyouokay = false;
  }
  foreach($res["warnings"] as $warning) {
    error_log("DEBUG: SendSMS: WARNING: " . $warning["message"]);
    $areyouokay = false;
  }
  //ok but may be FAILED cases
}
// update message.status via response
if ($areyouokay) {
  if ($res["messages"][$destination]) {
    $data = $res["messages"][$destination];
    if ($data["status"]) {
      //if (array_key_exists("reason", $data))
          //echo ", reason " . $data["reason"];
      $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
        "updated"=>null,
        "updatedby"=>"SendSMS",
        "status"=>$data["status"]
      ))));
    }
  }
}

if ($areyouokay) {
  echo json_encode(array("status" => "OK"));
  http_response_code(200); // OK
} else {
  echo json_encode(array("status" => "FAILED"));
  //http code?
}
?>