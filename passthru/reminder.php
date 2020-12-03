<?php
/* reminder.php
 * Copyright (c) 2019,2020 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to handle SMS flow reminder part.
 *
 * NB! Authorization done via lower level IP restriction!
 */

require_once '../api/settings.php';//->settings,db*
//no auth, ip restriction

require_once '../api/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass);

$smsapiuri = $settings['sms']['apiuri'];
$smsapikey = $settings['sms']['apikey'];

require_once '/opt/sms_api/sender.php';
$sms = new SMS_API\Sender($smsapikey,$smsapiuri);

require '../api/http_response_code.php';

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
  $input = json_decode(json_encode(json_decode($input), JSON_UNESCAPED_UNICODE));
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    http_response_code(200); // ok, but no action
    exit;
    break;
  case 'PUT':
    http_response_code(400); // bad request
    exit;
    break;
  case 'POST':
    if ($input) {
      //error_log("DEBUG: Reminder: input: ".json_encode($input));

      //
      // BEGIN FLOW
      //
      
      /* Expected input:
      {
        "sender": "+358500000009",
        "survey": "1",
        "destinations": ["+358400000001","+358400000002","+358400000003"]
      }
      */

      // steps: (NB! From which only ".reminder" is handled here)

      // 1. "Initiated"
      //  -> [db]contactsurvey
      //  -> sendSMS(+db) .message
      //THIS ONE:
      //  if .reminder:
      //    "no reply in time" (first or Nth time?)
      //    -> sendSMS(+db) .reminder
      //    -> wait .response[?]                                            ->> 2.
      //    "no reply in time Nth time"
      //    -> (db)supportneed                                              ->> 3.
      //  else:
      //    -> wait .response[?]                                            ->> 2.
      // 2. "Replied" (repeated)
      // for example "A"
      //  -> (db)message
      //  "Choice ?"
      //    substring(".response[?]",len("response")) == ",?,"
      //  "hit"
      //  -> sendSMS(+db) .message
      //  if .response[?].supportneed:
      //    -> (db)supportneed                                              ->> 3.
      //  else:
      //    -> wait .response[?]                                            ->> 2.
      //  "no hit/else"
      //  -> sendSMS(+db) ..other.message
      //  if .other.supportneed:
      //    -> (db)supportneed                                              ->> 3.
      //  else:
      //    -> wait .response[?]                                            ->> 2.
      // 3. "Final"
      //  -> (db)contactsurvey                                              ->|

      // variables
      $areyouokay = true;
      $sender = ""; //phonenumber, optional
      $survey = ""; //id, mandatory
      $destinations = array(); //array of phonenumbers
      if (array_key_exists('sender', $input)) {
        $sender = $input->{'sender'};
      }
      if (array_key_exists('survey', $input)) {
        $survey = $input->{'survey'};
      } else {
        $areyouokay = false;
      }
      if (array_key_exists('destinations', $input)) {
        $destinations = $input->{'destinations'};
      }

      //error_log("DEBUG: Reminder: config: survey: ".$survey);
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
            if (array_key_exists("reminder", $flow)) {
              $reminder = $flow->{'reminder'};
              if (array_key_exists("message", $reminder)) {
                $messagetemplate = $reminder->{'message'};
                break 1; //stop all
              }
            }
          }
        }
      } else {
        error_log("FAILED: Reminder: config: could not find surveyconfig with: survey=".$survey);
        $areyouokay = false;
      }
      //error_log("DEBUG: Reminder: config: message: ".$messagetemplate);

      // actions
      if ($areyouokay && $messagetemplate) {
        // ".reminder"
        foreach ($destinations as $destination) {
          $message = $messagetemplate;//copy template for replacements!
          //error_log("DEBUG: Reminder: message to: ".$destination);
          $key = null;
          $contactkeys = json_decode(json_encode($anniedb->selectContactKey($destination)));
          if (count($contactkeys)>0) {
            $key = $contactkeys[0]->{'key'};
            $areyouokay = true;
          } else {
            $areyouokay = false;
            error_log("FAILED: Reminder: action: FAILED to get key for: ".$destination);
          }

          if ($areyouokay) { //or key
            $areyouokay = $anniedb->insertContactsurvey($key,json_decode(json_encode(array(
              //default: "updated"=>null,
              "updatedby"=>"Reminder", //not important
              "survey"=>$survey,
              "status"=>"2"
            ))));
            //todo test $areyouokay

            // make message personalized
            // replace string placeholders, like "{{ firstname }}"
            $contacts = json_decode(json_encode($anniedb->selectContact($key)));
            $contact = $contacts[0]->{'contact'};
            
            $replaceables=preg_split('/[^{]*(\{\{ [^}]+ \}\})[^{]*/', $message, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
            $personalized=$message;
            foreach ($replaceables as $replacekey) {
              $ck = trim(str_replace("{{ ","",str_replace(" }}", "", $replacekey)));
              if (array_key_exists($ck, $contact)) {
                $personalized=str_replace($replacekey, $contact->{$ck}, $personalized);
              }
            }
            $message=$personalized;
            //error_log("DEBUG: Reminder: message personalized: ".$message." for: ".$destination);
            //if (array_key_exists("firstname", $contact)) {
            //  $message = str_replace("{{ firstname }}", $contact->{'firstname'}, $message);
            //  error_log("DEBUG: Reminder: firstname: ".$contact->{'firstname'}." for: ".$destination);
            //}

            // store message
            $messageid = $anniedb->insertMessage($key,json_decode(json_encode(array(
              //id is generated: "id"=>?,
              //default: "created"=>null,
              "createdby"=>"Reminder",
              //default: "updated"=>null,
              "updatedby"=>"Reminder",
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
              $res = $sms->sendSms(null, array($destination), $message, array("batchid"=>$messageid));
              foreach($res["errors"] as $error) {
                error_log("ERROR: Reminder: " . $error["message"]);
                $areyouokay = false;
              }
              foreach($res["warnings"] as $warning) {
                error_log("WARNING: Reminder: " . $warning["message"]);
                $areyouokay = false;
              }
              //ok but may be FAILED cases
              // update message.status via response
              if ($areyouokay) {
                //error_log("DEBUG: Reminder: messageid=$messageid " . $res["messages"][$destination]["status"]);
                if ($res["messages"][$destination]) {
                  $data = $res["messages"][$destination];
                  if ($data["status"]) {
                    //if (array_key_exists("reason", $data))
                        //echo ", reason " . $data["reason"];
                    $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
                      //default: "updated"=>null,
                      "updatedby"=>"Reminder",
                      "status"=>$data["status"]
                    ))));
                    //todo test $areyouokay
                    // do supportneed on immediate fail
                    if ($data["status"] == "FAILED") {
                      $areyouokay = $anniedb->insertSupportneed($key,json_decode(json_encode(array(
                        //default: "updated"=>null,
                        "updatedby"=>"Annie", // UI shows this
                        //not needed: "contact"=>$key,
                        "category"=>"W", // message not delivered
                        //not needed: "status"=>1,
                        "survey"=>$survey
                        //? "userrole"=>?
                      ))));
                      //todo test $areyouokay
                      // end the survey for this contact (rule: whenever supportneed...)
                      $areyouokay = $anniedb->insertContactsurvey($key,json_decode(json_encode(array(
                        //default: "updated"=>null,
                        "updatedby"=>"Reminder",
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

      // no output needed or expected
      http_response_code(200); // OK
    } else {
      http_response_code(400); // bad request
      exit;
    }
    break;
  case 'DELETE':
    http_response_code(400); // bad request
    exit;
    break;
}

?>