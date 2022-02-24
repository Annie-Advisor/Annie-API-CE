<?php
/* sendsms.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and the next place.
 * With authentication check first.
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*,quriiri*
require_once 'my_app_specific_library_dir/auth.php';

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
/* + db for specialized query */
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
/* - db */

$quriiriapiuri = $settings['quriiri']['apiuri'];
$quriiriapikey = $settings['quriiri']['apikey'];
if ( !( isset($quriiriapiuri) && isset($quriiriapikey) ) ) {
  die("Failed with settings");
}

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, POST';//GET, PUT, DELETE
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

// no parameters from URI

$input = file_get_contents('php://input');
// assume application/json
// not quite sure this is needed but has something to do with unicode chars
$input = json_decode(json_encode(json_decode($input), JSON_UNESCAPED_UNICODE));
// result is JSON

//TODO: check method, key and input!

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

require_once 'my_app_specific_quriiri_dir/sender.php';
$quriiri = new Quriiri_API\Sender($quriiriapikey,$quriiriapiuri);

$areyouokay = true;

// store message, nb! input should have everything needed for db table message
$messageid = $anniedb->insertMessage($input);
if ($messageid === FALSE) {
  error_log("FAILED: SendSMS: DB: insertMessage with input=".json_encode($input));
  $areyouokay = false;
}

if ($areyouokay) {
  $sql = "select value from $dbschm.config where segment='sms' and field='validity'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $smsvalidity = isset($res->value) ? $res->value : 1440;//default 24h
  // sendSMS
  // convert destination to array
  $res = $quriiri->sendSms(null, array($destination), $message, array("batchid"=>$messageid, "validity"=>$smsvalidity));
  //error_log("DEBUG: SendSMS: " . var_export($res,true));
  foreach($res["errors"] as $error) {
    error_log("ERROR: SendSMS: " . $error["message"]);
    $areyouokay = false;
  }
  foreach($res["warnings"] as $warning) {
    error_log("WARNING: SendSMS: " . $warning["message"]);
    $areyouokay = false;
  }
  // update message.status via response
  if ($areyouokay) {
    if ($res["messages"][$destination]) {
      $data = $res["messages"][$destination];
      if ($data["status"]) {
        //error_log("DEBUG: SendSMS: status=" . $data["status"] . " for " . $messageid);
        $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
          //"updated"=>[not null],
          "updatedby"=>"SendSMS",
          "status"=>$data["status"]
        ))));
      }
    }
  }
}

if ($areyouokay) {
  http_response_code(200); // OK
  echo json_encode(array("status"=>"OK"));
} else {
  http_response_code(400); // Bad Request
  echo json_encode(array("status"=>"FAILED"));
}
?>