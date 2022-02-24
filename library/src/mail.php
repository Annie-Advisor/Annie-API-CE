<?php
/* mail.php
 * Copyright (c) 2021 Annie Advisor
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

$emailapikey = $settings['sendinblue']['apikey'];
require_once 'my_app_specific_sendinblue_dir/autoload.php';
$emailconfig = SendinBlue\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', $emailapikey);
$emailprovider = new SendinBlue\Client\Api\TransactionalEmailsApi(
  new GuzzleHttp\Client(),
  $emailconfig
);

$clientname = "annie"; // default value might be wise to hit an existing mail address
if (gethostname()) {
  $clientname = explode(".",gethostname())[0];
}

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
function mailOnInitiate($surveyrow,$destinations,$annieusers,$mailcontent,$lang) {
  global $emailprovider, $replaceablevalues, $clientname;

  if (!isset($surveyrow) || !isset($destinations) || !isset($annieusers) || !isset($mailcontent) || !isset($lang)) {
    return;
  }

  $sendSmtpEmail = new SendinBlue\Client\Model\SendSmtpEmail();

  $surveyconfig = json_decode($surveyrow->{'config'});
  if (array_key_exists('title', $surveyconfig)) {
    $surveyname = $surveyconfig->{'title'};
  }

  // replaceables
  $replaceablevalues->{"contactcount"} = count($destinations);
  $replaceablevalues->{"surveyname"} = $surveyname;

  // subject
  $sendSmtpEmail['subject'] = textUnTemplate($mailcontent->subject->$lang);

  // body
  // header
  $sendSmtpEmail['htmlContent'] = textUnTemplate($mailcontent->header->$lang);
  // footer
  // nb! catenate
  $sendSmtpEmail['htmlContent'] .= textUnTemplate($mailcontent->footer->$lang);

  // meta
  $sendSmtpEmail['sender'] = array('name' => 'Annie Advisor', 'email' => $clientname.'@annieadvisor.com');
  // for each recipient (superuser or assigned to survey)
  $mailrecipients = array();
  foreach ($annieusers as $au) {
    // check email format
    if (filter_var($au->{'email'}, FILTER_VALIDATE_EMAIL)) {
      array_push($mailrecipients,array('email' => $au->{'email'}));
    }
  }
  if (empty($mailrecipients)) {
    echo 'WARNING: No recipients for email', PHP_EOL;
  } else {
    $sendSmtpEmail['to'] = $mailrecipients;
    $sendSmtpEmail['bcc'] = array(
        array('email' => 'copy+'.$clientname.'@annieadvisor.com')
    );
    try {
      $result = $emailprovider->sendTransacEmail($sendSmtpEmail);
    } catch (Exception $e) {
      echo 'ERROR: Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
    }
  }
}


/*
*/
function mailOnSurveyEnd($surveyrow,$supportneeds,$annieusers,$mailcontent,$lang) {
  global $emailprovider, $replaceablevalues, $clientname;

  if (!isset($surveyrow) || !isset($supportneeds) || !isset($annieusers) || !isset($mailcontent) || !isset($lang)) {
    return;
  }

  $sendSmtpEmail = new SendinBlue\Client\Model\SendSmtpEmail();

  // to-do-ish: should sanity check more, e.g. existance of 'config' etc.
  $surveyconfig = json_decode($surveyrow->{'config'});
  if (array_key_exists('title', $surveyconfig)) {
    $surveyname = $surveyconfig->{'title'};
  }

  // replaceables
  $replaceablevalues->{"surveyname"} = $surveyname;

  // subject
  $sendSmtpEmail['subject'] = textUnTemplate($mailcontent->subject->$lang);

  // body
  // header
  $sendSmtpEmail['htmlContent'] = textUnTemplate($mailcontent->header->$lang);

  // nb! case speciality: middle table
  // must catenate to htmlContent
  // TODO: some Finnish words still
  $supportneedstatus1 = "";
  $tmpjson = json_decode($surveyrow->{'supportneedstatus1'});
  if ($tmpjson && array_key_exists($lang, $tmpjson)) {
    $supportneedstatus1 = $tmpjson->{$lang};
  }
  $supportneedstatus2 = "";
  $tmpjson = json_decode($surveyrow->{'supportneedstatus2'});
  if ($tmpjson && array_key_exists($lang, $tmpjson)) {
    $supportneedstatus2 = $tmpjson->{$lang};
  }
  $supportneedstatus100 = "";
  $tmpjson = json_decode($surveyrow->{'supportneedstatus100'});
  if ($tmpjson && array_key_exists($lang, $tmpjson)) {
    $supportneedstatus100 = $tmpjson->{$lang};
  }
  if (count($supportneeds)==0) {
    $sendSmtpEmail['htmlContent'] .= '
    <p>Ei tuen tarpeita</p>
    ';
  } else {
    $sendSmtpEmail['htmlContent'] .= '
    <table>
    <tr>
    <td><b>Kategoria</b></td>
    <td><b>'.$supportneedstatus1.'</b></td>
    <td><b>'.$supportneedstatus2.'</b></td>
    <td><b>'.$supportneedstatus100.'</b></td>
    </tr>
    ';
    foreach ($supportneeds as $sn) {
      $categoryname = "";
      $tmpjson = json_decode($sn->{'categoryname'});
      if ($tmpjson && array_key_exists($lang, $tmpjson)) {
        $categoryname = $tmpjson->{$lang};
      }
      $sendSmtpEmail['htmlContent'] .= '
      <tr>
      <td>'.$categoryname.'</td>
      <td>'.$sn->{'supportneedstatus1count'}.'</td>
      <td>'.$sn->{'supportneedstatus2count'}.'</td>
      <td>'.$sn->{'supportneedstatus100count'}.'</td>
      </tr>
      ';
    }
    $sendSmtpEmail['htmlContent'] .= '
    </table>
    ';
  }

  // footer
  // nb! catenate
  $sendSmtpEmail['htmlContent'] .= textUnTemplate($mailcontent->footer->$lang);

  // meta
  $sendSmtpEmail['sender'] = array('name' => 'Annie Advisor', 'email' => $clientname.'@annieadvisor.com');
  // for each recipient (superuser or assigned to survey)
  $mailrecipients = array();
  foreach ($annieusers as $au) {
    // check email format
    if (filter_var($au->{'email'}, FILTER_VALIDATE_EMAIL)) {
      array_push($mailrecipients,array('email' => $au->{'email'}));
    }
  }
  if (empty($mailrecipients)) {
    echo 'WARNING: No recipients for email', PHP_EOL;
  } else {
    $sendSmtpEmail['to'] = $mailrecipients;
    $sendSmtpEmail['bcc'] = array(
        array('email' => 'copy+'.$clientname.'@annieadvisor.com')
    );
    try {
      $result = $emailprovider->sendTransacEmail($sendSmtpEmail);
    } catch (Exception $e) {
      echo 'ERROR: Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
    }
  }
}

