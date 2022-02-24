<?php
/* archive-survey-statistics.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 *
 * Store the result of survey-report to a database table.
 * Process survey archiving if chosen.
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/auth.php';

require_once 'my_app_specific_library_dir/anniedb.php';
//$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
//* dbh for specialized queries
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
// - dbh */

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET'; //PUT, POST, DELETE
$headers[]='Access-Control-Allow-Origin: *';
$headers[]='Access-Control-Allow-Credentials: true';
$headers[]='Access-Control-Max-Age: 1728000';
if (isset($_SERVER['REQUEST_METHOD'])) {
  foreach ($headers as $header) header($header);
} else {
  echo json_encode($headers);
}
header('Content-Type: application/json; charset=utf-8');

// get the HTTP method, path and body of the request
$method = $_SERVER['REQUEST_METHOD'];
if ($method=='OPTIONS') {
  http_response_code(200);
  exit;
}

$request = array();
if (isset($_SERVER['PATH_INFO'])) {
  $request = explode('/', trim($_SERVER['PATH_INFO'],'/'));
}

// parameters
// choices

// get parameters as an array (names here arent mandatory but can be used to force array)
$getarr = array(
  "survey"=>[],
  "archive"=>[]
);
// split on outer delimiter
$pairs = explode('&', $_SERVER['QUERY_STRING']);
// loop through each pair
foreach ($pairs as $i) {
  if ($i) {
    // split into name and value
    list($name,$value) = explode('=', $i, 2);
    // fix value (htmlspecialchars for extra security)
    $value = urldecode(htmlspecialchars($value));
    // if name already exists
    if (isset($getarr[$name])) {
      // stick multiple values into an array
      if (is_array($getarr[$name])) {
        $getarr[$name][] = $value;
      } else {
        $getarr[$name] = array($getarr[$name], $value);
      }
    } else { // otherwise, simply stick it in
      $getarr[$name] = array($value);
    }
  }
}

//
// Query "survey-report" -like data for upserting to surveystatistics table
// NB! Data must be queried (to "front") for decryption first
//
// For process
$processok = true; // keep going as long as this is true

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    // $datafields are the columns of table surveystatistics
    // fields that select query should return and
    // fields that insert statement obeys
    $datafields = array(
      "surveyid","surveyname","surveystarttime","surveyendtime",
      "contactid",
      "contactdegree","contactgroup","contactlocation","contactcustomtext","contactcustomkey",
      "delivered","responded","responsetime",
      "supportneed","remindercount",
      "messagesreceived","messagessent",
      "supportneedcategory","supportneedcategoryname",
      "supportneedstatus","supportneedstatusname"
    );
    // $datafieldkeys are the combination of columns in $datafields
    // that make up the primary key of table surveystatistics
    // there is also the primary key constraint surveystatistics_pk for other use
    $datafieldkeys = array(
      "surveyid","contactid","supportneedcategory"
    );
    // begin SQL building
    $sql = "
SELECT survey.id AS surveyid
, survey.config->>'title' AS surveyname
, survey.starttime AS surveystarttime
, survey.endtime AS surveyendtime
, contact.id AS contactid
, contact.contact, contact.iv --to be contactmeta
, case when exists (
    select 1 from $dbschm.message
    where contact = contact.id
    and survey = survey.id
    and status = 'DELIVERED' --sent to contact
    and (contact,survey,status,created) in (-- first one in this survey
        select m.contact,m.survey,m.status,min(created)
        from $dbschm.message m
        where m.contact = message.contact
        and m.survey = message.survey
        and m.status = message.status
        group by m.contact,m.survey,m.status
    )
  )
  then 1
  else 0
  end AS delivered
, case when exists (
    select 1 from $dbschm.message
    where contact = contact.id
    and survey = survey.id
    and status = 'RECEIVED' --from contact
  )
  then 1
  else 0
  end AS responded
