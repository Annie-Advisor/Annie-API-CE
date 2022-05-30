<?php
/* surveyengine.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as "survey engine" for SMS flow.
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

//
// FLOW/ENGINE
//
/*
Input comes from SMS Provider.
From there we get sender and destination numbers which will give us
enough information to query db and get means to make decisions in
which phase this current actor (student, destination number) is.
Phase (or survey + supportneed.category) should give us sufficient
information to decide on next action...
*/

/* SMS Provider JSON data example from PUSH (from SMS Provider to Annie):
{
  "sender": "+358500000002",
  "sendertype": "MSISDN",
  "destination": "+358400000001",
  "text": "H\u20acllo, world!",
  "sendtime": "2015-09-14T10:31:25Z",
  "status": "SENT",
  "statustime": "2015-09-14T10:31:25Z"
}
*/
//nb! batchid for survey

/* SMS Provider JSON data example for POST (from Annie to Student via SMS Provider):
{
  "sender": "+358500000002",
  "destination": "+358400000001",
  "text": "H\u20acllo, world!",
}
*/

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
//    substring(".response[?]",len("branch")) == ",?,"
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
// PUSHed by SMS Provider or polled by this engine (which call must be made by something)

/* variables expected:
$contactnumber = "";
$contactid = null;

$survey = $contactsurveys[0]->{'survey'};

$nextphase = null;
$nextphaseisleaf = null; //"next" is for grouping variable names in gatekeeper
$nextmessage = null;
$firstmessage = null;
$currentphaseconfig = null; //for checking next nextphase

$possiblephases = array();
$dosupportneed = false;
*/

