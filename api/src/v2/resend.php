<?php
/* resend.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to do a special task.
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/auth.php';

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

// see HTTP method and input
$areyouokay = false; // assume world to be bad
switch ($method) {
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
  case 'GET':
  case 'PUT':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

if (!$areyouokay) {
  exit;
}

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

$survey = $key;

$smsapiuri = $settings['quriiri']['apiuri'];
$smsapikey = $settings['quriiri']['apikey'];

require_once 'my_app_specific_quriiri_dir/sender.php';
$smsprovider = new Quriiri_API\Sender($smsapikey,$smsapiuri);

//
// PROCESS
//

// superuser action only
$sth = $anniedb->getDbh()->prepare("SELECT 1 FROM $dbschm.usageright_superuser WHERE annieuser=:auth_uid");
if (!$sth->execute(array(':auth_uid' => $auth_uid))) {
  error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
  http_response_code(500);
  exit;
}
if ($sth->rowCount() <= 0) {
  http_response_code(401); // Unauthorized
  exit;
}

// initiate a survey (id=key) for contacts found in input

$destinations = array();
foreach ($input as $contact) {
  //error_log("Resend: ".$survey." FOR ".$contact);
  $sql = "
  select contact as contactdata
  , iv
  from $dbschm.contact
  where id = :contact
  ";
  $sth = $anniedb->getDbh()->prepare($sql);
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
        $areyouokay = false;
        http_response_code(400);
        error_log("FAILED: Resend: endtime is NOT in future with survey=".$survey);
        exit;
      }
    }
    // status = IN PROGRESS
    if ("status" == $jk) {
      if ($jv != "IN PROGRESS") { // status is not in progress
        $areyouokay = false;
        http_response_code(400);
        error_log("FAILED: Resend: status is NOT IN PROGRESS with survey=".$survey);
        exit;
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
      $areyouokay = $anniedb->insertContactsurvey(json_decode(json_encode(array(
        "contact"=>$contactid,
        "survey"=>$survey,
        "status"=>"1",
        "updatedby"=>"Resend" //not important
      ))));
      if (!$areyouokay) {
        error_log("ERROR: Resend: insert contactsurvey(1) failed");
        $areyouokay = false;
      }

      // make message personalized
      // replace string placeholders, like "{{ firstname }}"
      $contacts = json_decode(json_encode($anniedb->selectContact($contactid,$auth_uid)));
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
      $messageid = $anniedb->insertMessage(json_decode(json_encode(array(
        "contact"=>$contactid,
        "createdby"=>"Resend",
        "updatedby"=>"Resend",
        "body"=>$message,
        "sender"=>"Annie",
        "survey"=>$survey,
        "context"=>"SURVEY"
      ))));
      if ($messageid === FALSE) {
        error_log("ERROR: Resend: insert message failed");
        $areyouokay = false;
      }
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
              if (!$areyouokay) {
                error_log("ERROR: Resend: updated message failed");
                $areyouokay = false;
              }
              // on immediate fail:
              if ($data["status"] == "FAILED") {
                // end the survey for this contact
                $areyouokay = $anniedb->insertContactsurvey(json_decode(json_encode(array(
                  "contact"=>$contactid,
                  "survey"=>$survey,
                  "status"=>"100",
                  "updatedby"=>"Resend"
                ))));
                if (!$areyouokay) {
                  error_log("ERROR: Resend: insert contactsurvey(2) failed");
                  $areyouokay = false;
                }
              }
            }
          }
        }
      }
    }
  }
}

// if we get this far
http_response_code(200);

?>