<?php
/* contact-duplicates.php
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

$contactidphonenumber = array();

$sql = "SELECT id,contact,iv FROM $dbschm.contact ";
$sth = $dbh->prepare($sql);
$sth->execute();
$rows = $sth->fetchAll(PDO::FETCH_ASSOC);
$contact_count = 0;
foreach ($rows as $rownum => &$row) { // & for modifying data in-place
  $contact_count++;
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
    $contactidphonenumber[$row['id']] = $row['phonenumber'];
  } else {
    printf("WARNING: There is no phonenumber for ".$row['id'].PHP_EOL);
  }
}

asort($contactidphonenumber);
$previd = null;
$prevphonenumber = null;
$contact_count = 0;
$duplicate_count = 0;
foreach ($contactidphonenumber as $key => $value) {
  $contact_count++;
  if (!isset($prevphonenumber)) {
    $prevphonenumber = $value;
  } else {
    if ($value) {//is value worthy
      if ($prevphonenumber === $value) {
        $duplicate_count++;
        printf("DUPLICATE: $value with ids $previd and $key".PHP_EOL);
      }
      $previd = $key;
      $prevphonenumber = $value;
    }
  }
}

if ($duplicate_count) {
  printf("$duplicate_count/$contact_count contacts w/ duplicate phonenumber.".PHP_EOL);
}

exit($duplicate_count);

?>