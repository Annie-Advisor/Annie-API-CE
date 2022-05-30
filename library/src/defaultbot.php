<?php
/* defaultbot.php
 * Copyright (c) 2019-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as "default bot" for SMS flow.
 *
 * NB! Included (via require) into another script
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

$quriiriapiuri = $settings['quriiri']['apiuri'];
$quriiriapikey = $settings['quriiri']['apikey'];

require_once 'my_app_specific_quriiri_dir/sender.php';
$quriiri = new Quriiri_API\Sender($quriiriapikey,$quriiriapiuri);

//
//
//

/* variables expected:
$contactnumber
$messageid ???
*/

//TODO: is messageid mandatory?
$messageid = isset($messageid)?$messageid:null;

// check all mandatory variables
//error_log("DEBUG: DefaultBot: CALLED with contactnumber=$contactnumber messageid=$messageid");
if (isset($contactnumber)) {

  // - query nextmessage from config (bot.defaultResponseSMS)
  // - send nextmessage

  $nextmessage = null;

  $res = json_decode(json_encode($anniedb->selectConfig('bot','defaultResponseSMS')))[0];
  if (isset($res) && isset($res->value)) {
    $resjson = json_decode($res->value);
    //error_log("DEBUG: DefaultBot: config.bot.defaultResponseSMS: ".json_encode($resjson));
    if (isset($resjson->message)) {
      //error_log("DEBUG: DefaultBot: config.bot.defaultResponseSMS::message: ".$resjson->message);
      $nextmessage = $resjson->message;
    }
  }
  if (!isset($nextmessage)) {
    error_log("WARNING: DefaultBot: nextmessage not found");
  } else {

    $res = json_decode(json_encode($anniedb->selectConfig('sms','validity')))[0];
    $smsvalidity = isset($res->value) ? $res->value : 1440;//default 24h

    //error_log("DEBUG: DefaultBot: sendSms: contactnumber=$contactnumber messageid=$messageid smsvalidity=$smsvalidity");
    // sendSMS
    // convert destination/contactnumber to array
    $res = $quriiri->sendSms(null, array($contactnumber), $nextmessage, array("batchid"=>$messageid, "validity"=>$smsvalidity));
  }
}//- mandatory variables
?>