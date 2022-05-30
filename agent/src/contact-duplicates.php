<?php
/* contact-duplicates.php
 * Copyright (c) 2021-2022 Annie Advisor
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
      "description" => "Check for contact having their phonenumber appearing more than once. Ids contains contact.id values.",
      "problem_count" => 0,
      "count" => 0,
      "ids" => array(),
    );

    $count = 0;
    $problem_count = 0;

    // loop twice to discover multiple occurences
    // first just gather data into a map
    $contactidphonenumber = array();

    $sql = "SELECT id,contact,iv FROM $dbschm.contact ";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { // & for modifying data in-place
      $count++;
      $id = $row['id'];
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
        if (array_key_exists('contact', $row)) {
          $contact = json_decode(decrypt($row['contact'],$iv));
          foreach ($contact as $rk => $rv) {
            $row[$rk] = $rv;
          }
        }
      }
      unset($row['iv']);
      unset($row['contact']);
      // row now complete

      if (array_key_exists('phonenumber', $row)) {
        $contactidphonenumber[$id] = $row['phonenumber'];
      }
    }

    // second loop with sorted data and start finding...
    asort($contactidphonenumber);
    $previd = null;
    $prevphonenumber = null;
    foreach ($contactidphonenumber as $id => $phonenumber) {
      // is there a leading "+"? (14.2.2022 discovered from history)
      if (substr($phonenumber, 0, 1) !== '+') {
        $phonenumber = '+'.$phonenumber;
      }
      if (!isset($prevphonenumber)) {
        $previd = $id;
        $prevphonenumber = $phonenumber;
      } else {
        if ($phonenumber) {//is worthy
          if ($prevphonenumber === $phonenumber) {
            //printf("DUPLICATE: $phonenumber with ids $previd and $id".PHP_EOL);
            array_push($result->ids, $previd);
            array_push($result->ids, $id);
            $problem_count++;
          }
          $previd = $id;
          $prevphonenumber = $phonenumber;
        }
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