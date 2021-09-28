<?php
/* reminder.php
 * Copyright (c) 2019-2021 Rapida
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to handle SMS flow reminder part.
 *
 * NB! Included into main script which handles all settings and database connections
 * Expected variables:
 * - $settings
 * - $anniedb
 * - $survey
 * - $contact -- for contactid
 * - $contactdata -- decrypted
 */

$smsapiuri = $settings['quriiri']['apiuri'];
$smsapikey = $settings['quriiri']['apikey'];

require_once('/opt/quriiri_api/sender.php');
$smsprovider = new Quriiri_API\Sender($smsapikey,$smsapiuri);

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
if (!$survey || !$contact || !$contactdata || !$messagetemplate) {
  $areyouokay = false;
}

//error_log("DEBUG: Reminder: config: survey: ".$survey);

$destination = null;
if (array_key_exists('phonenumber', $contactdata)) {
  $destination = $contactdata->{'phonenumber'};
}
$contactid = $contact;

//error_log("DEBUG: Reminder: config: message: ".$messagetemplate);

// actions
if ($areyouokay && $destination && $messagetemplate) {
  // ".reminder"
  $message = $messagetemplate;//copy template for replacements!
  //error_log("DEBUG: Reminder: message to: ".$destination);

  if ($areyouokay) { //or contactid
    $areyouokay = $anniedb->insertContactsurvey($contactid,json_decode(json_encode(array(
      //default: "updated"=>null,
      "updatedby"=>"Reminder", //not important
      "survey"=>$survey,
      "status"=>"2"
    ))));
    //: test $areyouokay

    // make message personalized
    // replace string placeholders, like "{{ firstname }}"
    // assume contactdata

    $replaceables = preg_split('/[^{]*(\{\{[^}]+\}\})[^{]*/', $message, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
    $personalized = $message;
    if (gettype($replaceables) === "array" && $replaceables[0] !== $message) {
      foreach ($replaceables as $replaceable) {
        $replacekey = trim(strtolower(preg_replace('/\{\{\s*([^}]+)\s*\}\}/', '$1', $replaceable)));
        if (array_key_exists($replacekey, $contactdata)) {
          $personalized = str_replace($replaceable, $contactdata->{$replacekey}, $personalized);
        }
      }
    }
    $message = $personalized;
    //error_log("DEBUG: Reminder: message personalized: ".$message." for: ".$destination);
    //if (array_key_exists("firstname", $contactdata)) {
    //  $message = str_replace("{{ firstname }}", $contactdata->{'firstname'}, $message);
    //  error_log("DEBUG: Reminder: firstname: ".$contactdata->{'firstname'}." for: ".$destination);
    //}

    // store message
    $messageid = $anniedb->insertMessage($contactid,json_decode(json_encode(array(
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
    //: test $areyouokay
    // sendSMS, one at a time due to personalized message
    if ($areyouokay) {
      // convert destination to array
      $res = $smsprovider->sendSms(null, array($destination), $message, array("batchid"=>$messageid));
      foreach($res["errors"] as $error) {
        error_log("ERROR: Reminder: smsprovider: " . $error["message"]);
        $areyouokay = false;
      }
      foreach($res["warnings"] as $warning) {
        error_log("WARNING: Reminder: smsprovider: " . $warning["message"]);
        $areyouokay = false;
      }
      //ok but may be FAILED cases
      // update message.status via response
      if ($areyouokay) {
        //error_log("DEBUG: Reminder: smsprovider: messageid=$messageid " . $res["messages"][$destination]["status"]);
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
            //: test $areyouokay
            // do supportneed on immediate fail
            if ($data["status"] == "FAILED") {
              $areyouokay = $anniedb->insertSupportneed($contactid,json_decode(json_encode(array(
                //default: "updated"=>null,
                "updatedby"=>"Annie", // UI shows this
                //not needed: "contact"=>$contactid,
                "category"=>"W", // message not delivered
                //not needed: "status"=>1,
                "survey"=>$survey
                //? "userrole"=>?
              ))));
              //: test $areyouokay
              // end the survey for this contact (rule: whenever supportneed...)
              $areyouokay = $anniedb->insertContactsurvey($contactid,json_decode(json_encode(array(
                //default: "updated"=>null,
                "updatedby"=>"Reminder",
                "survey"=>$survey,
                "status"=>'100'
              ))));
              //: test $areyouokay
            }
          }
        }
        //: test for areyouokay?
      }
    }
  }
}

// included, no end tag
