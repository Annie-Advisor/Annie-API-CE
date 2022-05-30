<?php
/* followup.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to initiate SMS flow.
 * Very similar to survey starting initiate.php but this is for supportneed based followup.
 *
 * NB! Included into main script which handles all settings and database connections
 * Expected variables:
 * - $settings
 * - $anniedb
 * - $followuptype
 * - $supportneedid
 * - $contactid
 * - $destination
 * - $category
 * - $lang
 */

$smsapiuri = $settings['quriiri']['apiuri'];
$smsapikey = $settings['quriiri']['apikey'];

require_once 'my_app_specific_quriiri_dir/sender.php';
$smsprovider = new Quriiri_API\Sender($smsapikey,$smsapiuri);

//
// BEGIN FLOW
//

// variables
$areyouokay = true;
if (!$followuptype) {
  $areyouokay = false;
  print(date("Y-m-d H:i:s")." FAILED: Followup(i): followuptype not set".PHP_EOL);
}
if (!$supportneedid) {
  $areyouokay = false;
  print(date("Y-m-d H:i:s")." FAILED: Followup(i): $followuptype: supportneedid not set".PHP_EOL);
}
if (!$contactid) {
  $areyouokay = false;
  print(date("Y-m-d H:i:s")." FAILED: Followup(i): $followuptype: contactid not set".PHP_EOL);
}
if ($areyouokay && !$destination) {
  $areyouokay = false;
  print(date("Y-m-d H:i:s")." FAILED: Followup(i): $followuptype: destination not set with contactid=".$contactid.PHP_EOL);
}

//print("DEBUG: Followup(i): $followuptype: supportneedid=$supportneedid".PHP_EOL);
$messagetemplate = null;
if ($areyouokay) {
  $followupconfig = json_decode($anniedb->selectConfig('followup','config')[0]['value']);
  if (array_key_exists($followuptype, $followupconfig)) {
    $flow = $followupconfig->{$followuptype};
    if (array_key_exists("message", $flow)) {
      $messagetemplate = $flow->{'message'};
    }
  } else {
    print(date("Y-m-d H:i:s")." FAILED: Followup(i): $followuptype: could not find from config".PHP_EOL);
    $areyouokay = false;
  }
}
if ($areyouokay && is_null($messagetemplate)) {
  print(date("Y-m-d H:i:s")." FAILED: Followup(i): $followuptype: could not resolve messagetemplate from config".PHP_EOL);
  $areyouokay = false;
}
//print("DEBUG: Followup(i): $followuptype: message: ".$messagetemplate.PHP_EOL);

// actions
if ($areyouokay && $messagetemplate) {
  $message = $messagetemplate;//copy template for replacements!
  //print("DEBUG: Followup(i): $followuptype: message to: ".$destination.PHP_EOL);

  if ($areyouokay) { //or contactid
    $followupid = $anniedb->insertFollowup((object)array(
      "supportneed"=>$supportneedid,
      "status"=>"1",
      "updatedby"=>"Followup" //not important
    ));
    if ($followupid === FALSE) {
      error_log("ERROR: Followup(i): insert followup(1) failed");
      $areyouokay = false;
    }
    //...continue anyway

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
        // other replaceables
        if ($replacekey == "supportneedcategory") {
          $categorynames = $anniedb->selectCodes('category',$category);
          if (is_array($categorynames) && count($categorynames)>0) {
            $personalized = str_replace($replaceable, $categorynames[0]->{'title'}, $personalized);
          }
        }
      }
    }
    $message = $personalized;
    //print("DEBUG: Followup(i): $followuptype: message personalized: ".$message." for: ".$destination.PHP_EOL);

    // store message
    $messageid = $anniedb->insertMessage(json_decode(json_encode(array(
      //id is generated: "id"=>?,
      "contact"=>$contactid,
      //default: "created"=>null,
      "createdby"=>"Followup",
      //default: "updated"=>null,
      "updatedby"=>"Followup",
      "body"=>$message,
      "sender"=>"Annie",
      "survey"=>$survey,
      "context"=>"FOLLOWUP"
    ))));
    if ($messageid === FALSE) {
      error_log("ERROR: Followup(i): insert message failed");
      $areyouokay = false;
    }
    // sendSMS, one at a time due to personalized message
    if ($areyouokay) {
      // convert destination to array
      $res = $smsprovider->sendSms(null, array($destination), $message, array("batchid"=>$messageid));
      foreach($res["errors"] as $error) {
        print(date("Y-m-d H:i:s")." ERROR: Followup(i): $followuptype: smsprovider: " . $error["message"].PHP_EOL);
        $areyouokay = false;
      }
      foreach($res["warnings"] as $warning) {
        print(date("Y-m-d H:i:s")." WARNING: Followup(i): $followuptype: smsprovider: " . $warning["message"].PHP_EOL);
        $areyouokay = false;
      }
      //ok but may be FAILED cases
      // update message.status via response
      if ($areyouokay) {
        //print("DEBUG: Followup(i): $followuptype: Smsprovider: messageid=$messageid " . $res["messages"][$destination]["status"].PHP_EOL);
        if ($res["messages"][$destination]) {
          $data = $res["messages"][$destination];
          if ($data["status"]) {
            //if (array_key_exists("reason", $data))
                //echo ", reason " . $data["reason"];
            $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
              //default: "updated"=>null,
              "updatedby"=>"Followup",
              "status"=>$data["status"]
            ))));
            if (!$areyouokay) {
              error_log("ERROR: Followup(i): update message failed");
              $areyouokay = false;
            }
            // on immediate fail:
            if ($data["status"] == "FAILED") {
              // end the followup
              $followupid = $anniedb->insertFollowup(json_decode(json_encode(array(
                "supportneed"=>$supportneedid,
                "status"=>"100",
                "updatedby"=>"Followup"
              ))));
              if ($followupid === FALSE) {
                error_log("ERROR: Followup(i): insert followup(2) failed");
                $areyouokay = false;
              }
              // followupresult=FAILED to supportneed (keep supportneed.status et al)
              $followupresult = "FAILED";
              // new id is not used here so store it in a different variable
              $newsupportneedid = $anniedb->insertSupportneed(json_decode(json_encode(array(
                "id" => $supportneedid, // for getting previous data
                "updatedby"=>"Annie", // UI shows this
                "contact"=>$contactid,
                "survey"=>$survey,
                "category" => $category,
                //previous: status
                //previous: supporttype
                "followuptype"=>$followuptype,
                "followupresult"=>$followupresult
              ))));
              if ($newsupportneedid === FALSE) {
                error_log("ERROR: Followup(i): insert supportneed failed");
                $areyouokay = false;
              }
            }
          }
        }
      }
    }
  }
}

// included, no end tag