, (
    select ceil(extract(epoch from (min(created) - survey.starttime))/60)::int
    from $dbschm.message
    where contact = contact.id
    and survey = survey.id
    and status = 'RECEIVED' --from contact
    and (contact,survey,status,created) in (-- first one in this survey
        select m.contact,m.survey,m.status,min(created)
        from $dbschm.message m
        where m.contact = message.contact
        and m.survey = message.survey
        and m.status = message.status
        group by m.contact,m.survey,m.status
    )
    group by contact,survey
) AS responsetime
, case when sn.contact is not null then 1 else 0 end AS supportneed
, (
    select count(*)
    from $dbschm.message
    where contact = contact.id
    and survey = survey.id
    and createdby = 'Reminder'
) AS remindercount
, (
    select count(*)
    from $dbschm.message
    where contact = contact.id
    and survey = survey.id
    and status = 'RECEIVED' --from contact
) AS messagesreceived
, (
    select count(*)
    from $dbschm.message
    where contact = contact.id
    and survey = survey.id
    and status != 'RECEIVED' --sent to contact (not from)
) AS messagessent
, coalesce(sn.category,'-') AS supportneedcategory
, (
    select coalesce(value->>(lang.value->>0),value->>'en')
    from $dbschm.codes where codeset='category' and code=sn.category
) AS supportneedcategoryname
, sn.status AS supportneedstatus
, (
    select coalesce(value->>(lang.value->>0),value->>'en')
    from $dbschm.codes where codeset='supportNeedStatus' and code=sn.status
) AS supportneedstatusname
FROM $dbschm.contact
JOIN $dbschm.contactsurvey cs ON cs.contact = contact.id
JOIN $dbschm.survey ON survey.id = cs.survey
LEFT JOIN $dbschm.supportneed sn ON sn.contact = contact.id AND sn.survey = survey.id
LEFT JOIN $dbschm.config lang ON lang.segment = 'ui' AND lang.field = 'language'
WHERE coalesce(survey.status,'') IN ('FINISHED','IN PROGRESS')
-- last contactsurvey for this contact+survey:
AND (cs.contact,cs.survey,cs.updated) in (
  select contact,survey,max(updated)
  from $dbschm.contactsurvey
  group by contact,survey
)
-- access right for superuser
AND true = (
  select au.superuser
  from $dbschm.annieuser au
  where au.id = ?
)
    ";
    $in_survey = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    //nb! if no survey is given, $in_survey is empty string "" and query returns nothing
    // could check for !empty($in_survey) to enable return of EVERYTHING
    if (isset($in_survey) && !empty($in_survey)) {
      $sql.= " AND survey.id in ($in_survey)";//part of list of strings
    }
    $sqlparams = array($auth_uid);
    if (isset($getarr["survey"]) && !empty($getarr["survey"])) {
      $sqlparams = array_merge($sqlparams,$getarr["survey"]);
    }
    // prepare & execute SQL statement (SELECT)
    $sth = $dbh->prepare($sql);
    $sth->execute($sqlparams);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 1) {
      // not an error but no need to continue
      http_response_code(204); // No Content
      $processok = false;
    }

    if ($processok) {
      // begin next SQL for upserting data
      // nb! select rows loop is below intentionally (but probably not necessarily)!
      $sql = "
INSERT INTO $dbschm.surveystatistics (".implode(",", $datafields).") VALUES
      ";
      $question_marks = array();
      $insert_values = array();
      //-insert preparing

      // loop select query:
      foreach ($rows as $rownum => &$row) { // & for modifying data in-place
        $iv = null;
        if (array_key_exists('iv', $row)) {
          $iv = base64_decode($row['iv']);
          if (array_key_exists('contact', $row)) {
            $contact = json_decode(decrypt($row['contact'],$iv));
            // drop phonenumber, firstname, lastname, birthdate and email
            foreach (array("phonenumber", "firstname", "lastname", "birthdate", "email") as $key) {
              if (array_key_exists($key, $contact)) {
                unset($contact->{$key});
              }
            }
            foreach ($contact as $rk => $rv) {
              $row['contact'.$rk] = $rv;
            }
          }
        }
        unset($row['iv']);
        unset($row['contact']);
        // row now complete

        // make insert values for each row in their own "(?,?,?,...?)"
        $qms = "(";
        foreach ($datafields as $field) {
          $qms.= "?,";
          array_push($insert_values, $row[$field]);
        }
        $qms = substr($qms,0,strlen($qms)-1).")";
        array_push($question_marks, $qms);
      }
      // finalize insert values list "(?,?,?,...?)" + ","
      $sql.=implode(",", $question_marks);
      // make SQL insert to upsert
      $sql.= "
ON CONFLICT ON CONSTRAINT surveystatistics_pk DO UPDATE SET
      ";
      $_comma_count = 0;
      foreach ($datafields as $field) {
        if (!array_key_exists($field,$datafieldkeys)) {
          if (++$_comma_count > 1) $sql.=",";
          $sql.=" $field=EXCLUDED.$field";
        }
      }
      // prepare & execute SQL statement (UPSERT as "insert on conflict do update")
      $sth = $dbh->prepare($sql);
      if (!$sth->execute($insert_values)) {
        error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
        http_response_code(400); // Bad Request
        $processok = false;
      }
    }

    // process archiving if chosen
    // rely on $in_survey and $sqlparams from above
    if ($processok && isset($getarr["archive"]) && !empty($getarr["archive"])) {
      if ($getarr["archive"][0] == "true") { // first one will suffice
        //$in_archive = implode(',', array_fill(0, count($getarr["archive"]), '?'));
        $updatedby = $auth_uid; // okay, but we can do better...
        $sql = "SELECT meta, iv FROM $dbschm.annieuser WHERE id = :annieuser";
        $sth = $dbh->prepare($sql);
        $sth->bindParam(':annieuser', $auth_uid);
        $sth->execute();
        $annieusers = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
        foreach ($annieusers as $au) {
          $aumeta = json_decode(decrypt($au->{'meta'},base64_decode($au->{'iv'})));
          $updatedby = $aumeta->{'firstname'}." ".$aumeta->{'lastname'}." (".$auth_uid.")";
        }

        // UPDATE survey.status
        $sql = "
UPDATE $dbschm.survey SET status='ARCHIVED'
, updatedby='$updatedby', updated=now()
WHERE status NOT IN ('ARCHIVED') -- to not repeat unnecessarily
-- access right for superuser
AND true = (
  select au.superuser
  from $dbschm.annieuser au
  where au.id = ?
)
AND id IN ($in_survey)
        ";
        //already set: $sqlparams
        // prepare & execute SQL statement (UPDATE)
        $sth = $dbh->prepare($sql);
        //to-do-ish: report success/failure
        if (!$sth->execute($sqlparams)) {
          error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
          http_response_code(400); // Bad Request
          $processok = false;
        }

        if ($processok) {
          // DELETE related supportneed rows (history stays)
          $sql = "
DELETE FROM $dbschm.supportneed
WHERE 1=1
-- access right for superuser
AND true = (
  select au.superuser
  from $dbschm.annieuser au
  where au.id = ?
)
AND survey in ($in_survey)
          ";
          $sth = $dbh->prepare($sql);
          if (!$sth->execute($sqlparams)) {
            error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
            http_response_code(400); // Bad Request
            $processok = false;
          }
        }
      }
    }
    if ($processok) {
      http_response_code(200); // OK
    }
    break;
  case 'PUT':
  case 'POST':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    //exit;
    break;
}

// clean up & close
$sth = null;
$dbh = null;

?>