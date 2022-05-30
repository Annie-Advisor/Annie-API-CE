<?php
/* delete-contact.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Agent for instance data.
 * "delete all data from a given student"
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
    if ($key) {
      $contactid = $key;

      $total_row_count = 0;

      //* separately -- or via "on delete cascade" below?
      $sql = "
      DELETE
      FROM $dbschm.message
      WHERE contact = :contact
      -- access right for superuser
      and true = (
        select au.superuser
        from $dbschm.annieuser au
        where au.id = :annieuser
        and coalesce(au.validuntil,'9999-09-09') > now()
      )
      ";
      $sth = $dbh->prepare($sql);
      $sth->bindParam(':contact', $contactid);
      $sth->bindParam(':annieuser', $auth_uid);
      $sth->execute();
      $total_row_count += $sth->rowCount();

      $sql = "
      DELETE
      FROM $dbschm.contactsurvey
      WHERE contact = :contact
      -- access right for superuser
      and true = (
        select au.superuser
        from $dbschm.annieuser au
        where au.id = :annieuser
        and coalesce(au.validuntil,'9999-09-09') > now()
      )
      ";
      $sth = $dbh->prepare($sql);
      $sth->bindParam(':contact', $contactid);
      $sth->bindParam(':annieuser', $auth_uid);
      $sth->execute();
      $total_row_count += $sth->rowCount();

      $sql = "
      DELETE
      FROM $dbschm.supportneed
      WHERE contact = :contact
      -- access right for superuser
      and true = (
        select au.superuser
        from $dbschm.annieuser au
        where au.id = :annieuser
        and coalesce(au.validuntil,'9999-09-09') > now()
      )
      ";
      $sth = $dbh->prepare($sql);
      $sth->bindParam(':contact', $contactid);
      $sth->bindParam(':annieuser', $auth_uid);
      $sth->execute();
      $total_row_count += $sth->rowCount();

      $sql = "
      DELETE
      FROM $dbschm.supportneedcomment
      WHERE supportneed IN (
        select id
        from $dbschm.supportneed
        where contact = :contact
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
      $sth->bindParam(':contact', $contactid);
      $sth->bindParam(':annieuser', $auth_uid);
      $sth->execute();
      $total_row_count += $sth->rowCount();

      $sql = "
      DELETE
      FROM $dbschm.supportneed
      WHERE contact = :contact
      -- access right for superuser
      and true = (
        select au.superuser
        from $dbschm.annieuser au
        where au.id = :annieuser
        and coalesce(au.validuntil,'9999-09-09') > now()
      )
      ";
      $sth = $dbh->prepare($sql);
      $sth->bindParam(':contact', $contactid);
      $sth->bindParam(':annieuser', $auth_uid);
      $sth->execute();
      $total_row_count += $sth->rowCount();
      //*/

      // this alone would suffice due to "on delete cascade" foreign key constraints
      // but if we want to count deleted rows in total, see above
      $sql = "
      DELETE
      FROM $dbschm.contact
      WHERE id = :contact
      -- access right for superuser
      and true = (
        select au.superuser
        from $dbschm.annieuser au
        where au.id = :annieuser
        and coalesce(au.validuntil,'9999-09-09') > now()
     )
      ";
      $sth = $dbh->prepare($sql);
      $sth->bindParam(':contact', $contactid);
      $sth->bindParam(':annieuser', $auth_uid);
      // check success (to-do-ish: oddly just this last one)
      if ($sth->execute()) {
        $total_row_count += $sth->rowCount();
        http_response_code(200);
        echo json_encode(array("status"=>"OK", "message"=>"$total_row_count rows deleted"));
      } else {
        error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
        http_response_code(400);
        echo json_encode(array("status"=>"FAILED", "message"=>"database problem"));
      }
    } else {
      http_response_code(400);
      echo json_encode(array("status"=>"FAILED", "message"=>"argument missing"));
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