function mailOnSurveyEndTeacher($surveyrow,$supportneeds,$annieuser,$mailcontent,$lang) {
  global $emailprovider, $replaceablevalues, $clientname;

  if (!isset($surveyrow) || !isset($supportneeds) || !isset($annieuser) || !isset($mailcontent) || !isset($lang)) {
    return;
  }

  $sendSmtpEmail = new SendinBlue\Client\Model\SendSmtpEmail();

  // to-do-ish: should sanity check more, e.g. existance of 'config' etc.
  $surveyconfig = json_decode($surveyrow->{'config'});
  if (array_key_exists('title', $surveyconfig)) {
    $surveyname = $surveyconfig->{'title'};
  }

  // replaceables
  $replaceablevalues->{"surveyname"} = $surveyname;
  $replaceablevalues->{"teachername"} = "";
  if (array_key_exists("firstname", $annieuser)) {
    $replaceablevalues->{"teachername"} = $annieuser->{'firstname'};
  }

  // subject
  $sendSmtpEmail['subject'] = textUnTemplate($mailcontent->subject->$lang);

  // body
  // header
  $sendSmtpEmail['htmlContent'] = textUnTemplate($mailcontent->header->$lang);

  // nb! case speciality: middle table
  // must catenate to htmlContent
  // TODO: some Finnish words still
  $supportneedstatus1 = "";
  $tmpjson = json_decode($surveyrow->{'supportneedstatus1'});
  if ($tmpjson && array_key_exists($lang, $tmpjson)) {
    $supportneedstatus1 = $tmpjson->{$lang};
  }
  $supportneedstatus2 = "";
  $tmpjson = json_decode($surveyrow->{'supportneedstatus2'});
  if ($tmpjson && array_key_exists($lang, $tmpjson)) {
    $supportneedstatus2 = $tmpjson->{$lang};
  }
  $supportneedstatus100 = "";
  $tmpjson = json_decode($surveyrow->{'supportneedstatus100'});
  if ($tmpjson && array_key_exists($lang, $tmpjson)) {
    $supportneedstatus100 = $tmpjson->{$lang};
  }
  if (count($supportneeds)==0) {
    $sendSmtpEmail['htmlContent'] .= '
    <p>Ei tuen tarpeita</p>
    ';
  } else {
    $sendSmtpEmail['htmlContent'] .= '
    <table>
    <tr>
    <td><b>Kategoria</b></td>
    <td><b>'.$supportneedstatus1.'</b></td>
    <td><b>'.$supportneedstatus2.'</b></td>
    <td><b>'.$supportneedstatus100.'</b></td>
    </tr>
    ';
    foreach ($supportneeds as $sn) {
      $categoryname = "";
      $tmpjson = json_decode($sn->{'categoryname'});
      if ($tmpjson && array_key_exists($lang, $tmpjson)) {
        $categoryname = $tmpjson->{$lang};
      }
      $sendSmtpEmail['htmlContent'] .= '
      <tr>
      <td>'.$categoryname.'</td>
      <td>'.$sn->{'supportneedstatus1count'}.'</td>
      <td>'.$sn->{'supportneedstatus2count'}.'</td>
      <td>'.$sn->{'supportneedstatus100count'}.'</td>
      </tr>
      ';
    }
    $sendSmtpEmail['htmlContent'] .= '
    </table>
    ';
  }

  // footer
  // nb! catenate
  $sendSmtpEmail['htmlContent'] .= textUnTemplate($mailcontent->footer->$lang);

  // meta
  $sendSmtpEmail['sender'] = array('name' => 'Annie Advisor', 'email' => $clientname.'@annieadvisor.com');
  // for each recipient (superuser or assigned to survey)
  $mailrecipients = array();
  // check email format
  if (filter_var($annieuser->{'email'}, FILTER_VALIDATE_EMAIL)) {
    array_push($mailrecipients,array('email' => $annieuser->{'email'}));
  }
  if (empty($mailrecipients)) {
    echo 'WARNING: No recipients for email', PHP_EOL;
  } else {
    $sendSmtpEmail['to'] = $mailrecipients;
    $sendSmtpEmail['bcc'] = array(
        array('email' => 'copy+'.$clientname.'@annieadvisor.com')
    );
    try {
      $result = $emailprovider->sendTransacEmail($sendSmtpEmail);
    } catch (Exception $e) {
      echo 'ERROR: Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
    }
  }
}

