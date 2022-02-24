<?php
/* mail.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Mail message forming and sending.
 *
 * NB! Included (via require_once) into another script which handles all settings
 * Expected variables:
 * - $settings
 */

$clientname = "annie"; // default value might be wise to hit an existing mail address
if (gethostname()) {
  $clientname = explode(".",gethostname())[0];
}

$emaildomain = $settings['mailgun']['domain'];
$emailapiuri = $settings['mailgun']['apiuri'];
$emailapikey = $settings['mailgun']['apikey'];
require 'my_app_specific_mailgun_dir/autoload.php';
use Mailgun\Mailgun;
$mg = Mailgun::create($emailapikey, $emailapiuri);

//
//
//

/* mailSend
- Generic email sending function.
*/
function mailSend($mailsender,$mailrecipients,$mailsubject,$mailbody,$mailtext,$mailtag) {
  global $mg, $emaildomain, $clientname;

  if (!isset($mailrecipients) || empty($mailrecipients)
   || !isset($mailsubject) || !isset($mailbody) || !isset($mailtext)
  ) {
    return false;
  }

  if (!isset($mailsender)) {
    //$mailsender = "Annie Advisor <".$clientname."@".$emaildomain.">";
    $mailsender = "Annie Advisor <".$clientname."@annieadvisor.com>";
  }

  if (!isset($mailtag)) {
    $mailtag = "default";
  }

  //Recipients
  $mailrecipientsstr = "";
  foreach ($mailrecipients as $mailrecipient) {
    if ($mailrecipientsstr) { $mailrecipientsstr.=", "; }
    $mailrecipientsstr.=$mailrecipient->{'email'};
  }

  //error_log("DEBUG: mailSend: $mailtag $mailsubject $mailrecipientsstr");
  if (!$mailrecipientsstr) {
    error_log("WARNING: mailSend: $mailtag $mailsubject with no recipients. Skipping send.");
    return false;
  } else {
    // Now, compose and send your message.
    // $mg->messages()->send($domain, $params);
    $mgresult = $mg->messages()->send($emaildomain, [
      'from'    => $mailsender,
      'to'      => $mailrecipientsstr,
      'subject' => $mailsubject,
      'html'    => $mailbody,
      'text'    => $mailtext,
      'o:tag'   => $mailtag,
      'inline' => array(
        array(
          'filePath' => 'https://annieadvisor.kinsta.cloud/app/uploads/2022/01/logo.png',
          'filename' => 'logo.png'
        )
      )
    ]);
    //if ($mgresult) {
    //  error_log("ERROR: mailSend: Email not sent: ".$mgresult);
    //  return false;
    //}
  }
  return true;
}

// Functions with predefined structures and placeholders (replaceables)

// keyword list to replace from text template
// nb! presetting here is not mandatory
// to-do-ish? this global method might not be optimal...
$replaceablevalues = (object)array(
  "hostname" => $clientname,
  // very often used, for example
  "surveyname" => ""
);

