<?php
/* supportprocess.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as "support process" for SMS flow.
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

/* variables expected:
$contactnumber
$contactid

$survey
$category
$supportneedstatus
*/

// check all mandatory variables
//error_log("DEBUG: SupportProcess: CALLED with contactnumber=$contactnumber contactid=$contactid survey=$survey category=$category");
if (isset($contactnumber) && isset($contactid) && isset($survey) && isset($category)) {

  if (isset($supportneedstatus) && $supportneedstatus == '100') {//100="Resolved"
    //change supportneedstatus to New
    $newsupportneed = (object)array(
      "updatedby" => "Annie", // UI shows this
      "contact" => $contactid,
      "survey" => $survey,
      "category" => $category,
      "status" => '2' //"In progress"
    );
    $anniedb->insertSupportneed($newsupportneed);
  }

  //error_log("DEBUG: SupportProcess: NOTIFY right persons");

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

  $res = json_decode(json_encode($anniedb->selectConfig('mail','messageToSupportneedImmediate')))[0];
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
    $categoryname = (object)array($lang => $category); // default to code?
  }

  //error_log("DEBUG: SupportProcess: MAIL firstname=$firstname lastname=$lastname surveyname($survey)=".json_encode($surveyname)." categoryname($category)=".json_encode($categoryname)." annieusers=[".implode(",",$annieusers)."]");
  if (isset($firstname) && isset($lastname) && isset($surveyname) && isset($categoryname)
   && isset($annieusers) && !empty($annieusers) && isset($mailcontent) && isset($lang)
  ) {
    mailOnMessageToSupportneedImmediate($firstname,$lastname,$surveyname,$categoryname,$annieusers,$mailcontent,$lang);
  }

}//- mandatory variables
?>