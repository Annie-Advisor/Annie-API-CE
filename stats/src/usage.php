<?php
/*
 * Statistics for Annie.
 * Usage
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

$startdate = (new DateTime("first day of last month"))->format("Y-m-d");
$enddate = (new DateTime("last day of last month"))->format("Y-m-d");
if (isset($_GET["startdate"])) {
  $startdate = htmlspecialchars($_GET["startdate"]);
}
if (isset($_GET["enddate"])) {
  $enddate = htmlspecialchars($_GET["enddate"]);
}
// GET argument will be converted to a DateInterval object
$interval = '1 week';
if (isset($_GET["interval"])) {
  $interval = urldecode(htmlspecialchars($_GET["interval"]));
}
$my_interval = DateInterval::createFromDateString($interval);

// create SQL based on HTTP method
switch ($method) {
  case 'GET':
    $result = new stdClass();

    $period = new DatePeriod(new DateTime($startdate), $my_interval, new DateTime($enddate));

    foreach ($period as $dt) {
      // -1 day for query
      $de = (new DateTime($dt->format("Y-m-d")))->add($my_interval)->sub(new DateInterval('P1D'));

      //echo $dt->format("l Y-m-d H:i:s\n");
      $dbh->exec('SET search_path TO ' . $dbschm);
      // NB! SCHEMA (search_path) MUST BE SET BEFORE:
      $sql = "
        SELECT
          (select count(distinct updatedby) from supportneedhistory where updatedby not in ('Annie','Annie.') and updated between :startdate and :enddate) AS active_users
          ,(select count(*)
              from supportneedhistory a
              where updated <= (
                  select min(b.updated)
                  from supportneedhistory b
                  where b.contact=a.contact and b.survey=a.survey --and b.category=a.category
                  and b.updated between :startdate and :enddate
                  group by contact,survey--,category
              )
          ) AS new_supportneeds
          ,(select count(*) from message where status!='RECEIVED' and updated between :startdate and :enddate) AS messages_sent
          ,(select count(*) from message where status ='RECEIVED' and updated between :startdate and :enddate) AS messages_received
          ,(select count(distinct contact) from message where status!='RECEIVED' and updated between :startdate and :enddate) AS recipient_count
          ,(select count(distinct contact) from message where status ='RECEIVED' and updated between :startdate and :enddate) AS sender_count
          ,(select count(*) from supportneed where status='100' and updated between :startdate and :enddate) AS resolved_supportneeds
      ";
      // prepare SQL statement
      $sth = $dbh->prepare($sql,array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
      // excecute SQL statement
      // convert to associative array for named placeholders
      $sth->execute(array(":startdate" => $dt->format('Y-m-d'), ":enddate" => $de->format('Y-m-d')));

      $sqlresult = $sth->fetchAll(PDO::FETCH_ASSOC)[0];// return 1 (1st) row always (no list)
      $result->{"annie"}[] = array_merge(
        array(
          'startdate' => $dt->format('Y-m-d'),
          'enddate' => $de->format('Y-m-d')
        ),
        // value w/ title & description
        array("active_users" => array(
          "value" => $sqlresult["active_users"],
          "title" => "Aktiivisia käyttäjiä",
          "description" => "Lukumäärä eri käyttäjistä, jotka ovat käsitelleet ratkaistavaa asiaa"
        )),
        array("new_supportneeds" => array(
          "value" => $sqlresult["new_supportneeds"],
          "title" => "Uusia ratkaistavia asioita",
          "description" => "Ratkaistavien asioiden lukumäärä jotka ovat tallennushistorian ensimmäinen ilmentymä"
        )),
        array("messages_sent" => array(
          "value" => $sqlresult["messages_sent"],
          "title" => "Lähetettyjä tekstiviestejä",
          "description" => "Viestien lukumäärä, joissa lähettäjä on Annie"
        )),
        array("messages_received" => array(
          "value" => $sqlresult["messages_received"],
          "title" => "Vastaanotettuja tekstiviestejä",
          "description" => "Viestien lukumäärä, joissa lähettäjä ei ole Annie"
        )),
        array("recipient_count" => array(
          "value" => $sqlresult["recipient_count"],
          "title" => "Vastaanottajia",
          "description" => "Lukumäärä eri kontakteista, joissa lähettäjä on Annie"
        )),
        array("sender_count" => array(
          "value" => $sqlresult["sender_count"],
          "title" => "Lähettäjiä",
          "description" => "Lukumäärä eri kontakteista, joissa lähettäjä ei ole Annie"
        )),
        array("resolved_supportneeds" => array(
          "value" => $sqlresult["resolved_supportneeds"],
          "title" => "Ratkaistuja asioita",
          "description" => "Ratkaistujen asioiden lukumäärä"
        ))
      );
    }

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