function textUnTemplate($texttemplate) {
  global $replaceablevalues;

  // replace string placeholders, like "{{ firstname }}"
  $replaceables = preg_split('/[^{]*(\{\{[^}]+\}\})[^{]*/', $texttemplate, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
  if (gettype($replaceables) === "array" && $replaceables[0] !== $texttemplate) {
    foreach ($replaceables as $replaceable) {
      $replacekey = trim(strtolower(preg_replace('/\{\{\s*([^}]+)\s*\}\}/', '$1', $replaceable)));
      if (array_key_exists($replacekey, $replaceablevalues)) {
        $texttemplate = str_replace($replaceable, $replaceablevalues->$replacekey, $texttemplate);
      }
    }
  }

  return $texttemplate;
}

/*
*/
function mailOnSurveyStart($surveyrow,$destinations,$annieusers,$firstmessage,$mailcontent,$lang) {
  global $replaceablevalues;

  if (!isset($surveyrow) || !isset($destinations) || !isset($annieusers) || !isset($mailcontent) || !isset($lang)) {
    return;
  }
  if (!isset($firstmessage)) {
    $firstmessage = "";
  }

  $surveyconfig = json_decode($surveyrow->{'config'});
  if (array_key_exists('title', $surveyconfig)) {
    $surveyname = $surveyconfig->{'title'};
  }

  $replaceablevalues->{"contactcount"} = count($destinations);
  $replaceablevalues->{"surveyname"} = $surveyname;
  $replaceablevalues->{"firstmessage"} = $firstmessage;

  $mailsubject = textUnTemplate($mailcontent->subject->$lang);

  $mailbody = textUnTemplate($mailcontent->html->$lang);
  $mailtext = textUnTemplate($mailcontent->plaintext->$lang);

  mailSend(null,$annieusers,$mailsubject,$mailbody,$mailtext,"surveyStart");
}

/* "When a new supportneed is created that a certain annieuser is responsible for"
*/
function mailOnSupportneedImmediate($firstname,$lastname,$surveyname,$categoryname,$supportneed,$firstmessage,$nextmessage,$annieusers,$mailcontent,$lang) {
  global $replaceablevalues;

  if (!isset($firstname) || !isset($lastname) || !isset($surveyname) || !isset($categoryname)) {
    return;
  }
  if (!isset($supportneed)) {
    $supportneed = "";
  }
  if (!isset($firstmessage)) {
    $firstmessage = "";
  }
  if (!isset($nextmessage)) {
    $nextmessage = "";
  }
  if (!isset($annieusers) || !isset($lang)) {
    return;
  }
  if (!isset($mailcontent)) {
    return;
  } else if (!array_key_exists('subject', $mailcontent) || !array_key_exists('html', $mailcontent) || !array_key_exists('plaintext', $mailcontent)) {
    return;
  }

  $replaceablevalues->{"firstname"} = $firstname;
  $replaceablevalues->{"lastname"} = $lastname;
  $replaceablevalues->{"surveyname"} = $surveyname;
  $replaceablevalues->{"supportneedcategory"} = $categoryname->$lang;
  $replaceablevalues->{"supportneedid"} = $supportneed;
  $replaceablevalues->{"lastmessage"} = $nextmessage;
  $replaceablevalues->{"firstmessage"} = $firstmessage;

  $mailsubject = textUnTemplate($mailcontent->subject->$lang);

  $mailbody = textUnTemplate($mailcontent->html->$lang);
  $mailtext = textUnTemplate($mailcontent->plaintext->$lang);

  mailSend(null,$annieusers,$mailsubject,$mailbody,$mailtext,"supportneedImmediate");
}

/* "When a new message to existing supportneed arrives notify responsible for annieuser"
*/
function mailOnMessageToSupportneedImmediate($firstname,$lastname,$surveyname,$categoryname,$supportneed,$annieusers,$mailcontent,$lang) {
  global $replaceablevalues;

  if (!isset($firstname) || !isset($lastname) || !isset($surveyname) || !isset($categoryname)
   || !isset($annieusers) || !isset($mailcontent) || !isset($lang)
  ) {
    return;
  }

  $replaceablevalues->{"firstname"} = $firstname;
  $replaceablevalues->{"lastname"} = $lastname;
  $replaceablevalues->{"surveyname"} = $surveyname;
  $replaceablevalues->{"supportneedcategory"} = $categoryname->$lang;
  $replaceablevalues->{"supportneedid"} = $supportneed;

  $mailsubject = textUnTemplate($mailcontent->subject->$lang);

  $mailbody = textUnTemplate($mailcontent->html->$lang);
  $mailtext = textUnTemplate($mailcontent->plaintext->$lang);

  mailSend(null,$annieusers,$mailsubject,$mailbody,$mailtext,"messageToSupportneedImmediate");
}

/* "Remind support providers & teachers of support requests with email that are in status 1 or 2."
*/
function mailOnReminder($contactdata,$supportneed,$firstmessage,$lastmessage,$annieusers,$mailcontent,$lang) {
  global $replaceablevalues;

  if (!isset($contactdata)) {
    return;
  } else if (!array_key_exists('firstname', $contactdata) || !array_key_exists('lastname', $contactdata)) {
    return;
  }
  if (!isset($supportneed) || !isset($annieusers) || !isset($lang)) {
    return;
  }
  if (!isset($lastmessage)) {
    $lastmessage = "";
  }
  if (!isset($mailcontent)) {
    return;
  } else if (!array_key_exists('subject', $mailcontent) || !array_key_exists('html', $mailcontent) || !array_key_exists('plaintext', $mailcontent)) {
    return;
  }

  //$replaceablevalues->{"hostname"} = $clientname;
  $replaceablevalues->{"firstname"} = $contactdata->firstname;
  $replaceablevalues->{"lastname"} = $contactdata->lastname;
  $replaceablevalues->{"supportneedid"} = $supportneed;
  $replaceablevalues->{"firstmessage"} = $firstmessage;
  $replaceablevalues->{"lastmessage"} = $lastmessage;

  $mailsubject = textUnTemplate($mailcontent->subject->$lang);

  $mailbody = textUnTemplate($mailcontent->html->$lang);
  $mailtext = textUnTemplate($mailcontent->plaintext->$lang);

  mailSend(null,$annieusers,$mailsubject,$mailbody,$mailtext,"reminder");
}