// check all mandatory variables
//error_log("DEBUG: Engine: CALLED with contactnumber=$contactnumber contactid=$contactid survey=$survey nextphase=$nextphase nextphaseisleaf=$nextphaseisleaf dosupportneed=$dosupportneed");
//error_log("DEBUG: Engine: ...... .... possiblephases=[".implode(",",$possiblephases)."]");
//error_log("DEBUG: Engine: ...... .... currentphaseconfig=".json_encode($currentphaseconfig));
//error_log("DEBUG: Engine: ...... .... nextmessage=$nextmessage");
//error_log("DEBUG: Engine: ...... .... firstmessage=$firstmessage");
if (isset($contactnumber) && isset($contactid) && isset($survey)
 && (isset($nextphase) || isset($nextphaseisleaf))
) {

  // send Annies next message (continue survey), if applicable
  if (in_array($nextphase,$possiblephases)) {
    $areyouokay = $anniedb->insertContactsurvey(json_decode(json_encode(array(
      "contact"=>$contactid,
      "survey"=>$survey,
      "status"=>$nextphase,
      //default: "updated"=>null,
      "updatedby"=>"Engine" //not important
    ))));
    if (!$areyouokay) {
      error_log("ERROR: Engine: insert contactsurvey(1) failed");
      $areyouokay = false;
    }
    //...continue anyway

    // make message(s) personalized
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
    // firstmessage
    $replaceables = preg_split('/[^{]*(\{\{[^}]+\}\})[^{]*/', $firstmessage, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
    $personalized = $firstmessage;
    if (gettype($replaceables) === "array" && $replaceables[0] !== $firstmessage) {
      foreach ($replaceables as $replaceable) {
        $replacekey = trim(strtolower(preg_replace('/\{\{\s*([^}]+)\s*\}\}/', '$1', $replaceable)));
        if (array_key_exists($replacekey, $contact)) {
          $personalized = str_replace($replaceable, $contact->{$replacekey}, $personalized);
        }
      }
    }
    $firstmessage = $personalized;

    // final (possibly redundant) check:
    if (!$contactnumber || !$nextmessage) {
      error_log("ERROR: Engine: was about to send message but either contactnumber: ".$contactnumber." or nextmessage: ".$nextmessage." is empty");
    } else {
      // store message (next phase)
      $messageid = $anniedb->insertMessage(json_decode(json_encode(array(
        //id is generated: "id"=>?,
        "contact"=>$contactid,
        //default: "created"=>null,
        "createdby"=>"Engine",
        //default: "updated"=>null,
        "updatedby"=>"Engine",
        "body"=>$nextmessage,
        "sender"=>"Annie",
        "survey"=>$survey,
        "context"=>"SURVEY"
      ))));
      //error_log("DEBUG: Engine: insert message: id=$messageid");
      if ($messageid === FALSE) {
        error_log("ERROR: Engine: insert message failed");
        $areyouokay = false;
      }
      if ($areyouokay) {
        $res = json_decode(json_encode($anniedb->selectConfig('sms','validity')))[0];
        $smsvalidity = isset($res->value) ? $res->value : 1440;//default 24h
        //error_log("DEBUG: Engine: sendSms: contactnumber=$contactnumber messageid=$messageid smsvalidity=$smsvalidity");
        // sendSMS
        // convert destination/contactnumber to array
        $res = $quriiri->sendSms(null, array($contactnumber), $nextmessage, array("batchid"=>$messageid, "validity"=>$smsvalidity));
        if (!is_array($res)) {
          error_log("ERROR: Engine: SendSMS: result is not understood");
          $areyouokay = false;
        }
        else {
          if (array_key_exists("errors", $res)) foreach($res["errors"] as $error) {
            error_log("ERROR: Engine: SendSMS: " . $error["message"]);
            $areyouokay = false;
          }
          if (array_key_exists("warnings",$res)) foreach($res["warnings"] as $warning) {
            error_log("WARNING: Engine: SendSMS: " . $warning["message"]);
            $areyouokay = false;
          }
          if (!array_key_exists("messages", $res)) {
            error_log("ERROR: Engine: SendSMS: result is missing \"messages\"");
            $areyouokay = false;
          } else if (!array_key_exists($contactnumber, $res["messages"])) {
            error_log("ERROR: Engine: SendSMS: result is missing \"$contactnumber\" under \"messages\"");
            $areyouokay = false;
          } else if (!array_key_exists("status", $res["messages"][$contactnumber])) {
            error_log("ERROR: Engine: SendSMS: result is missing \"status\" under \"messages\".\"$contactnumber\"");
            $areyouokay = false;
          }
        }
        // update message.status via response
        if ($areyouokay) {
          if ($res["messages"][$contactnumber]) {
            $data = $res["messages"][$contactnumber];
            if ($data["status"]) {
              //error_log("DEBUG: Engine: SendSMS: status=" . $data["status"] . " for " . $messageid);
              $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
                //"updated"=>[not null],
                "updatedby"=>"Engine",
                "status"=>$data["status"]
              ))));
              if (!$areyouokay) {
                error_log("ERROR: Engine: update message failed");
                $areyouokay = false;
              }
            }
          }
        }
      }
    }
    if ($dosupportneed) {
      $category = "Z"; // Unknown, actual default by anniedb but use it here for clarity
      $status = "NEW"; // default
      $supporttype = "MISSING"; // default
      $followuptype = null; // default not existing
      if (array_key_exists("category", $currentphaseconfig)) {
        $category = $currentphaseconfig->{'category'};
      }
      if (array_key_exists("supporttype", $currentphaseconfig)) {
        $supporttype = $currentphaseconfig->{'supporttype'};
        if ($supporttype == "INFORMATION") {
          $status = "ACKED";
        }
      }
      if (array_key_exists("category", $currentphaseconfig)) {
        $followuptype = $currentphaseconfig->{'followuptype'};
      }
      $supportneedid = $anniedb->insertSupportneed(json_decode(json_encode(array(
        //default: "updated" => null,
        "updatedby" => "Annie", // UI shows this
        "contact" => $contactid,
        "survey" => $survey,
        "category" => $category,
        "status" => $status,
        "supporttype" => $supporttype,
        "followuptype" => $followuptype
        //previous: followupresult
      ))));
      if ($supportneedid === FALSE) {
        error_log("ERROR: Engine: insert supportneed failed");
        $areyouokay = false;
      } else {
        // no mail notification on supportneeds of supporttype INFORMATION
        if ($supporttype != "INFORMATION") {
          // mail immediately to "annieuser is responsible for"
          // we do know survey and category so
          // query annieusers "responsible for"
          // nb! not for superusers
          $sql = "
          select id, meta, iv
          from $dbschm.annieuser
          where id in (
            select annieuser
            from $dbschm.annieusersurvey
            where survey = :survey
            and (meta->'category'->:category)::boolean
            union --AD-260 responsible teacher
            select annieuser
            from $dbschm.usageright_teacher
            where teacherfor = :contact
            and (:survey,:category) NOT in (select survey,category from $dbschm.usageright_provider)
          )
          and notifications = 'IMMEDIATE'
          and coalesce(validuntil,'9999-09-09') > now()
          ";
          $sth = $dbh->prepare($sql);
          $sth->bindParam(':survey', $survey);
          $sth->bindParam(':category', $category);
          $sth->bindParam(':contact', $contactid);
          $sth->execute();
          $annieuserrows = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
          $annieusers = array();
          foreach ($annieuserrows as $au) {
            $annieuser = (object)array("id" => $au->{'id'});
            $iv = base64_decode($au->{'iv'});
            $annieusermeta = json_decode(decrypt($au->{'meta'},$iv));
            if (isset($annieusermeta) && array_key_exists('email', $annieusermeta)) {
              $annieuser->{'email'} = $annieusermeta->{'email'};
              array_push($annieusers, $annieuser);
            }
          }

          $res = json_decode(json_encode($anniedb->selectConfig('mail','supportneedImmediate')))[0];
          $mailcontent = isset($res->value) ? json_decode($res->value) : null;

          $res = json_decode(json_encode($anniedb->selectConfig('ui','language')))[0];
          $lang = isset($res->value) ? json_decode($res->value) : null;

          $firstname = null;
          $lastname = null;
          $surveyname = null;
          $categoryname = null;
          $contacts = json_decode(json_encode($anniedb->selectContact($contactid)));
          if (isset($contacts) && is_array($contacts) && !empty($contacts)) {
            if (isset($contacts[0])) {
              if (array_key_exists('contact', $contacts[0])) {
                if (array_key_exists('firstname', $contacts[0]->{'contact'})) {
                  $firstname = $contacts[0]->{'contact'}->{'firstname'};
                }
                if (array_key_exists('lastname', $contacts[0]->{'contact'})) {
                  $lastname = $contacts[0]->{'contact'}->{'lastname'};
                }
              }
            }
          }
          $surveys = json_decode(json_encode($anniedb->selectSurvey($survey,array())));
          if (isset($surveys) && is_array($surveys) && !empty($surveys)) {
            if (isset($surveys[0])) {
              if (array_key_exists('config',$surveys[0])) {
                if (array_key_exists('title',$surveys[0]->{'config'})) {
                  $surveyname = $surveys[0]->{'config'}->{'title'};
                }
              }
            }
          }
          $categorynames = json_decode(json_encode($anniedb->selectCodes('category',$category)));
          if (is_array($categorynames) && count($categorynames)>0) {
            $categoryname = $categorynames[0];
          } else {
            $categoryname = (object)array('title' => $category); // default to code?
          }

          if (isset($firstname) && isset($lastname) && isset($surveyname) && isset($categoryname)
           && isset($annieusers) && !empty($annieusers) && isset($mailcontent) && isset($lang)
          ) {
            //AD-285 "Change notifications so that only the “final” support need causes notifications"
            //AD-500 "Send notifications always when creating support need"
            //if ($nextphaseisleaf) {
              mailOnSupportneedImmediate($firstname,$lastname,$surveyname,$categoryname,$supportneedid,$firstmessage,$nextmessage,$annieusers,$mailcontent,$lang);
            //}
          }
        }//-supporttype!=INFORMATION
      }//-supportneedid!=FALSE
    }//-dosupportneed
  }//-nextphase in possiblephases

  // if we've reached leaf level end the survey
  if ($nextphaseisleaf) {
    $areyouokay = $anniedb->insertContactsurvey(json_decode(json_encode(array(
      "contact"=>$contactid,
      "survey"=>$survey,
      "status"=>"100",
      //default: "updated"=>null,
      "updatedby"=>"Engine" //not important
    ))));
    if (!$areyouokay) {
      error_log("ERROR: Engine: insert contactsurvey(2) failed");
      $areyouokay = false;
    }
  }
}//- mandatory variables
?>