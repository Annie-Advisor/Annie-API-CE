<?php
/* resend.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to do a special task.
 */

require_once('/opt/annie/settings.php');//->settings,db*
require_once('/opt/annie/auth.php');

require '/opt/annie/http_response_code.php';

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

// see HTTP method and input
$areyouokay = false; // assume world to be bad
switch ($method) {
  case 'GET':
  case 'PUT':
  case 'DELETE':
    http_response_code(405); // Method Not Allowed
    exit;
    break;
  case 'POST':
    if ($key && $input) {
      // only way we allow script to continue
      $areyouokay = true;
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"Missing either parameter or input."));
      exit;
    }
    break;
}

if (!$areyouokay) {
  exit;
}

require_once('/opt/annie/anniedb.php');
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
/* + db for specialized query */
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
/* - db */

$survey = $key;
$contacts = $input;

$smsapiuri = $settings['quriiri']['apiuri'];
$smsapikey = $settings['quriiri']['apikey'];

require_once('/opt/quriiri_api/sender.php');
$smsprovider = new Quriiri_API\Sender($smsapikey,$smsapiuri);

//
// FUNCTIONS
//

$cipher = "aes-256-cbc";
function encrypt($string,$iv) {
  global $cipher, $salt;
  $output = false;
  if (in_array($cipher, openssl_get_cipher_methods())) {
      $output = openssl_encrypt($string, $cipher, $salt, $options=0, $iv);
  }
  return $output;
}

function decrypt($string,$iv) {
  global $cipher, $salt;
  $output = false;
  if (in_array($cipher, openssl_get_cipher_methods())) {
      $output = openssl_decrypt($string, $cipher, $salt, $options=0, $iv);
  }
  return $output;
}

//
// PROCESS
//

// initiate a survey (id=key) for contacts found in input

$destinations = array();
foreach ($contacts as $contact) {
  //error_log("Resend: ".$survey." FOR ".$contact);
  $sql = "
  select contact as contactdata
  , iv
  from $dbschm.contact
  where id = :contact
  ";
  $sth = $dbh->prepare($sql);
  $sth->bindParam(':contact', $contact);
  $sth->execute();
  $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $rownum => $row) {
    $iv = base64_decode($row['iv']);
    $cd = json_decode(decrypt($row['contactdata'],$iv));
    array_push($destinations,$cd->{'phonenumber'});
  }
}

//error_log("DEBUG: Resend: config: survey: ".$survey);
if ($areyouokay) {
  $surveyconfigs = json_decode(json_encode($anniedb->selectSurveyConfig($survey)));
}
$messagetemplate = null;
// loop surveyconfig, phases
if (count($surveyconfigs)>0) {
  foreach ($surveyconfigs[0] as $jk => $jv) {//nb! should be only one!
    if ("config" == $jk) { //must have
      $flow = json_decode($jv);
      // root level
      //A, B, C...
      if (array_key_exists("message", $flow)) {
        $messagetemplate = $flow->{'message'};
      }
    }
    // sanity check
    // "endtime is in future"
    if ("endtime" == $jk) {
      if ($jv < date("Y-m-d H:i")) { // we've passed end time
        error_log("FAILED: Resend: endtime is NOT in future with survey=".$survey);
        http_response_code(400);
        $areyouokay = false;
      }
    }
    // status = IN PROGRESS
    if ("status" == $jk) {
      if ($jv != "IN PROGRESS") { // status is not in progress
        error_log("FAILED: Resend: status is NOT IN PROGRESS with survey=".$survey);
        http_response_code(400);
        $areyouokay = false;
      }
    }
  }
} else {
  error_log("FAILED: Resend: config: could not find surveyconfig with: survey=".$survey);
  http_response_code(400);
  $areyouokay = false;
}
//error_log("DEBUG: Resend: config: message: ".$messagetemplate);

// actions
if ($areyouokay && $messagetemplate) {
  foreach ($destinations as $destination) {
    $message = $messagetemplate;//copy template for replacements!
    //error_log("DEBUG: Resend: message to: ".$destination);
    $contactid = null;
    $contactids = json_decode(json_encode($anniedb->selectContactId($destination)));
    if (count($contactids)>0) {
      $contactid = $contactids[0]->{'id'};
      $areyouokay = true;
      //error_log("Resend: start action for: ".$destination);
    } else {
      $areyouokay = false;
      error_log("FAILED: Resend: action: FAILED to get contactid for: ".$destination);
    }

    if ($areyouokay) { //or contactid
      $areyouokay = $anniedb->insertContactsurvey($contactid,json_decode(json_encode(array(
        "updatedby"=>"Resend", //not important
        "survey"=>$survey,
        "status"=>"1"
      ))));
      //todo test $areyouokay

      // make message personalized
      // replace string placeholders, like "{{ firstname }}"
      $contacts = json_decode(json_encode($anniedb->selectContact($contactid)));
      $contact = $contacts[0]->{'contact'};

      $replaceables = preg_split('/[^{]*(\{\{[^}]+\}\})[^{]*/', $message, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
      $personalized = $message;
      if (gettype($replaceables) === "array" && $replaceables[0] !== $message) {
        foreach ($replaceables as $replaceable) {
          $replacekey = trim(strtolower(preg_replace('/\{\{\s*([^}]+)\s*\}\}/', '$1', $replaceable)));
          if (array_key_exists($replacekey, $contact)) {
            $personalized = str_replace($replaceable, $contact->{$replacekey}, $personalized);
          }
        }
      }
      $message = $personalized;
      //error_log("DEBUG: Resend: message personalized: ".$message." for: ".$destination);

      // store message
      $messageid = $anniedb->insertMessage($contactid,json_decode(json_encode(array(
        "createdby"=>"Resend",
        "updatedby"=>"Resend",
        "body"=>$message,
        "sender"=>"Annie",
        "survey"=>$survey
      ))));
      if ($messageid === FALSE) {
        $areyouokay = false;
      }
      //todo test $areyouokay
      // sendSMS, one at a time due to personalized message
      if ($areyouokay) {
        // convert destination to array
        $res = $smsprovider->sendSms(null, array($destination), $message, array("batchid"=>$messageid));
        foreach($res["errors"] as $error) {
          error_log("ERROR: Resend: smsprovider: " . $error["message"]);
          $areyouokay = false;
        }
        foreach($res["warnings"] as $warning) {
          error_log("WARNING: Resend: smsprovider: " . $warning["message"]);
          $areyouokay = false;
        }
        //ok but may be FAILED cases
        // update message.status via response
        if ($areyouokay) {
          //error_log("DEBUG: Resend: Smsprovider: messageid=$messageid " . $res["messages"][$destination]["status"]);
          if ($res["messages"][$destination]) {
            $data = $res["messages"][$destination];
            if ($data["status"]) {
              $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
                "updatedby"=>"Resend",
                "status"=>$data["status"]
              ))));
              //todo test $areyouokay
              // do supportneed on immediate fail
              if ($data["status"] == "FAILED") {
                $areyouokay = $anniedb->insertSupportneed($contactid,json_decode(json_encode(array(
                  "updatedby"=>"Annie", // UI shows this
                  "category"=>"W", // message not delivered
                  "survey"=>$survey
                ))));
                //todo test $areyouokay
                // end the survey for this contact (rule: whenever supportneed...)
                $areyouokay = $anniedb->insertContactsurvey($contactid,json_decode(json_encode(array(
                  "updatedby"=>"Resend",
                  "survey"=>$survey,
                  "status"=>'100'
                ))));
                //todo test $areyouokay
              }
            }
          }
          //todo: test for areyouokay?
        }
      }
    }
  }
}

?>