/* "When a new supportneed is created that a certain annieuser is responsible for"
*/
function mailOnSupportneedImmediate($firstname,$lastname,$surveyname,$categoryname,$annieusers,$mailcontent,$lang) {
  global $emailprovider, $replaceablevalues, $clientname;

  if (!isset($firstname) || !isset($lastname) || !isset($surveyname) || !isset($categoryname)
   || !isset($annieusers) || !isset($mailcontent) || !isset($lang)
  ) {
    return;
  }

  $sendSmtpEmail = new SendinBlue\Client\Model\SendSmtpEmail();

  // replaceables
  $replaceablevalues->{"firstname"} = $firstname;
  $replaceablevalues->{"lastname"} = $lastname;
  $replaceablevalues->{"surveyname"} = $surveyname;
  $replaceablevalues->{"supportneedcategory"} = $categoryname->$lang;

  // subject
  $sendSmtpEmail['subject'] = textUnTemplate($mailcontent->subject->$lang);

  // body
  // header
  $sendSmtpEmail['htmlContent'] = textUnTemplate($mailcontent->header->$lang);
  // footer
  // nb! catenate
  $sendSmtpEmail['htmlContent'] .= textUnTemplate($mailcontent->footer->$lang);

  // meta
  $sendSmtpEmail['sender'] = array('name' => 'Annie Advisor', 'email' => $clientname.'@annieadvisor.com');
  // for each recipient (superuser or assigned to survey)
  $mailrecipients = array();
  foreach ($annieusers as $au) {
    // check email format
    if (filter_var($au->{'email'}, FILTER_VALIDATE_EMAIL)) {
      array_push($mailrecipients,array('email' => $au->{'email'}));
    }
  }
  if (empty($mailrecipients)) {
    echo 'WARNING: No recipients for email', PHP_EOL;
  } else {
    $sendSmtpEmail['to'] = $mailrecipients;
    $sendSmtpEmail['bcc'] = array(
        array('email' => 'copy+'.$clientname.'@annieadvisor.com')
    );

    // send
    try {
      $result = $emailprovider->sendTransacEmail($sendSmtpEmail);
    } catch (Exception $e) {
      echo 'ERROR: Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
    }
  }
}

