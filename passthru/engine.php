<?php
/* engine.php
 * Copyright (c) 2019,2020 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as engine for SMS flow.
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
    http_response_code(200); // ok, but no action
    echo '{"status":"Annie sad", "Advice":"Puny gettin\' without magic"}';
    exit;
    break;
  case 'POST':
    if ($input) {
      //
      // FLOW/ENGINE
      //
      /*
      Input comes from SMS provider.
      From there we get sender and destination numbers which will give us
      enough information to query db and get means to make decisions in
      which phase this current actor (student, destination number) is.
      Phase (or survey + supportneed.category) should give us sufficient
      information to decide on next action...
      */
      // input variables
      $sender = null; // phonenumber
      $sendertype = null;
      $destination = null; // phonenumber
      $text = null;
      $sendtime = null;
      $status = null;
      $statustime = null;
      if (array_key_exists('sender', $input)) {
        $sender = $input->{'sender'};
      }
      if (array_key_exists('sendertype', $input)) {
        $sendertype = $input->{'sendertype'};
      }
      if (array_key_exists('destination', $input)) {
        $destination = $input->{'destination'};
      }
      if (array_key_exists('text', $input)) {
        $text = $input->{'text'};
      }
      if (array_key_exists('sendtime', $input)) {
        $sendtime = $input->{'sendtime'};
      }
      //dev:
      else {
        $sendtime = date_format(date_create(),"Y-m-d\TH:i:s.v\Z");
      }
      if (array_key_exists('status', $input)) {
        $status = $input->{'status'};
      }
      if (array_key_exists('statustime', $input)) {
        $statustime = $input->{'statustime'};
      }

      //nb! sms numbers are somehow scuffed "+358..." -> " 358..."
      $destination = trim($destination);
      $sender = trim($sender);

      if (!preg_match('/^[+].*/', $destination)) {
        $destination = "+".$destination;
      }
      if (!preg_match('/^[+].*/', $sender)) {
        $sender = "+".$sender;
      }

      // steps: (nb! from which parts "2." and "3." actions are handled here)

      // 1. "Initiated"
      //  -> [db]contactsurvey
      //  -> sendSMS(+db) .message
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

      // actions
      // 2. "Replied"
      // PUSHed by SMS provider or polled by this engine (which call must be made by something)
      if (array_key_exists('sendertype', $input)) { //consider this as Provider PUSHed

        // direction of message (which is sender and which destination)
        // - search for destination from contacts
        // - then for sender from contacts
        $annienumber = "";
        $contactnumber = "";
        
        $key = null;

        $flow = null;
        $nextphase = null;
        $nextmessage = null;
        $currentphaseconfig = null; //for checking next nextphase
        
        $possiblephases = array();
        $dosupportneed = false;

        // figure out contact id (key) from destination/sender number
        $contactkeys = json_decode(json_encode($anniedb->selectContactKey($destination)));
        if (count($contactkeys)>0) {
          $contactnumber = $destination;
          $annienumber = $sender;
          $key = $contactkeys[0]->{'key'};
        } else {
          $contactkeys = json_decode(json_encode($anniedb->selectContactKey($sender)));
          if (count($contactkeys)>0) {
            $contactnumber = $sender;
            $annienumber = $destination;
            $key = $contactkeys[0]->{'key'};
          } else {
            $areyouokay = false;
            error_log("FAILED: Engine: action: FAILED to get key for: ".$destination);
          }
        }

        //error_log("DEBUG: Engine: key: ".$key);

        if ($areyouokay && $key)
        {
          $contacts = json_decode(json_encode($anniedb->selectContact($key)));
          $contact = $contacts[0]->{'contact'};

          // figure out survey from contact
          $contactsurveys = json_decode(json_encode($anniedb->selectContactLastContactsurvey($key)));
          // ...test if there is such info...
          // ...especially if there is not: (do supporneed Y)
          if (count($contactsurveys)==0 || (count($contactsurveys)==1 && $contactsurveys[0]->{'status'} == "100")) {
            $category = "Y"; // Student initiated
            $survey = "Y"; // Student initiated, used at the end also

            $surveyconfigs = json_decode(json_encode($anniedb->selectSurveyConfig($survey))); // just "Y"
            foreach ($surveyconfigs[0] as $jk => $jv) {//nb! should be only one!
              if ("config" == $jk) { //must have
                $flow = json_decode($jv);
                if (array_key_exists("other", $flow)) {
                  $fv = $flow->{"other"};
                  if (array_key_exists("message", $fv)) {
                    $nextmessage = $fv->{"message"};
                  }
                  if (array_key_exists("supportneed", $fv) && $fv->{'supportneed'}) {
                    $dosupportneed = true;
                  }
                }
              }
            }

            // nextphase is actually end (100) here since this is not an actual survey but situation is handled at the
            $nextphase = "Y";
            $possiblephases = array("Y");

          } else { // there is contactsurvey

            //todo: test contactsurvey.status here?

            $survey = $contactsurveys[0]->{'survey'};
            $surveyconfigs = json_decode(json_encode($anniedb->selectSurveyConfig($survey)));

            // ...and now we should know what we need
            // - contact, survey and message (text)

            // do the flow magic; figure out:
            // - phase we are at (currentphase)
            // - which phases are possible (possiblephases)
            // - what do we actually do (nextphase, dosupportneed)

            // can we proceed
            $currentphase = $contactsurveys[0]->{'status'};

            // loop surveyconfig, phases
            // - get possible phases for current status
            foreach ($surveyconfigs[0] as $jk => $jv) {//nb! should be only one!
              if ("config" == $jk) { //must have
                $flow = json_decode($jv);
                // root level
                //A, B, C...
                foreach ($flow as $fk => $fv) {
                  if (preg_match('/^response.*$/i', $fk)) {
                    $phasecandidate_ = substr($fk,strlen("response"));
                    if (in_array($currentphase,array("1","2"))) { // root phases
                      array_push($possiblephases,$phasecandidate_);
                    }
                    // next level
                    //A1, A2...
                    foreach ($fv as $ffk => $ffv) {
                      if (preg_match('/^response.*$/i', $ffk)) {
                        $phasecandidate__ = substr($ffk,strlen("response"));
                        if ($currentphase == $phasecandidate_) {// parent phase
                          array_push($possiblephases,$phasecandidate__);
                        }
                      } elseif ($ffk == "other") {
                        if ($currentphase == $phasecandidate_) {// parent phase
                          array_push($possiblephases,"$phasecandidate_.other");
                        }
                      }
                    }
                  } elseif ($fk == "other") {
                    if (in_array($currentphase,array("1","2"))) { // root phases
                      array_push($possiblephases,"other");
                    }
                  }
                }
              }
            }

            if ($text) {
              // loop phases again but with evaluating with message received
              foreach ($surveyconfigs[0] as $jk => $jv) {//nb! should be only one!
                if ("config" == $jk) { //must have
                  $flow = json_decode($jv);
                  // root level
                  //A, B, C...
                  foreach ($flow as $fk => $fv) {
                    if (strpos($fk,"response") !== false) {
                      $phasecandidate_ = substr($fk,strlen("response"));
                      $pattern = "/^$phasecandidate_.*/i";
                      //todo: check $possiblephases
                      if (in_array($currentphase, array("1","2"))) {
                        if (preg_match($pattern, $text)) { //nb! improve me!
                          $nextmessage = $fv->{'message'};
                          $nextphase = $phasecandidate_;
                          $currentphaseconfig = $fv;
                          if (array_key_exists("supportneed", $fv) && $fv->{'supportneed'}) {
                            $dosupportneed = true;
                          }
                          break 2;//stop all
                        }
                      } elseif ($currentphase == $phasecandidate_) {
                        // next level
                        //A1, A2...
                        foreach ($fv as $ffk => $ffv) {
                          if (strpos($ffk,"response") !== false) {
                            $phasecandidate__ = substr($ffk,strlen("response"));
                            $pattern = "/^$phasecandidate__.*/i";
                            //todo: check $possiblephases
                            if (preg_match($pattern, $text)) { //nb! improve me!
                              $nextmessage = $ffv->{'message'};
                              $nextphase = $phasecandidate__;
                              $currentphaseconfig = $ffv;
                              //todo: next level?
                              if ($ffv->{'supportneed'}) {
                                $dosupportneed = true;
                              }
                              break 3;//stop all
                            }
                          }
                        }//-loop fv
                        // no match above for subphase, but we are in currentphase
                        // so SUB.other if exists
                        if (!$nextphase) {
                          if (array_key_exists('other', $fv)) {
                            $ffv = $fv->{'other'};
                            if (gettype($ffv)=="object" && array_key_exists('message', $ffv)) {
                              $nextmessage = $ffv->{'message'};
                              $nextphase = "$phasecandidate_.other";
                              $currentphaseconfig = $fv;
                            }
                            if (array_key_exists('supportneed', $ffv) && $ffv->{'supportneed'}) {
                              $dosupportneed = true;
                            }
                            break 1;//stop this (flow)
                          }
                        }
                      }
                    }//-response
                  }//-loop flow
                  // no match above
                  if (!$nextphase) {
                    if (in_array($currentphase,array("1","2"))) { // root phases
                      if (gettype($flow)=="object" && array_key_exists("other", $flow)) {
                        $fv = $flow->{'other'};
                        if (gettype($fv)=="object" && array_key_exists('message', $fv)) {
                          $nextmessage = $fv->{'message'};
                          $nextphase = "other";
                          $currentphaseconfig = $flow;
                        }
                        if (array_key_exists('supportneed', $fv) && $fv->{'supportneed'}) {
                          $dosupportneed = true;
                        }
                      }
                      break 1;//stop all
                    }  //"else" phase with no next or other (typically A=everything OK) handled below
                  }
                }//-flow
              }//-loop surveyconfig
            }//-text

          }//-contactsurvey (exists)

          //
          // actions
          //

          // start by storing received message to db
          $sendername = "Annie"; //default, use contact if thats the sender
          if ($sender == $contactnumber) {
            $sendername = $contact->{'firstname'}." ".$contact->{'lastname'};
          }
          $notneededmessageid = $anniedb->insertMessage($key,json_decode(json_encode(array(
            //id is generated: "id"=>?,
            "created"=>$sendtime,
            "createdby"=>"Engine",
            "updated"=>$sendtime,
            "updatedby"=>"Engine",
            "body"=>$text,
            "sender"=>$sendername,
            "survey"=>$survey,
            "status"=>"RECEIVED" // special case when message comes from contact
          ))));
          if ($notneededmessageid === FALSE) {
            $areyouokay = false;
          }
          if (!$areyouokay) {
            error_log("WARNING: Engine: insert message(1) failed");
          }
          //...continue anyway

          // send Annies next message (continue survey), if applicable
          if (in_array($nextphase,$possiblephases)) {
            $areyouokay = $anniedb->insertContactsurvey($key,json_decode(json_encode(array(
              //default: "updated"=>null,
              "updatedby"=>"Engine", //not important
              "survey"=>$survey,
              "status"=>$nextphase
            ))));
            if (!$areyouokay) {
              error_log("WARNING: Engine: insert contactsurvey(1) failed");
            }
            //...continue anyway

            // make message personalized
            // replace string placeholders, like "{{ firstname }}"
            $replaceables=preg_split('/[^{]*(\{\{ [^}]+ \}\})[^{]*/', $nextmessage, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
            $personalized=$nextmessage;
            foreach ($replaceables as $replacekey) {
              $ck = trim(str_replace("{{ ","",str_replace(" }}", "", $replacekey)));
              if (array_key_exists($ck, $contact)) {
                $personalized=str_replace($replacekey, $contact->{$ck}, $personalized);
              }
            }
            $nextmessage=$personalized;

            // final (possibly redundant) check:
            if (!$contactnumber || !$nextmessage) {
              error_log("ERROR: Engine: was about to send message but either contactnumber: ".$contactnumber." or nextmessage: ".$nextmessage." is empty");
            } else {
              // store message (next phase)
              $messageid = $anniedb->insertMessage($key,json_decode(json_encode(array(
                //id is generated: "id"=>?,
                //default: "created"=>null,
                "createdby"=>"Engine",
                //default: "updated"=>null,
                "updatedby"=>"Engine",
                "body"=>$nextmessage,
                "sender"=>"Annie",
                "survey"=>$survey
              ))));
              if ($messageid === FALSE) {
                $areyouokay = false;
              }
              // test $areyouokay
              if (!$areyouokay) {
                error_log("ERROR: Engine: insert message(2) failed");
              } else {
                // sendSMS
                $destinations = array($contactnumber);
                $res = $sms->sendSms(null, $destinations, $nextmessage, array("batchid"=>$messageid));
                foreach($res["errors"] as $error) {
                  error_log("ERROR: Engine: " . $error["message"]);
                  $areyouokay = false;
                }
                foreach($res["warnings"] as $warning) {
                  error_log("WARNING: Engine: " . $warning["message"]);
                  $areyouokay = false;
                }
                //ok: foreach($destinations as $des)
                //todo test $areyouokay
              }
            }

            if ($dosupportneed) {
              $category = explode(".",$nextphase)[0];//if there is ".other" cut it out
              if (!$category) {
                $category = "Z";//Unknown, actual default by anniedb but use it here for clarity
              }
              $areyouokay = $anniedb->insertSupportneed($key,json_decode(json_encode(array(
                //default: "updated"=>null,
                "updatedby"=>"Annie", // UI shows this
                //not needed: "contact"=>$key,
                "category"=>$category,
                //not needed: "status"=>1,
                "survey"=>$survey
                //? "userrole"=>?
              ))));
              if (!$areyouokay) {
                error_log("WARNING: Engine: insert supportneed failed");
              }
              //...continue anyway

              // end the survey for this contact (rule: whenever supportneed...)
              $areyouokay = $anniedb->insertContactsurvey($key,json_decode(json_encode(array(
                //default: "updated"=>null,
                "updatedby"=>"Engine",
                "survey"=>$survey,
                "status"=>'100'
              ))));
              if (!$areyouokay) {
                error_log("WARNING: Engine: insert contactsurvey(2) failed");
              }
            } else {
              // if next nextphase doesnt exists (responding to "A" for example) end the contactsurvey
              $nextnextphaseexists = false;
              foreach ($currentphaseconfig as $fk => $fv) {
                if (strpos($fk,"response") !== false) {
                  $nextnextphaseexists = true;
                }
              }
              if (!$nextnextphaseexists) {
                $areyouokay = $anniedb->insertContactsurvey($key,json_decode(json_encode(array(
                  //default: "updated"=>null,
                  "updatedby"=>"Engine",
                  "survey"=>$survey,
                  "status"=>'100'
                ))));
                if (!$areyouokay) {
                  error_log("WARNING: Engine: insert contactsurvey(3) failed");
                }
              }
            }

          }
          // end the survey for this contact (no nextphase!)
          if (!$nextphase) {
            $areyouokay = $anniedb->insertContactsurvey($key,json_decode(json_encode(array(
              //default: "updated"=>null,
              "updatedby"=>"Engine",
              "survey"=>$survey,
              "status"=>'100'
            ))));
            if (!$areyouokay) {
              error_log("WARNING: Engine: insert contactsurvey(4) failed");
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
}

?>