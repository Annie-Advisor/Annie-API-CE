<?php
/*
 * Statistics for Annie.
 * During survey
 */

require_once 'my_app_specific_library_dir/settings.php';//->$settings,$db*

$validated = false;

if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
  $user = $_SERVER['PHP_AUTH_USER'];
  $pass = $_SERVER['PHP_AUTH_PW'];

  $validated = ($user == $settings['api']['user'] && $pass == $settings['api']['pass']);
}

if (!$validated) {
  //header('HTTP/1.0 401 Unauthorized');
  //die ("Not authorized");
  require_once 'my_app_specific_library_dir/auth.php';//->$as,$auth
}

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET';//, PUT, POST, DELETE';
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

//
//
//

//* dbh
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
// - dbh */

$survey = "0";
if (isset($_GET["survey"])) {
  $survey = htmlspecialchars($_GET["survey"]);
}
$inmins = 5;
if (isset($_GET["inmins"])) {
  $inmins = htmlspecialchars($_GET["inmins"]);
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    $result = new stdClass();
    $result->{"meta"} = array(
      'survey' => $survey,
      'inmins' => $inmins
    );
    $result->{"annie"} = array();

    $dbh->exec('SET search_path TO ' . $dbschm);
    // NB! SCHEMA (search_path) MUST BE SET BEFORE:
    $sql = "
with recursive
param as (
    select :survey as survey, 5 as inmins
)
, init as (
    select param.survey
    , param.inmins
    , coalesce(realtime.surveystart,survey.starttime) as surveystart
    , coalesce(realtime.surveystart,survey.starttime) as timelinestart
    , coalesce(realtime.surveystart,survey.starttime) + make_interval(mins := inmins) as timelineend
    , coalesce(realtime.surveyend,survey.endtime) as surveyend
    from param
    join survey on survey.id = param.survey
    left join (
        select survey
        , min(updated) surveystart
        , max(updated) surveyend
        from contactsurvey
        group by survey
    ) realtime on realtime.survey=survey.id
)
, timeline (surveystart,timelinestart,timelineend,surveyend,inmins,survey) as (
    select surveystart
    , timelinestart
    , surveystart + make_interval(mins := inmins) as timelineend
    , surveyend
    , inmins
    , survey
    from init
    union all
    select surveystart
    , timelineend as timelinestart
    , timelineend + make_interval(mins := inmins) as timelineend
    , surveyend
    , inmins
    , survey
    from timeline
    where timelineend < surveyend
)
select
surveystart,timelinestart,
cast(
     date_part('day',(timelinestart - surveystart))*24*60
    +date_part('hour',(timelinestart - surveystart))*60
    +date_part('minute',(timelinestart - surveystart))
    as int)
as minutes_since_survey_sent
,(
    select count(distinct contact)
    from contactsurvey cs
    where cs.survey = timeline.survey
    and cs.updated < timeline.timelineend
    and (cs.survey,cs.contact,cs.updated) in (
        select cs2.survey,cs2.contact,max(cs2.updated)
        from contactsurvey cs2
        where cs2.updated <= timeline.timelineend
        group by cs2.survey,cs2.contact
    )
    and cs.status = '100'
    and cs.contact not in (
        select s.contact from supportneedhistory s
        where s.survey = timeline.survey
        and s.updated < timeline.timelineend
    )
)
as no_support_needed
,(
    select count(distinct s.contact)
    from supportneedhistory s
    where s.survey = timeline.survey
    and s.updated < timeline.timelineend
    and s.status = '1'
    and (s.contact,s.survey) not in (
        select ss.contact,ss.survey
        from supportneedhistory ss
        where ss.survey = timeline.survey
        and ss.updated < timeline.timelineend
        and ss.status = '100'
    )
)
as support_needed___new
,(
    select count(distinct s.contact)
    from supportneedhistory s
    where s.survey = timeline.survey
    and s.updated < timeline.timelineend
    and s.status = '100'
)
as support_needed___resolved
,(
    select count(distinct cs.contact)
    from contactsurvey cs
    where cs.survey = timeline.survey
    and cs.updated < timeline.timelineend
    and (cs.survey,cs.contact,cs.updated) in (
        select cs2.survey,cs2.contact,max(cs2.updated)
        from contactsurvey cs2
        where cs2.updated < timeline.timelineend
        group by cs2.survey,cs2.contact
    )
    and cs.status in ('1','2')
)
as no_response
from timeline
order by minutes_since_survey_sent asc
    ";
    // prepare SQL statement
    //$result->{"meta"}["sql"] = $sql;
    $sth = $dbh->prepare($sql);
    // excecute SQL statement, convert to associative array for named placeholders
    //$sth->execute(array(":survey" => $survey, ":inmins" => $inmins));
    $sth->bindParam(':survey', $survey);
    //$sth->bindParam(':inmins', $inmins, PDO::PARAM_INT);
    $sth->execute();
    //$result->{"annie"} = $sth->fetchAll(PDO::FETCH_ASSOC);
    //*
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => $row) {
      $result->{"annie"}[] = array_merge(
        // value w/ title & description
        array("surveystart" => array(
          "value" => $row["surveystart"],
          "title" => "Kyselykierroksen alku",
          "description" => "..."
        )),
        array("timelinestart" => array(
          "value" => $row["timelinestart"],
          "title" => "Aikajakson alku",
          "description" => "..."
        )),
        array("minutes_since_survey_sent" => array(
          "value" => $row["minutes_since_survey_sent"],
          "title" => "Minuutteja alusta",
          "description" => "Kuinka paljon aikaa on kulunut ko. survey_id:n mukaisen survey-taulun rivin starttime -tiedosta"
        )),
        array("no_support_needed" => array(
          "value" => $row["no_support_needed"],
          "title" => "Ei tuentarpeita",
          "description" => "Kyseiseen survey_id:hen liittyvät opiskelijat joilla flow mennyt loppuun (contactsurvey status 100) ja ei tehty supportneediä"
        )),
        array("support_needed___new" => array(
          "value" => $row["support_needed___new"],
          "title" => "Uusia tuentarpeita",
          "description" => "Kyseiseen survey_id:hen liittyvät opiskelijat joille tehty supportneed ja supportneed status 1"
        )),
        array("support_needed___resolved" => array(
          "value" => $row["support_needed___resolved"],
          "title" => "Ratkaistuja tuentarpeita",
          "description" => "Kyseiseen survey_id:hen liittyvät opiskelijat joille tehty supportneed ja supportneed status 100"
        )),
        array("no_response" => array(
          "value" => $row["no_response"],
          "title" => "Ei vastausta",
          "description" => "Kyseiseen survey_id:hen liittyvät opiskelijat jotka eivät ole vielä vastanneet (viimeisin contactsurvey status 1 tai 2)"
        ))
      );
    }
    //*/

    // print results
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
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