<?php
/* supportprocess.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as "support process" for SMS flow.
 *
 * NB! Included (via require) into another script
 */

require_once '/opt/annie/settings.php';//->settings,db*

require_once '/opt/annie/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
//* + db for specialized queries
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
//- db */

require_once '/opt/annie/mail.php';

/* variables expected:
$contactnumber
$contactid

$survey
$category
*/

// check all mandatory variables
//error_log("DEBUG: SupportProcess: CALLED with contactnumber=$contactnumber contactid=$contactid survey=$survey category=$category");
if (isset($contactnumber) && isset($contactid) && isset($survey) && isset($category)) {

  //error_log("DEBUG: SupportProcess: NOTIFY right persons");

  // mail immediately to "annieuser is responsible for"
  // we do know survey and category so
  // query annieusers "responsible for"
  // nb! not for superusers
  $sql = "
  select id as annieuser
  from $dbschm.annieuser
  where id in (
    select annieuser
    from $dbschm.annieusersurvey
    where survey = :survey
    and (meta->'category'->:category)::boolean
  )
  and notifications = 'IMMEDIATE'
  ";
  $sth = $dbh->prepare($sql);
  $sth->bindParam(':survey', $survey);
  $sth->bindParam(':category', $category);
  $sth->execute();
  $annieusers = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));

  $res = json_decode(json_encode($anniedb->selectConfig('mail','messageToSupportneedImmediate')))[0];
  $mailcontent = isset($res->value) ? json_decode($res->value) : null;

  $res = json_decode(json_encode($anniedb->selectConfig('ui','language')))[0];
  $lang = isset($res->value) ? json_decode($res->value) : null;

  $surveyname = null;
  $categoryname = null;
  $surveys = json_decode(json_encode($anniedb->selectSurvey($survey,array())));
  if (isset($surveys) && is_array($surveys) && !empty($surveys)) {
    if (isset($surveys[0])) {
      if (array_key_exists('config',$surveys[0])) {
        if (array_key_exists('name',$surveys[0]->{'config'})) {
          $surveyname = $surveys[0]->{'config'}->{'name'};
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

  //error_log("DEBUG: SupportProcess: MAIL surveyname($survey)=".json_encode($surveyname)." categoryname($category)=".json_encode($categoryname)." annieusers=[".implode(",",$annieusers)."]");
  if (isset($surveyname) && isset($categoryname) && isset($annieusers) && !empty($annieusers) && isset($mailcontent) && isset($lang)) {
    mailOnMessageToSupportneedImmediate($surveyname,$categoryname,$annieusers,$mailcontent,$lang);
  }

}//- mandatory variables
?>