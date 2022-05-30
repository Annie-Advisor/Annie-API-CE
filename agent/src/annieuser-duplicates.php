<?php
/* annieuser-duplicates.php
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
      "description" => "Check for annieuser having their userid / phonenumber / email appearing more than once. Lists contains annieuser.id values.",
      "problem_count" => 0,
      "count" => 0,
      "userids" => (object)array(),
      "phonenumbers" => (object)array(),
      "emails" => (object)array(),
    );

    $count = 0;
    $problem_count = 0;

    // keep track of seen values to discover multiple occurences
    $useridid = array();
    $phonenumberid = array();
    $emailid = array();

    $sql = "SELECT id,meta,iv FROM $dbschm.annieuser ";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { // & for modifying data in-place
      $count++;
      $id = $row['id'];
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
        if (array_key_exists('meta', $row)) {
          $annieusermeta = json_decode(decrypt($row['meta'],$iv));
          foreach ($annieusermeta as $rk => $rv) {
            $row[$rk] = $rv;
          }
        }
      }
      unset($row['iv']);
      unset($row['meta']);
      // row now complete

      if (array_key_exists('userid', $row)) {
        $row['userid'] = strtolower($row['userid']);
        if (array_key_exists($row['userid'], $useridid)) {
          if (!isset($result->userids->{$row['userid']})) {
            $result->userids->{$row['userid']} = array();
          }
          array_push($result->userids->{$row['userid']}, $useridid[$row['userid']]);
          array_push($result->userids->{$row['userid']}, $id);
          $problem_count++;
        }
        $useridid[$row['userid']] = $id;
      }

      if (array_key_exists('phonenumber', $row)) {
        // is there a leading "+"? (14.2.2022 discovered from history)
        if (substr($row['phonenumber'], 0, 1) !== '+') {
          $row['phonenumber'] = '+'.$row['phonenumber'];
        }
        if (array_key_exists($row['phonenumber'], $phonenumberid)) {
          if (!isset($result->phonenumbers->{$row['phonenumber']})) {
            $result->phonenumbers->{$row['phonenumber']} = array();
          }
          array_push($result->phonenumbers->{$row['phonenumber']}, $phonenumberid[$row['phonenumber']]);
          array_push($result->phonenumbers->{$row['phonenumber']}, $id);
          $problem_count++;
        }
        $phonenumberid[$row['phonenumber']] = $id;
      }
      if (array_key_exists('email', $row)) {
        $row['email'] = strtolower($row['email']);
        // is email valid?
        if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
          if (array_key_exists($row['email'], $emailid)) {
            if (!isset($result->emails->{$row['email']})) {
              $result->emails->{$row['email']} = array();
            }
            array_push($result->emails->{$row['email']}, $emailid[$row['email']]);
            array_push($result->emails->{$row['email']}, $id);
            $problem_count++;
          }
          $emailid[$row['email']] = $id;
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