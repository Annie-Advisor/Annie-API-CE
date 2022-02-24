<?php
/*
 * Statistics for Annie.
 * Survey results
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

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    // NB! SCHEMA (search_path) MUST BE SET BEFORE:
    $sql = "
select co.id as contactid
, co.contact, co.iv
, su.id as surveyid
, su.config->>'title' as surveyname
, sn.category as supportneedcategory
, (select value->>'fi' from $dbschm.codes where codeset='category' and code=sn.category) as categoryname
, sn.status as supportneedstatus
from $dbschm.contact co
join $dbschm.contactsurvey cs on cs.contact = co.id
join $dbschm.survey su on su.id = cs.survey
left join $dbschm.supportneed sn on sn.contact = co.id and sn.survey = su.id
    ";
    // prepare SQL statement
    $sth = $dbh->prepare($sql);
    // excecute SQL statement, convert to associative array for named placeholders
    //$sth->execute(array(":survey" => $survey, ":inmins" => $inmins));
    //$sth->bindParam(':survey', $survey);
    //$sth->bindParam(':inmins', $inmins, PDO::PARAM_INT);
    $sth->execute();
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) {
      $iv = null;
      $contact = null; //contact crypted data decrypted
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);

        if (array_key_exists('contact', $row)) {
          $contact = json_decode(decrypt($row['contact'],$iv));
          $row['degree'] = $contact->{'degree'};
          $row['group'] = $contact->{'group'};
          $row['location'] = $contact->{'location'};
        }
      }
      unset($row['iv']);
      unset($row['contact']);
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
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