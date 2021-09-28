<?php
/* initiate.php
 * Copyright (c) 2019-2021 Rapida
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to initiate SMS flow.
 *
 * NB! Included into main script which handles all settings and database connections
 * Expected variables:
 * - $settings
 * - $anniedb
 * - $survey
 * - $destinations
 */

$smsapiuri = $settings['quriiri']['apiuri'];
$smsapikey = $settings['quriiri']['apikey'];

require_once('/opt/quriiri_api/sender.php');
$smsprovider = new Quriiri_API\Sender($smsapikey,$smsapiuri);

//
// BEGIN FLOW
//

/* Expected input:
{
  "sender": "+358500000009", //optional
  "survey": "1",
  "destinations": ["+358400000001","+358400000002","+358400000003"]
}
*/

// steps: (NB! From which only "1. Initiated" is handled here)

// 1. "Initiated"
//  -> [db]contactsurvey
//  -> sendSMS(+db) .message
//todo: when and where to:
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
if (!$survey || !$destinations) {
  $areyouokay = false;
}

//error_log("DEBUG: Initiate: config: survey: ".$survey);
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
        break 1; //stop all
      }
    }
  }
} else {
  error_log("FAILED: Initiate: config: could not find surveyconfig with: survey=".$survey);
  $areyouokay = false;
}
//error_log("DEBUG: Initiate: config: message: ".$messagetemplate);

// actions
if ($areyouokay && $messagetemplate) {
  // 1. "Initiated"
  foreach ($destinations as $destination) {
    $message = $messagetemplate;//copy template for replacements!
    //error_log("DEBUG: Initiate: message to: ".$destination);
    $contactid = null;
    $contactids = json_decode(json_encode($anniedb->selectContactId($destination)));
    if (count($contactids)>0) {
      $contactid = $contactids[0]->{'id'};
      $areyouokay = true;
      //error_log("Initiate: start action for: ".$destination);
    } else {
      $areyouokay = false;
      error_log("FAILED: Initiate: action: FAILED to get contactid for: ".$destination);
    }

    if ($areyouokay) { //or contactid
      $areyouokay = $anniedb->insertContactsurvey($contactid,json_decode(json_encode(array(
        //default: "updated"=>null,
        "updatedby"=>"Initiate", //not important
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
      //error_log("DEBUG: Initiate: message personalized: ".$message." for: ".$destination);
      //if (array_key_exists("firstname", $contact)) {
      //  $message = str_replace("{{ firstname }}", $contact->{'firstname'}, $message);
      //  error_log("DEBUG: Initiate: firstname: ".$contact->{'firstname'}." for: ".$destination);
      //}

      // store message
      $messageid = $anniedb->insertMessage($contactid,json_decode(json_encode(array(
        //id is generated: "id"=>?,
        //default: "created"=>null,
        "createdby"=>"Initiate",
        //default: "updated"=>null,
        "updatedby"=>"Initiate",
        "body"=>$message,
        "sender"=>"Annie",
        "survey"=>$survey,
        "context"=>"SURVEY"
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
          error_log("ERROR: Initiate: smsprovider: " . $error["message"]);
          $areyouokay = false;
        }
        foreach($res["warnings"] as $warning) {
          error_log("WARNING: Initiate: smsprovider: " . $warning["message"]);
          $areyouokay = false;
        }
        //ok but may be FAILED cases
        // update message.status via response
        if ($areyouokay) {
          //error_log("DEBUG: Initiate: Smsprovider: messageid=$messageid " . $res["messages"][$destination]["status"]);
          if ($res["messages"][$destination]) {
            $data = $res["messages"][$destination];
            if ($data["status"]) {
              //if (array_key_exists("reason", $data))
                  //echo ", reason " . $data["reason"];
              $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
                //default: "updated"=>null,
                "updatedby"=>"Initiate",
                "status"=>$data["status"]
              ))));
              //todo test $areyouokay
              // on immediate fail:
              if ($data["status"] == "FAILED") {
                // end the survey for this contact
                $areyouokay = $anniedb->insertContactsurvey($contactid,json_decode(json_encode(array(
                  //default: "updated"=>null,
                  "updatedby"=>"Initiate",
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

// included, no end tag
