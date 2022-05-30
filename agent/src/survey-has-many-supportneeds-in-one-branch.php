<?php
/* survey-has-many-supportneeds-in-one-branch.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Check up on data.
 * NB! No authentication. IP restricted!
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
/* + db for specialized query */
$dbh = $anniedb->getDbh();
/* - db */

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET'; //POST, PUT, DELETE
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
$input = json_decode(file_get_contents('php://input'));

$key = null;
if (count($request)>=1) {
  $key = array_shift($request);
}

// create SQL based on HTTP method
switch ($method) {
  case 'GET':

    $result = (object)array(
      "description" => "Check for survey config having branches with many supportneed=true. Objects contains survey.id => Jsonpath => count values.",
      "problem_count" => 0,
      "count" => 0,
      "objects" => (object)array(),
    );

    $count = 0;
    $problem_count = 0;

    $sql = "
    WITH RECURSIVE survey_config_recursive(surveyid, path, parent, key, value, supportneedcount) AS (
      SELECT su.id as surveyid
      , '$.'||t.key as path
      , '$.' as parent
      , t.key
      , t.value
      , case when (t.value->'supportneed')::boolean then 1 else 0 end supportneedcount
      FROM $dbschm.survey su
      cross join jsonb_each(su.config) AS t
      where su.endtime > now()

      UNION ALL

      SELECT surveyid
      , survey_config_recursive.path||'.'||t.key as path
      , survey_config_recursive.path as parent
      , t.key
      , t.value
      , supportneedcount + case when (t.value->'supportneed')::boolean then 1 else 0 end supportneedcount
      FROM survey_config_recursive
      cross join jsonb_each(
        CASE
        WHEN jsonb_typeof(survey_config_recursive.value) <> 'object' THEN '{}' :: JSONB
        ELSE survey_config_recursive.value
        END
      ) AS t
    )
    select surveyid
    , path
    , supportneedcount
    from survey_config_recursive
    where key like 'branch%'
    ";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => $row) {
      $count++;
      // check data for warning
      if ($row['supportneedcount'] > 1) {
        if (!isset($result->objects->{$row['surveyid']})) {
          $result->objects->{$row['surveyid']} = (object)array();
        }
        $result->objects->{$row['surveyid']}->{$row['path']} = $row['supportneedcount'];
        $problem_count++;
      }
    }

    $result->problem_count = $problem_count;
    $result->count = $count;

    if ($problem_count) {
      http_response_code(409); // Conflict
    } else {
      http_response_code(200); // OK
    }
    echo json_encode($result, JSON_PRETTY_PRINT);

    break;
  case 'POST':
  case 'PUT':
  case 'DELETE':
    http_response_code(405); // Method Not Allowed
    break;
}

// clean up & close
$sth = null;
$dbh = null;

// no end tag to interrupt output