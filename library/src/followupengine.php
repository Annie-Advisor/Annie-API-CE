<?php
/* followupengine.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as "follow-up engine" for SMS flow.
 *
 * NB! Included (via require) into another script
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

require_once 'my_app_specific_library_dir/mail.php';

$quriiriapiuri = $settings['quriiri']['apiuri'];
$quriiriapikey = $settings['quriiri']['apikey'];

require_once 'my_app_specific_quriiri_dir/sender.php';
$quriiri = new Quriiri_API\Sender($quriiriapikey,$quriiriapiuri);

//
// FLOW
//
/*
???
*/

/* variables expected:
$contactnumber = "";
$contactid = null;

$survey = null; // for message
$requestid = null; // one of supportneedid, the id followup points to

$followupresult = "";

$nextphase = null;
$possiblephases = array();
$nextmessage = null;
$nextphaseisleaf = null; //"next" is for grouping variable names in gatekeeper
$currentphaseconfig = null; //for checking next nextphase
*/

// check all mandatory variables
//error_log("DEBUG: Followup: CALLED with contactnumber=$contactnumber contactid=$contactid supportneed=$requestid nextphase=$nextphase nextphaseisleaf=$nextphaseisleaf");
//error_log("DEBUG: Followup: ...... .... possiblephases=[".implode(",",$possiblephases)."]");
//error_log("DEBUG: Followup: ...... .... nextmessage=$nextmessage");
//error_log("DEBUG: Followup: ...... .... currentphaseconfig=".json_encode($currentphaseconfig));
if (isset($contactnumber) && isset($contactid) && isset($requestid)
 && (isset($nextphase) || isset($nextphaseisleaf))
) {

  // send Annies next message (continue followup), if applicable
  if (in_array($nextphase,$possiblephases)) {
    $followupid = $anniedb->insertFollowup((object)array(
      "supportneed"=>$requestid,
      "status"=>$nextphase,
      "updatedby"=>"Followup" //not important
    ));
    if ($followupid === FALSE) {
      error_log("ERROR: Followup: insert(1) failed");
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

    // final (possibly redundant) check:
    if (!$contactnumber || !$nextmessage) {
      error_log("ERROR: Followup: was about to send message but either contactnumber: ".$contactnumber." or nextmessage: ".$nextmessage." is empty");
    } else {
      if (!isset($survey)) {
        $survey = null;
      }
      // store message (next phase)
      $messageid = $anniedb->insertMessage(json_decode(json_encode(array(
        //id is generated: "id"=>?,
        "contact"=>$contactid,
        //default: "created"=>null,
        "createdby"=>"Followup",
        //default: "updated"=>null,
        "updatedby"=>"Followup",
        "body"=>$nextmessage,
        "sender"=>"Annie",
        "survey"=>$survey,
        "context"=>"FOLLOWUP"
      ))));
      //error_log("DEBUG: Followup: insert message: id=$messageid");
      if ($messageid === FALSE) {
        error_log("ERROR: Followup: insert message failed");
        $areyouokay = false;
      }
      if ($areyouokay) {
        $res = json_decode(json_encode($anniedb->selectConfig('sms','validity')))[0];
        $smsvalidity = isset($res->value) ? $res->value : 1440;//default 24h
        //error_log("DEBUG: Followup: sendSms: contactnumber=$contactnumber messageid=$messageid smsvalidity=$smsvalidity");
        // sendSMS
        // convert destination/contactnumber to array
        $res = $quriiri->sendSms(null, array($contactnumber), $nextmessage, array("batchid"=>$messageid, "validity"=>$smsvalidity));
        if (!is_array($res)) {
          error_log("ERROR: Followup: SendSMS: result is not understood");
          $areyouokay = false;
        }
        else {
          if (array_key_exists("errors", $res)) foreach($res["errors"] as $error) {
            error_log("ERROR: Followup: SendSMS: " . $error["message"]);
            $areyouokay = false;
          }
          if (array_key_exists("warnings",$res)) foreach($res["warnings"] as $warning) {
            error_log("WARNING: Followup: SendSMS: " . $warning["message"]);
            $areyouokay = false;
          }
          if (!array_key_exists("messages", $res)) {
            error_log("ERROR: Followup: SendSMS: result is missing \"messages\"");
            $areyouokay = false;
          } else if (!array_key_exists($contactnumber, $res["messages"])) {
            error_log("ERROR: Followup: SendSMS: result is missing \"$contactnumber\" under \"messages\"");
            $areyouokay = false;
          } else if (!array_key_exists("status", $res["messages"][$contactnumber])) {
            error_log("ERROR: Followup: SendSMS: result is missing \"status\" under \"messages\".\"$contactnumber\"");
            $areyouokay = false;
          }
        }
        // update message.status via response
        if ($areyouokay) {
          if ($res["messages"][$contactnumber]) {
            $data = $res["messages"][$contactnumber];
            if ($data["status"]) {
              //error_log("DEBUG: Followup: SendSMS: status=" . $data["status"] . " for " . $messageid);
              $areyouokay = $anniedb->updateMessage($messageid,json_decode(json_encode(array(
                "updatedby"=>"Followup",
                "status"=>$data["status"]
              ))));
              if (!$areyouokay) {
                error_log("ERROR: Followup: update message failed");
                $areyouokay = false;
              }
            }
          }
        }
      }
    }
  }

  // if we've reached leaf level end the followup
  if ($nextphaseisleaf) {
    $followupid = $anniedb->insertFollowup((object)array(
      "supportneed"=>$requestid,
      "status"=>"100",
      "updatedby"=>"Followup" //not important
    ));
    if ($followupid === FALSE) {
      error_log("ERROR: Followup: insert(2) failed");
      $areyouokay = false;
    }
    //...continue anyway

    // save the result to supportneed.
    if (array_key_exists("result", $currentphaseconfig)) {
      // fetch previous data for supportneed (to keep but also to alter here)
      $supportneedhistory = $anniedb->selectSupportneedHistory($requestid);
      $survey = null;
      $category = null;
      $status = null;
      $supporttype = null;
      $followuptype = null;
      $followupresult = $currentphaseconfig->{'result'};
      foreach ($supportneedhistory as $sni => $supportneed) {
        if ($supportneed['current'] === true) {
          $survey = $supportneed['survey'];
          $category = $supportneed['category'];
          $status = $supportneed['status'];
          $supporttype = $supportneed['supporttype'];
          $followuptype = $supportneed['followuptype'];
          //result is what we do here!
          break;
        }
      }
      if (is_null($category)) {
        error_log("ERROR: Followup: could not get previous supportneed data");
        $areyouokay = false;
      } else {
        // "When followup needs supportproviders attention, change supportneed status to NEW"
        if ($followupresult == "NOHELP" || $followupresult == "OTHER") {
          $status = "NEW";
        } elseif ($followuptype == "LIKERT") {
          if ($followupresult == "1" || $followupresult == "2") {
            $status = "NEW";
          }
        }
        // new id is used for checking to whom the notification goes to
        $newsupportneedid = $anniedb->insertSupportneed(json_decode(json_encode(array(
          "updatedby" => "Annie", // UI shows this
          "contact" => $contactid,
          "survey" => $survey,
          "category" => $category,
          "status" => $status,
          "supporttype" => $supporttype,
          "followuptype" => $followuptype,
          "followupresult" => $followupresult
        ))));
        if ($newsupportneedid === FALSE) {
          error_log("ERROR: Followup: insert supportneed failed");
          $areyouokay = false;
        }

        // no mail notification on supportneeds of supporttype INFORMATION
        if ($supporttype != "INFORMATION") {
          // send email (per followup)
          $replaceables = (object)array(
            "firstname" => null,
            "lastname" => null,
            "supportneedid" => $requestid
          );

          $sql = "select value from $dbschm.config where segment='ui' and field='language'";
          $sth = $dbh->prepare($sql);
          $sth->execute();
          $res = $sth->fetch(PDO::FETCH_OBJ);
          $lang = isset($res->value) ? json_decode($res->value) : null;

          // followupComplete for GOTHELP and INPROGRESS
          // followupCompleteReopen for NOHELP and OTHER
          $mailtag = null;
          if ($followupresult == 'GOTHELP' || $followupresult == 'INPROGRESS') {
            $mailtag = "followupComplete";
          } elseif ($followupresult == 'NOHELP' || $followupresult == 'OTHER') {
            $mailtag = "followupCompleteReopen";
          } elseif ($followuptype == 'LIKERT') {
            if ($followupresult == "1" || $followupresult == "2") {
              $mailtag = "followupCompleteReopen";
            } elseif ($followupresult == "3" || $followupresult == "4" || $followupresult == "5") {
              $mailtag = "followupComplete";
            }
          }
          if (isset($mailtag)) {
            $sql = "select value from $dbschm.config where segment='mail' and field='$mailtag'";
            $sth = $anniedb->getDbh()->prepare($sql);
            $sth->execute();
            $res = $sth->fetch(PDO::FETCH_OBJ);
            $mailcontent = isset($res->value) ? json_decode($res->value) : null;

            // users with access (for email "To")
            $sql = "
            select au.id, au.meta, au.iv
            , co.contact as contactdata, co.iv as contactiv
            from $dbschm.annieuser au
            join $dbschm.supportneed sn on sn.id = :supportneed
            join $dbschm.contact co on co.id = sn.contact
            where 1=1
            -- TODO: to whom? link between supportneed and user?
            and (1=0
              or (au.id,sn.survey,sn.category) in (select annieuser,survey,category from $dbschm.usageright_provider)
              or (
                (au.id,sn.contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
                and (sn.survey,sn.category) NOT in (select survey,category from $dbschm.usageright_provider)
              )
            )
            and coalesce(au.notifications,'DISABLED') != 'DISABLED'
            and coalesce(au.validuntil,'9999-09-09') > now()
            ";
            $sth = $anniedb->getDbh()->prepare($sql);
            $sth->bindParam(':supportneed', $newsupportneedid); // nb! use the latest supportneed id for latest usage rights
            $sth->execute();
            $annieuserrows = $sth->fetchAll(PDO::FETCH_ASSOC);
            $annieusers = array();
            foreach ($annieuserrows as $au) {
              $annieuser = (object)array("id" => $au['id']);
              $iv = base64_decode($au['iv']);
              $annieusermeta = json_decode(decrypt($au['meta'],$iv));
              if (isset($annieusermeta) && array_key_exists('email', $annieusermeta)) {
                $annieuser->{'email'} = $annieusermeta->{'email'};
                array_push($annieusers, $annieuser);
              }

              $iv = base64_decode($au['contactiv']);
              $contactdata = json_decode(decrypt($au['contactdata'],$iv));
              if (array_key_exists('firstname', $contactdata)) {
                $replaceables->firstname = $contactdata->firstname;
              }
              if (array_key_exists('lastname', $contactdata)) {
                $replaceables->lastname = $contactdata->lastname;
              }
            }

            if (isset($annieusers) && count($annieusers)>0 && isset($mailcontent) && isset($lang)) {
              mailOnFollowupComplete($mailtag,$replaceables,$annieusers,$mailcontent,$lang);
            } else {
              error_log("WARNING: Followup: could not send mail");
            }
          } else {
            // not necessarily an error
            error_log("WARNING: Followup: could not get mail template ($mailtag) for followup result=$followupresult and type=$followuptype");
          }
        }//-supporttype!=INFORMATION
      }
    } else {
      error_log("WARNING: Followup: could not get followupresult");
    }//-followupresult
  }
}//- mandatory variables
?>