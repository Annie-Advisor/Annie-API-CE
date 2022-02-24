<?php
/* survey-report.php
 * Copyright (c) 2020-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script between AnnieUI and Annie database.
 * Before database there is authentication check.
 *
 * Retrieve "single survey report" data.
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
//later due to choice: header('Content-Type: application/json; charset=utf-8');

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
$returntype = "json"; // alternative is "csv"
$returncsvfile = false;

// get parameters as an array (names here arent mandatory but can be used to force array)
$getarr = array("survey"=>[]);
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
    // + extra for choices
    if (strtolower($name)=="returntype" && strtolower($value)=="csv") {
      $returntype = "csv";
      $returncsvfile = false;
    }
    if (strtolower($name)=="returntype" && strtolower($value)=="csvfile") {
      $returntype = "csv";
      $returncsvfile = true;
    }
  }
}

if ($returntype=="csv") {
  if ($returncsvfile) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-disposition: attachment; filename=survey-report.csv");
  } else {
    header('Content-Type: text/plain; charset=utf-8');
  }
} else {
  header('Content-Type: application/json; charset=utf-8');
}

//
//
//

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
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
, sn.category AS supportneedcategory
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
LEFT JOIN $dbschm.config lang ON lang.segment = 'ui' and lang.field = 'language'
WHERE coalesce(survey.status,'') IN ('FINISHED','IN PROGRESS')
-- last contactsurvey for this contact+survey:
AND (cs.contact,cs.survey,cs.updated) in (
  select contact,survey,max(updated)
  from $dbschm.contactsurvey
  group by contact,survey
)
-- access right for superuser
and true = (
  select au.superuser
  from $dbschm.annieuser au
  where au.id = ?
)
    ";
    $in_survey = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    //nb! if no survey is given, $in_survey is empty string "" and query returns nothing
    // could check for !empty($in_survey) to enable return of EVERYTHING
    if (isset($in_survey)) {
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
    foreach ($rows as $rownum => &$row) { // & for modifying data in-place
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
        if (array_key_exists('contact', $row)) {
          $row['contactmeta'] = json_decode(decrypt($row['contact'],$iv));
        }
      }
      unset($row['iv']);
      unset($row['contact']);
    }

    if ($returntype == "csv") {
      // columns
      $csvcolumns = array(
        "surveyid","surveyname","surveystarttime","surveyendtime",
        "contactid",
        "contactphonenumber","contactfirstname","contactlastname","contactbirthdate","contactemail",
        "contactdegree","contactgroup","contactlocation","contactcustomtext","contactcustomkey",
        "delivered","responded","responsetime","supportneed","remindercount","messagesreceived","messagessent",
        "supportneedcategory","supportneedcategoryname","supportneedstatus","supportneedstatusname"
      );

      // collect csv data into memory (to use fputcsv)
      $f = fopen('php://memory', 'r+');
      //add BOM to fix UTF-8 in Excel
      fputs($f, $bom=( chr(0xEF) . chr(0xBB) . chr(0xBF) ));

      // CSV header
      fputcsv($f, $csvcolumns, $separator=";");

      foreach ($rows as $rownum => &$row) {//is "&" really needed?
        if (array_key_exists('contactmeta', $row)) {
          foreach ($row['contactmeta'] as $rk => $rv) {
            $row['contact'.$rk] = $rv;
          }
        }
        // make sure printed row has all the columns and in correct order
        $csvline = array();
        foreach ($csvcolumns as $cn => $ck) {
          $csvline[$ck] = $row[$ck];
        }
        // store the CSV line
        fputcsv($f, $csvline, $separator=";");
      }
      // CSV output finally
      rewind($f);
      echo rtrim(stream_get_contents($f));
    } else {
      echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }
    http_response_code(200); // OK
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