<?php
/* stuck-reminder.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to handle SMS flow stuck in survey reminder part.
 *
 * NB! Included into main script which handles all settings and database connections
 * Expected variables:
 * - $settings
 * - $anniedb
 * - $survey
 * - $contact -- for contactid
 * - $contactdata -- decrypted
 * - $contactsurveystatus
 */

$smsapiuri = $settings['quriiri']['apiuri'];
$smsapikey = $settings['quriiri']['apikey'];

require_once 'my_app_specific_quriiri_dir/sender.php';
$smsprovider = new Quriiri_API\Sender($smsapikey,$smsapiuri);

// variables
$areyouokay = true;
if (!$survey || !$contact || !$contactdata || !$messagetemplate || !$contactsurveystatus) {
  $areyouokay = false;
}

$destination = null;
if (array_key_exists('phonenumber', $contactdata)) {
  $destination = $contactdata->{'phonenumber'};
}
$contactid = $contact;

// actions
if ($areyouokay && $destination) {
  $message = $messagetemplate;//copy template for replacements!

  if ($areyouokay) { //or contactid
    // repeat the same status to keep track
    $areyouokay = $anniedb->insertContactsurvey(json_decode(json_encode(array(
      "contact" => $contactid,
      "survey" => $survey,
      "status" => $contactsurveystatus,
      "updatedby" => "StuckReminder" //not important
    ))));

    // make message personalized
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
      //id is generated: "id"=>?,
      "contact" => $contactid,
      //default: "created"=>null,
      "createdby" => "StuckReminder",
      //default: "updated"=>null,
      "updatedby" => "StuckReminder",
      "body" => $message,
      "sender" => "Annie",
      "survey" => $survey,
      "context" => "SURVEY"
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
        error_log("ERROR: StuckReminder: smsprovider: " . $error["message"]);
        $areyouokay = false;
      }
      foreach($res["warnings"] as $warning) {
        error_log("WARNING: StuckReminder: smsprovider: " . $warning["message"]);
        $areyouokay = false;
      }
      //ok but may be FAILED cases
      // update message.status via response
      if ($areyouokay) {
        if ($res["messages"][$destination]) {
          $data = $res["messages"][$destination];
          if ($data["status"]) {
            $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
              //default: "updated"=>null,
              "updatedby"=>"StuckReminder",
              "status"=>$data["status"]
            ))));
            //: test $areyouokay
            // on immediate fail:
            //TODO what? if ($data["status"] == "FAILED") {
              // end the survey for this contact?
            //}
          }
        }
        //: test for areyouokay?
      }
    }
  }
}

// included, no end tag
