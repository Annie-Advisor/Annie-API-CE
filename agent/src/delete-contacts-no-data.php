<?php
/* delete-contacts-no-data.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Agent for instance data.
 * "delete students with no links to other data"
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/auth.php';//auth_uid

require_once 'my_app_specific_library_dir/anniedb.php';
//$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
/* + db for specialized query */
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
/* - db */

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, POST'; //GET, PUT, DELETE
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
  case 'POST':
    $total_row_count = 0;
    
    // this should suffice alone due to "on delete cascade" foreign key constraints
    $sql = "
    DELETE
    FROM $dbschm.contact
    WHERE id NOT IN (
      select contact from $dbschm.message
      union
      select contact from $dbschm.contactsurvey
      union
      select contact from $dbschm.supportneed
      union
      select suco.contact
      from $dbschm.survey
      cross join jsonb_array_elements_text(survey.contacts) suco(contact)
      where coalesce(survey.contacts,'null') != 'null' --json null is string
    )
    -- access right for superuser
    and true = (
      select au.superuser
      from $dbschm.annieuser au
      where au.id = :annieuser
      and coalesce(au.validuntil,'9999-09-09') > now()
    )
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':annieuser', $auth_uid);
    // check success
    if ($sth->execute()) {
      $total_row_count += $sth->rowCount();
      http_response_code(200);
      echo json_encode(array("status"=>"OK", "message"=>"$total_row_count rows deleted"));
    } else {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"database problem"));
    }
    break;
  case 'GET':
  case 'PUT':
  case 'DELETE':
    http_response_code(405); // Method Not Allowed
    break;
}

// clean up & close
$sth = null;
$dbh = null;

?>