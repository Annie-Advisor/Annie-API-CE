<?php
/* surveycancel.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as "cancel" in survey context for SMS flow.
 *
 * NB! Included (via require) into another script
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
//* + db for specialized queries
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
//- db */

require_once 'my_app_specific_library_dir/mail.php';

$quriiriapiuri = $settings['quriiri']['apiuri'];
$quriiriapikey = $settings['quriiri']['apikey'];

require_once 'my_app_specific_quriiri_dir/sender.php';
$quriiri = new Quriiri_API\Sender($quriiriapikey,$quriiriapiuri);

// actions

/* variables expected:
$contactid
$contactnumber
$survey
$nextmessage
*/

if (isset($contactnumber) && isset($contactid) && isset($survey) && isset($nextmessage)) {

  // make message personalized
  // replace string placeholders, like "{{ firstname }}"
  // nextmessage
  $replaceables = preg_split('/[^{]*(\{\{[^}]+\}\})[^{]*/', $nextmessage, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
  $personalized = $nextmessage;
  if (gettype($replaceables) === "array" && $replaceables[0] !== $nextmessage) {
    foreach ($replaceables as $replaceable) {
      $replacekey = trim(strtolower(preg_replace('/\{\{\s*([^}]+)\s*\}\}/', '$1', $replaceable)));
      if (array_key_exists($replacekey, $contact)) {
        $personalized = str_replace($replaceable, $contact->{$replacekey}, $personalized);
      }
    }
  }
  $nextmessage = $personalized;

  // final (possibly redundant) check:
  if (!$contactnumber || !$nextmessage) {
    error_log("ERROR: SurveyCancel: was about to send message but either contactnumber: ".$contactnumber." or nextmessage: ".$nextmessage." is empty");
  } else {
    // store message
    $messageid = $anniedb->insertMessage(json_decode(json_encode(array(
      "contact"=>$contactid,
      "createdby"=>"SurveyCancel",
      "updatedby"=>"SurveyCancel",
      "body"=>$nextmessage,
      "sender"=>"Annie",
      "survey"=>$survey,
      "context"=>"SURVEY"
    ))));
    if ($messageid === FALSE) {
      error_log("ERROR: SurveyCancel: insert message failed");
      $areyouokay = false;
    }
    if ($areyouokay) {
      $res = json_decode(json_encode($anniedb->selectConfig('sms','validity')))[0];
      $smsvalidity = isset($res->value) ? $res->value : 1440;//default 24h
      // sendSMS
      // convert destination/contactnumber to array
      $res = $quriiri->sendSms(null, array($contactnumber), $nextmessage, array("batchid"=>$messageid, "validity"=>$smsvalidity));
      if (!is_array($res)) {
        error_log("ERROR: SurveyCancel: SendSMS: result is not understood");
        $areyouokay = false;
      }
      else {
        if (array_key_exists("errors", $res)) foreach($res["errors"] as $error) {
          error_log("ERROR: SurveyCancel: SendSMS: " . $error["message"]);
          $areyouokay = false;
        }
        if (array_key_exists("warnings",$res)) foreach($res["warnings"] as $warning) {
          error_log("WARNING: SurveyCancel: SendSMS: " . $warning["message"]);
          $areyouokay = false;
        }
        if (!array_key_exists("messages", $res)) {
          error_log("ERROR: SurveyCancel: SendSMS: result is missing \"messages\"");
          $areyouokay = false;
        } else if (!array_key_exists($contactnumber, $res["messages"])) {
          error_log("ERROR: SurveyCancel: SendSMS: result is missing \"$contactnumber\" under \"messages\"");
          $areyouokay = false;
        } else if (!array_key_exists("status", $res["messages"][$contactnumber])) {
          error_log("ERROR: SurveyCancel: SendSMS: result is missing \"status\" under \"messages\".\"$contactnumber\"");
          $areyouokay = false;
        }
      }
      // update message.status via response
      if ($areyouokay) {
        if ($res["messages"][$contactnumber]) {
          $data = $res["messages"][$contactnumber];
          if ($data["status"]) {
            $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
              //"updated"=>[not null],
              "updatedby"=>"SurveyCancel",
              "status"=>$data["status"]
            ))));
            if (!$areyouokay) {
              error_log("ERROR: SurveyCancel: update message failed");
              $areyouokay = false;
            }
          }
        }
      }
    }
  }

  // end the survey for contact
  $areyouokay = $anniedb->insertContactsurvey(json_decode(json_encode(array(
    "contact"=>$contactid,
    "survey"=>$survey,
    "status"=>"100",
    //default: "updated"=>null,
    "updatedby"=>"SurveyCancel" //not important
  ))));
  if (!$areyouokay) {
    error_log("ERROR: SurveyCancel: insert contactsurvey failed");
    $areyouokay = false;
  }
}//- mandatory variables
?>