<?php
/* sms.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * SMS message forming and sending.
 *
 * NB! Included (via require_once) into another script
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*,quriiri*

$quriiriapiuri = $settings['quriiri']['apiuri'];
$quriiriapikey = $settings['quriiri']['apikey'];
if ( !( isset($quriiriapiuri) && isset($quriiriapikey) ) ) {
  die("Failed with settings");
}

require_once 'my_app_specific_quriiri_dir/sender.php';
$smsprovider = new Quriiri_API\Sender($quriiriapikey,$quriiriapiuri);

$clientname = $settings['my']['name'];
if (empty($clientname)) {
  $clientname = "annie"; // default value might be wise to hit an existing mail address
}

// keyword list to replace from text template
// nb! presetting here is not mandatory
$myreplaceables = (object)array(
  "hostname" => $clientname,
  // very often used, for example
  "surveyname" => ""
);

function fillPlaceholders($texttemplate) {
  global $myreplaceables;

  $processedtext = $texttemplate;

  // replace string placeholders, like "{{ firstname }}"
  $placeholders = preg_split('/[^{]*(\{\{[^}]+\}\})[^{]*/', $processedtext, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
  if (gettype($placeholders) === "array" && $placeholders[0] !== $processedtext) {
    foreach ($placeholders as $placeholder) {
      $replacekey = trim(strtolower(preg_replace('/\{\{\s*([^}]+)\s*\}\}/', '$1', $placeholder)));
      if (array_key_exists($replacekey, $myreplaceables)) {
        $processedtext = str_replace($placeholder, $myreplaceables->$replacekey, $processedtext);
      }
    }
  }

  return $processedtext;
}

/* smsSend
- Generic SMS sending function. One at a time.
Parameters:
- $destination - string of a phonenumber
- $messagetemplate - string with possible placeholders like {{firstname}}
- $replaceables - (object)array of key=>value replaceables
- $lang - string of language code (fi,sv,en,...)
- $smsvalidity - integer in minutes to keep trying SMS delivery (by provider)
*/
function smsSend($destination,$messagetemplate,$replaceables,$lang,$smsvalidity) {
  global $smsprovider, $myreplaceables;

  $areyouokay = true;

  if (!isset($destination) || empty($destination)
   || !isset($messagetemplate) || !isset($messagetemplate->$lang)
  ) {
    $areyouokay = false;
  }

  foreach ($replaceables as $replacekey => $replacevalue) {
    $myreplaceables->$replacekey = $replacevalue;
  }

  if ($areyouokay) {
    $message = fillPlaceholders($messagetemplate->$lang);

    // sendSMS (sender, destination, text, optional)
    // convert destination to array
    $res = $smsprovider->sendSms(null, array($destination), $message, array(/*"batchid"=>$messageid,*/ "validity"=>$smsvalidity));
    //error_log("DEBUG: SendSMS: " . var_export($res,true));
    foreach($res["errors"] as $error) {
      error_log("ERROR: smsSend: " . $error["message"]);
      $areyouokay = false;
    }
    foreach($res["warnings"] as $warning) {
      error_log("WARNING: smsSend: " . $warning["message"]);
      $areyouokay = false;
    }
  }

  return $areyouokay;
}
