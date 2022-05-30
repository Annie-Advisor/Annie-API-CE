<?php
/* survey-report.php
 * Copyright (c) 2020-2022 Annie Advisor
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
, contact.optout AS contactoptout
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
    select value->>'title'
    from $dbschm.codes where codeset='category' and code=sn.category
) AS supportneedcategoryname
, sn.status AS supportneedstatus
, sn.supporttype
, sn.followuptype
, sn.followupresult
--
-- Duration
, (
    select round(cast(extract(epoch from (min(created) - survey.starttime))/60 as numeric), 2)
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
) AS dur_firstresponse -- duration (in minutes) from message sent to first response
, (
    select round(cast(extract(epoch from (cs100.updated - min(firstresponse.created)))/60 as numeric), 2)
    from $dbschm.message firstresponse
    join $dbschm.contactsurvey cs100 on cs100.status = '100'
        and cs100.contact = contact.id and cs100.survey = survey.id
    where firstresponse.contact = contact.id
    and firstresponse.survey = survey.id
    and firstresponse.status = 'RECEIVED' --from contact
    and (firstresponse.contact,firstresponse.survey,firstresponse.status,firstresponse.created) in (--first one in this survey
        select m.contact,m.survey,m.status,min(m.created)
        from $dbschm.message m
        where m.contact = firstresponse.contact
        and m.survey = firstresponse.survey
        and m.status = firstresponse.status
        group by m.contact,m.survey,m.status
    )
    and (cs100.contact,cs100.survey,cs100.status,cs100.updated) in (--first one with '100'
        select contact,survey,status,min(updated) --nb!
        from $dbschm.contactsurvey
        group by contact,survey,status --nb!
    )
    group by firstresponse.contact,firstresponse.survey
    , cs100.updated --this is one way...
) AS dur_surveyflow -- duration (in minutes) from first response to the response that sets contactsurvey to 100
, (
    select round(cast(extract(epoch from (sn2.updated - min(request.updated)))/60 as numeric), 2)
    from $dbschm.supportneed request
    join $dbschm.supportneed sn2 on sn2.status = 'OPENED'
        and sn2.contact = contact.id and sn2.survey = survey.id
    where request.contact = contact.id
    and request.survey = survey.id
    and request.status = 'NEW'
    and (request.contact,request.survey,request.status,request.id) in (--first one with 'NEW'
        select contact,survey,status,min(id) --nb!
        from $dbschm.supportneed
        group by contact,survey,status --nb!
    )
    and (sn2.contact,sn2.survey,sn2.status,sn2.id) in (--first one with 'OPENED'
        select contact,survey,status,min(id) --nb!
        from $dbschm.supportneed
        group by contact,survey,status --nb!
    )
    group by request.contact,request.survey
    , sn2.updated --this is one way...
) AS dur_requestseen -- duration (in minutes) between supportneed creation and supportneed status OPENED
, (
    select round(cast(extract(epoch from (sn100.updated - min(request.updated)))/60 as numeric), 2)
    from $dbschm.supportneed request
    join $dbschm.supportneed sn100 on sn100.status = 'ACKED'
        and sn100.contact = contact.id and sn100.survey = survey.id
    where request.contact = contact.id
    and request.survey = survey.id
    and request.status = 'NEW'
    and (request.contact,request.survey,request.status,request.id) in (--first one with 'NEW'
        select contact,survey,status,min(id) --nb!
        from $dbschm.supportneed
        group by contact,survey,status --nb!
    )
    and (sn100.contact,sn100.survey,sn100.status,sn100.id) in (--first one with 'ACKED'
        select contact,survey,status,min(id) --nb!
        from $dbschm.supportneed
        group by contact,survey,status --nb!
    )
    group by request.contact,request.survey
    , sn100.updated --this is one way...
) AS dur_requestacked -- duration (in minutes) between supportneed creation and supportneed status ACKED
, (
    select round(cast(extract(epoch from (fu1.updated - min(sn100.updated)))/60 as numeric), 2)
    from $dbschm.supportneed sn100
    join $dbschm.followup fu1 on fu1.status = '1'
    where sn100.contact = contact.id
    and sn100.survey = survey.id
    and sn100.status = 'ACKED'
    and (sn100.contact,sn100.survey,sn100.status,sn100.id) in (--first one with 'ACKED'
        select contact,survey,status,min(id) --nb!
        from $dbschm.supportneed
        group by contact,survey,status --nb!
    )
    -- followup
    and fu1.supportneed in (
        select id
        from $dbschm.supportneed reqchain
        where (reqchain.contact,reqchain.survey) in (
            select contact,survey
            from $dbschm.supportneed
            where id = sn100.id
        )
    )
    and (fu1.supportneed,fu1.status,fu1.id) in (--first one with '1'
        select supportneed,status,min(id) --nb!
        from $dbschm.followup
        group by supportneed,status --nb!
    )
    group by sn100.contact,sn100.survey
    , fu1.updated --this is one way...
) AS dur_ackedfollowup -- duration (in minutes) between support need status ACKED and followup start (in case followup is started before supportneed is acked this can be negative and it’s ok)
, (
    select round(cast(extract(epoch from (fu100.updated - min(fu1.updated)))/60 as numeric), 2)
    from $dbschm.followup fu1
    join $dbschm.followup fu100 on fu100.status = '100'
    where fu1.supportneed in (
        select id
        from $dbschm.supportneed reqchain
        where reqchain.contact = contact.id
        and reqchain.survey = survey.id
    )
    and fu1.status = '1'
    and (fu1.supportneed,fu1.status,fu1.id) in (--first one with '1'
        select supportneed,status,min(id) --nb!
        from $dbschm.followup
        group by supportneed,status --nb!
    )
    and fu100.supportneed = fu1.supportneed --nb!
    and (fu100.supportneed,fu100.status,fu100.id) in (--first one with '100'
        select supportneed,status,min(id) --nb!
        from $dbschm.followup
        group by supportneed,status --nb!
    )
    group by fu1.supportneed
    , fu100.updated --this is one way...
) AS dur_followupflow -- duration (in minutes) between followup start and followup complete
--
-- TIME
, (
    select min(created)
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
) AS time_firstresponse
, (
    select min(sn100.updated)
    from $dbschm.supportneed sn100
    where sn100.status = 'ACKED'
    and sn100.contact = contact.id and sn100.survey = survey.id
    and (sn100.contact,sn100.survey,sn100.status,sn100.id) in (--first one with 'ACKED'
        select contact,survey,status,min(id) --nb!
        from $dbschm.supportneed
        group by contact,survey,status --nb!
    )
    group by sn100.contact,sn100.survey
) AS time_requestacked
, (
    select min(fu100.updated)
    from $dbschm.followup fu100
    where fu100.status = '100' --complete
    and fu100.supportneed in ( --any in the chain
        select id
        from $dbschm.supportneed reqchain
        where reqchain.contact = contact.id
        and reqchain.survey = survey.id
    )
    and (fu100.supportneed,fu100.status,fu100.id) in (--first one with 100
        select supportneed,status,min(id) --nb!
        from $dbschm.followup
        group by supportneed,status --nb!
    )
    group by fu100.supportneed
) AS time_followup
--
--
FROM $dbschm.contact
JOIN $dbschm.contactsurvey cs ON cs.contact = contact.id
JOIN $dbschm.survey ON survey.id = cs.survey
LEFT JOIN $dbschm.supportneed sn ON sn.contact = contact.id AND sn.survey = survey.id
  -- last supportneed for this contact+survey
  AND sn.id IN (
      select max(id) from $dbschm.supportneed
      where contact = sn.contact and survey = sn.survey
  )
WHERE coalesce(survey.status,'') IN ('FINISHED','IN PROGRESS')
-- last contactsurvey for this contact+survey
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
  and coalesce(au.validuntil,'9999-09-09') > now()
)
    ";
    $in_survey = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    //nb! for test isset($in_survey) if no survey is given, $in_survey is empty string "" and query returns nothing
    // could check for !empty($in_survey) to enable return of EVERYTHING
    if (!empty($in_survey)) {
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
        "contactoptout",
        "delivered","responded","supportneed","remindercount","messagesreceived","messagessent",
        "supportneedcategory","supportneedcategoryname","supportneedstatus",
        "supporttype","followuptype","followupresult",
        "dur_firstresponse",
        "dur_surveyflow","dur_requestseen","dur_requestacked",
        "dur_ackedfollowup","dur_followupflow",
        "time_firstresponse","time_requestacked","time_followup"
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