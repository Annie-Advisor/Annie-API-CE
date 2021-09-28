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
 * Retrieve "single survey report" data. AD-181
 */

require_once('/opt/annie/settings.php');//->settings,db*
require_once('/opt/annie/auth.php');

require_once('/opt/annie/anniedb.php');
//$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
//* dbh for specialized queries
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
// - dbh */

require '/opt/annie/http_response_code.php';

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

$cipher = "aes-256-cbc";
function encrypt($string,$iv) {
  global $cipher, $salt;
  $output = false;
  if (in_array($cipher, openssl_get_cipher_methods())) {
    $output = openssl_encrypt($string, $cipher, $salt, $options=0, $iv);
  }
  return $output;
}
function decrypt($string,$iv) {
  global $cipher, $salt;
  $output = false;
  if (in_array($cipher, openssl_get_cipher_methods())) {
    $output = openssl_decrypt($string, $cipher, $salt, $options=0, $iv);
  }
  return $output;
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    // NB! SCHEMA (search_path) MUST BE SET BEFORE:
    $sql = "
SELECT survey.id AS surveyid
, survey.config#>>'{name,fi}' AS surveyname
, survey.starttime AS surveystarttime
, survey.endtime AS surveyendtime
, contact.id AS contactid, contact.contact, contact.iv
, null AS contactmeta --placeholder for decrypted contact data
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
    select ceil(extract(epoch from (created - survey.starttime))/60)::int
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
, (select value from $dbschm.codes where codeset='category' and code=sn.category) AS supportneedcategoryname
, sn.status AS supportneedstatus
, (select value from $dbschm.codes where codeset='supportNeedStatus' and code=sn.status) AS supportneedstatusname
FROM $dbschm.contact
JOIN $dbschm.contactsurvey cs ON cs.contact = contact.id
JOIN $dbschm.survey ON survey.id = cs.survey
LEFT JOIN $dbschm.supportneed sn ON sn.contact = contact.id AND sn.survey = survey.id
WHERE coalesce(survey.status,'') IN ('FINISHED','IN PROGRESS')
-- last contactsurvey for this contact+survey:
AND (cs.contact,cs.survey,cs.updated) in (
  select contact,survey,max(updated)
  from $dbschm.contactsurvey
  group by contact,survey
)
-- access right for superuser
and (
  select au.superuser
  from $dbschm.annieuser au
  where au.id = ?
)
    ";
    $in_survey = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    if (isset($in_survey)) {
      $sql.= " AND survey.id in ($in_survey)";//part of list of strings
    }
    $sqlparams = array($auth_uid);
    if (isset($getarr["survey"]) && !empty($getarr["survey"])) {
      $sqlparams = array_merge($sqlparams,$getarr["survey"]);
    }
    // prepare & execute SQL statement
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
      // jsonify some
      $row['supportneedcategoryname'] = json_decode($row['supportneedcategoryname']);
      $row['supportneedstatusname'] = json_decode($row['supportneedstatusname']);
    }

    if ($returntype == "csv") {
      // columns
      $csvcolumns = array(
        "surveyid","surveyname","surveystarttime","surveyendtime",
        "contact.id","contact.phonenumber","contact.firstname","contact.lastname","contact.birthdate","contact.email","contact.degree","contact.group","contact.location","contact.customtext","contact.customkey",
        "delivered","responded","responsetime","supportneed","remindercount","messagesreceived","messagessent",
        "supportneedcategory.fi","supportneedcategory.en","supportneedstatus.fi","supportneedstatus.en"
      );
      // CSV header
      echo implode(";", $csvcolumns);
      echo "\n";

      foreach ($rows as $rownum => &$row) {//is "&" really needed?
        if (array_key_exists('contactid', $row)) {
          $row['contact.id'] = $row['contactid'];
        }
        if (array_key_exists('contactmeta', $row)) {
          foreach ($row['contactmeta'] as $rk => $rv) {
            $row['contact.'.$rk] = $rv;
          }
        }
        if (array_key_exists('supportneedcategoryname', $row)) {
          foreach ($row['supportneedcategoryname'] as $rk => $rv) {
            $row['supportneedcategory.'.$rk] = $rv;
          }
        }
        if (array_key_exists('supportneedstatusname', $row)) {
          foreach ($row['supportneedstatusname'] as $rk => $rv) {
            $row['supportneedstatus.'.$rk] = $rv;
          }
        }
        // make sure printed row has all the columns and in correct order
        foreach ($csvcolumns as $cn => $ck) {
          if (array_key_exists($ck, $row)) {
            echo $row[$ck];
          }
          echo ";";
        }
        //echo implode(";", $row)."\n";
        echo "\n";
      }
      //print_r($rows);
    } else {
      echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }
    break;
  case 'PUT':
  case 'POST':
  case 'DELETE':
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

// clean up & close
$sth = null;
$dbh = null;

http_response_code(200); // OK
?>