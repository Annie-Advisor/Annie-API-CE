<?php
/* annieuser-missing-email.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend/watch script to check up on data.
 */

// this script is actually NOT meant to be called via HTTP
if (php_sapi_name() != "cli") {
  print("<h1>hello</h1>");
  exit;
}

//: setup block (doesnt hurt to do again)
{
  require_once 'my_app_specific_library_dir/settings.php';
  require_once 'my_app_specific_library_dir/anniedb.php';
  $anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
  /* + db */
  try {
    $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
  } catch (PDOException $e) {
    die("Something went wrong while connecting to database: " . $e->getMessage() );
  }
  /* - db */
} // - setup block

$count = 0;
$problem_count = 0;

$sql = "SELECT * FROM $dbschm.annieuser ";
$sth = $dbh->prepare($sql);
$sth->execute();
$rows = $sth->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $rownum => &$row) { // & for modifying data in-place
  $count++;
  $id = $row['id'];
  $annieusermeta = json_decode(decrypt($row['meta'],base64_decode($row['iv'])));
  // check for meta and email for warning
  if (!isset($annieusermeta)) {
    printf("WARNING: annieuser=$id has no meta therefore no email".PHP_EOL);
    $problem_count++;
  } else {
    if (!array_key_exists('email', $annieusermeta)) {
      printf("WARNING: annieuser=$id has no email".PHP_EOL);
      $problem_count++;
    } else if (!filter_var($annieusermeta->email,FILTER_VALIDATE_EMAIL)) {
      printf("WARNING: annieuser=$id has no functional email \"".$annieusermeta->email."\"".PHP_EOL);
      $problem_count++;
    }
  }
}

if ($problem_count) {
  printf("$problem_count/$count annieusers w/o (functional) email.".PHP_EOL);
}

exit($problem_count);

?>