/* "When a new message to existing supportneed arrives notify responsible for annieuser"
*/
function mailOnMessageToSupportneedImmediate($firstname,$lastname,$surveyname,$categoryname,$annieusers,$mailcontent,$lang) {
  global $emailprovider, $replaceablevalues, $clientname;

  if (!isset($firstname) || !isset($lastname) || !isset($surveyname) || !isset($categoryname)
   || !isset($annieusers) || !isset($mailcontent) || !isset($lang)
  ) {
    return;
  }

  $sendSmtpEmail = new SendinBlue\Client\Model\SendSmtpEmail();

  // replaceables
  $replaceablevalues->{"firstname"} = $firstname;
  $replaceablevalues->{"lastname"} = $lastname;
  $replaceablevalues->{"surveyname"} = $surveyname;
  $replaceablevalues->{"supportneedcategory"} = $categoryname->$lang;

  // subject
  $sendSmtpEmail['subject'] = textUnTemplate($mailcontent->subject->$lang);

  // body
  // header
  $sendSmtpEmail['htmlContent'] = textUnTemplate($mailcontent->header->$lang);
  // footer
  // nb! catenate
  $sendSmtpEmail['htmlContent'] .= textUnTemplate($mailcontent->footer->$lang);

  // meta
  $sendSmtpEmail['sender'] = array('name' => 'Annie Advisor', 'email' => $clientname.'@annieadvisor.com');
  // for each recipient (superuser or assigned to survey)
  $mailrecipients = array();
  foreach ($annieusers as $au) {
    // check email format
    if (filter_var($au->{'email'}, FILTER_VALIDATE_EMAIL)) {
      array_push($mailrecipients,array('email' => $au->{'email'}));
    }
  }
  if (empty($mailrecipients)) {
    echo 'WARNING: No recipients for email', PHP_EOL;
  } else {
    $sendSmtpEmail['to'] = $mailrecipients;
    $sendSmtpEmail['bcc'] = array(
        array('email' => 'copy+'.$clientname.'@annieadvisor.com')
    );

    // send
    try {
      $result = $emailprovider->sendTransacEmail($sendSmtpEmail);
    } catch (Exception $e) {
      echo 'ERROR: Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
    }
  }
}


/*
*/
function mailOnDailyDigest($annieusers,$mailcontent,$lang) {
  global $emailprovider, $replaceablevalues, $clientname;

  if (!isset($annieusers) || !isset($mailcontent) || !isset($lang)) {
    return;
  }

  $sendSmtpEmail = new SendinBlue\Client\Model\SendSmtpEmail();

  // subject
  $sendSmtpEmail['subject'] = textUnTemplate($mailcontent->subject->$lang);

  // meta
  $sendSmtpEmail['sender'] = array('name' => 'Annie Advisor', 'email' => $clientname.'@annieadvisor.com');
  $sendSmtpEmail['bcc'] = array(
      array('email' => 'copy+'.$clientname.'@annieadvisor.com')
  );

  // body & send

  // for each recipient (superuser or assigned to survey)
  // nb! separately for this one, though
  foreach ($annieusers as $au) {
    // check email format
    if (filter_var($au->{'email'}, FILTER_VALIDATE_EMAIL)) {
      $mailrecipients = array();
      array_push($mailrecipients,array('email' => $au->{'email'}));
      $sendSmtpEmail['to'] = $mailrecipients;

      // replaceables
      $replaceablevalues->{"newmessagecount"} = $au->{'messagecount'};
      $replaceablevalues->{"supportneedcount"} = $au->{'supportneedcount'};

      // header
      $sendSmtpEmail['htmlContent'] = textUnTemplate($mailcontent->header->$lang);
      // footer
      // nb! catenate!
      $sendSmtpEmail['htmlContent'] .= textUnTemplate($mailcontent->footer->$lang);

      // send
      try {
        $result = $emailprovider->sendTransacEmail($sendSmtpEmail);
      } catch (Exception $e) {
        echo 'ERROR: Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
      }
    }
  }

}
