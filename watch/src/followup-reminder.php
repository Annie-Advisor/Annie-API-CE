<?php
/* followup-reminder.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>

Backend script to handle SMS flow followup reminder part.

NB! Included into main script which handles all settings and database connections
Expected variables:
- $settings
- $anniedb
- $supportneedid -- "request id"
- $survey -- survey.id
- $contact -- for contactid
- $contactdata -- decrypted
- $messagetemplate
**/

$smsapiuri = $settings['quriiri']['apiuri'];
$smsapikey = $settings['quriiri']['apikey'];

require_once 'my_app_specific_quriiri_dir/sender.php';
$smsprovider = new Quriiri_API\Sender($smsapikey,$smsapiuri);

// variables
$areyouokay = true;
if (!$supportneedid || !$contact || !$contactdata || !$messagetemplate) {
  $areyouokay = false;
}

$destination = null;
if (array_key_exists('phonenumber', $contactdata)) {
  $destination = $contactdata->{'phonenumber'};
}
$contactid = $contact;

// actions
if ($areyouokay && $destination && $messagetemplate) {
  $message = $messagetemplate;//copy template for replacements!
  //error_log("DEBUG: Followup Reminder: message to: ".$destination);

  if ($areyouokay) { //or contactid
    $followupid = $anniedb->insertFollowup((object)array(
      "supportneed" => $supportneedid,
      "status" => "2",
      "updatedby" => "Reminder" //not important
    ));
    if ($followupid === FALSE) {
      error_log("ERROR: Followup Reminder: insert followup(1) failed");
      $areyouokay = false;
    }
    //...continue anyway

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

    // store message
    $messageid = $anniedb->insertMessage(json_decode(json_encode(array(
      "contact" => $contactid,
      "createdby" => "Reminder",
      "updatedby" => "Reminder",
      "body" => $message,
      "sender" => "Annie",
      "survey" => $survey,
      "context" => "FOLLOWUP"
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
        //print("DEBUG: Followup Reminder: $followuptype: Smsprovider: messageid=$messageid " . $res["messages"][$destination]["status"].PHP_EOL);
        if ($res["messages"][$destination]) {
          $data = $res["messages"][$destination];
          if ($data["status"]) {
            $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
              "updatedby" => "Followup",
              "status" => $data["status"]
            ))));
            if (!$areyouokay) {
              error_log("ERROR: Followup Reminder: update message failed");
              $areyouokay = false;
            }
            // on immediate fail:
            if ($data["status"] == "FAILED") {
              // end the followup
              $followupid = $anniedb->insertFollowup(json_decode(json_encode(array(
                "supportneed" => $supportneedid,
                "status" => "100",
                "updatedby" => "Followup"
              ))));
              if ($followupid === FALSE) {
                error_log("ERROR: Followup Reminder: insert followup(2) failed");
                $areyouokay = false;
              }
              // followupresult=FAILED to supportneed (keep supportneed.status et al)
              // new id is not used here so store it in a different variable
              $newsupportneedid = $anniedb->insertSupportneed(json_decode(json_encode(array(
                "id" => $supportneedid, // for getting previous data
                "updatedby" => "Annie", // UI shows this
                "contact" => $contactid, // could use previous
                "survey" => $survey, // could use previous
                //previous: category
                //previous: status
                //previous: supporttype
                //previous: followuptype
                "followupresult" => "FAILED"
              ))));
              if ($newsupportneedid === FALSE) {
                error_log("ERROR: Followup Reminder: insert supportneed failed");
                $areyouokay = false;
              }
            }
          }
        }
        //: test for areyouokay?
      }
    }
  }
}

// included, no end tag
