<!DOCTYPE html>
<html lang="en">
<head>
<!-- bootstrap :: the first 3 must be first -->
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Annie">
<meta name="keywords" content="annie, dropout stop, education, learning, support">
<meta name="author" content="Annie Advisor">

<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" integrity="sha384-HSMxcRTRxnN+Bdg0JdbxYKrThecOKuH5zCYotlSAcp1+c8xmyTe9GYg1l9a69psu" crossorigin="anonymous">
<link href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet" integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">
<?php

// NB! Extending timeout value for this script (AD-215)!
set_time_limit(300);

// "API" for database
require_once 'my_app_specific_library_dir/settings.php';
require_once 'my_app_specific_library_dir/auth.php';
require_once 'my_app_specific_library_dir/anniedb.php';
//$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
$dbh = null;
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}

// selectContactData
// a copy of anniedb->selectContactId to return them ALL!
// for finding contact via phone number in massive amounts faster
$dbcontactdata = array();
function selectContactData() {
  global $dbschm, $dbh;
  $sql = "SELECT id, contact, iv FROM $dbschm.contact";
  // excecute SQL statement
  $sth = $dbh->prepare($sql);
  $sth->execute();
  // for return
  $ret = array();
  $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $rownum => $row) {
    $iv = null;
    if (array_key_exists('iv', $row)) {
      $iv = base64_decode($row['iv']);
    }
    if (array_key_exists('contact', $row)) {
      $dec_contact = json_decode(decrypt($row['contact'],$iv));
      $dataphonenumber = $dec_contact->{'phonenumber'};
      // for searching/accessing via phonenumber:
      $ret[$dataphonenumber] = array(
        "id" => $row["id"],
        "contact" => $dec_contact,
        "iv" => $row["iv"]
      );
    }
  }
  return $ret;
}
$dbcontactdata = selectContactData();

// update, or if doesnt exist insert, a single contact row
// $contact is in string form
function upsertContact($contact) {
  global $cipher, $dbschm, $dbh, $dbcontactdata, $auth_uid;
  if (!$contact) return false;

  $updatedby = $auth_uid;
  $annieuser = null;

  $ivlen = openssl_cipher_iv_length($cipher);
  $iv = openssl_random_pseudo_bytes($ivlen);
  $enc_contact = encrypt($contact,$iv);
  $enc_iv = base64_encode($iv);

  $id = null;
  if (array_key_exists(json_decode($contact)->phonenumber, $dbcontactdata)) {
    $id = $dbcontactdata[json_decode($contact)->phonenumber]["id"];
  } else {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = 'CO';//well, Annie you know
    for ($i = 0; $i < 10; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    $id = $randomString;
  }
  if (array_key_exists("teacheruid", json_decode($contact))) {
    $annieuser = json_decode($contact)->teacheruid;
  }
  $sql = "
    UPDATE $dbschm.contact
    SET contact=:contact, iv=:iv, updated=now(), updatedby=:updatedby
    , annieuser=:annieuser
    WHERE id=:id
  ";
  $sth = $dbh->prepare($sql);
  $sth->bindParam(':contact', $enc_contact);
  $sth->bindParam(':iv', $enc_iv);
  $sth->bindParam(':updatedby', $updatedby);
  $sth->bindParam(':annieuser', $annieuser);
  $sth->bindParam(':id', $id);
  if ($sth->execute() === false) return false;
  // if update "fails" (no rows found), insert
  if ($sth->rowCount() === 0) {
    $sql = "
      INSERT INTO $dbschm.contact (id,contact,iv,updatedby,annieuser)
      VALUES (:id,:contact,:iv,:updatedby,:annieuser)
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':id', $id);
    $sth->bindParam(':contact', $enc_contact);
    $sth->bindParam(':iv', $enc_iv);
    $sth->bindParam(':updatedby', $updatedby);
    $sth->bindParam(':annieuser', $annieuser);
    if ($sth->execute() === false) return false;
    error_log("INFO: cupload: INSERT id=$id by auth_uid=$auth_uid");
    return "INSERT";
  }
  error_log("INFO: cupload: UPDATE id=$id by auth_uid=$auth_uid");
  return "UPDATE";
}

?>

<title>Annie. Contact Upload</title>
</head>
<body>

<div class="container-fluid">

<div class="row">
<div class="col-xs-12 text-center">
  <h1>Annie</h1>
  <h2>Contact Upload</h2>
</div>
</div>

<div class="row">
<div class="col-xs-12">

<?php // step 0 of 3

$step = 0;
// initially show the form of dropping file (and upload)
if (!$_POST) {
?>
<form action="" method="POST" enctype="multipart/form-data" class="form-inline">
<div class="row text-center">
<div class="hidden-xs col-sm-3"></div>
<div class="col-xs-12 col-sm-6 form-group">
  <input type="file" name="fileup" accept=".xlsx" class="form-control">
  <label for="f_over" class="checkbox">
    <input type="checkbox" name="over" id="f_over">
    overwrite
  </label>
  <br>
  <input type="submit" name="_random_" class="btn btn-primary">
</div>
<div class="hidden-xs col-sm-3"></div>
</div>
</form>
<?php
}
?>

<?php
// data processing steps

// step 1 of 3
// check to see if a file upload should be done
// - produces:
$uploadOk = 0;
$target_file = null;
if ($_FILES) {
  $step = 1;
  $target_dir = "uploads/";
  if ($_FILES["fileup"]["name"]) {
    $target_file = $target_dir . basename($_FILES["fileup"]["name"]);
  }

  $filename = basename($_FILES["fileup"]["name"]);
  $filetype = pathinfo($target_file,PATHINFO_EXTENSION);
  $filenoext = preg_replace("/[^a-z0-9_]+/i","",pathinfo($target_file,PATHINFO_FILENAME));

  $uploadOk = 1; // it is fine, at least for a moment
  // Check if file already exists
  if (file_exists($target_file) && !isset($_POST["over"])) {
    echo "<h3>Sorry, file $filename already exists. You can go back and choose \"overwrite\" at your own risk.</h3>".PHP_EOL;
    $uploadOk = 0;
  }
  // Allow certain file formats
  $allowed_types = Array("xlsx", "XLSX"); // case-sensitive, limited variations!
  //print_r($allowed_types);
  if(!in_array($filetype, $allowed_types, true)) {
    echo "Sorry, only XLSX files are allowed.".PHP_EOL;
    $uploadOk = 0;
  }
  // if everything is ok, try to upload file
  if ($uploadOk == 1) {
    if (move_uploaded_file($_FILES["fileup"]["tmp_name"], $target_file)) {
      echo "<h3>The file $filename has been uploaded.</h3>".PHP_EOL;
    } else {
      echo "<h3>Sorry, there was an error uploading your file.</h3>".PHP_EOL;
      $uploadOk = 0;
    }
  }
}

// step 2 of 3
// read excel file to a json we like to use
// - depends on $uploadOk and $target_file
// - produces:
$contactdata = null; // json object (see below)
if ($uploadOk && $target_file) {
  $step = 2;
  // execute python script which is so much better in handling excel files
  // data of interest will be in $contactexcel
  exec(
    '/usr/bin/python3 contactxl.py --quiet --source='.escapeshellarg($target_file)
    ,$contactexcel
  );
  // get rid of root array since there is only one object (with array inside)
  // make php object to access data easier
  $contactdata = json_decode($contactexcel[0]);
  //echo "<!-- ".PHP_EOL; var_dump($contactdata); echo " -->".PHP_EOL;
}

// step 3 of 3
// save the data to database
// print along execution...
if (isset($_POST["save"]) && isset($_POST["just_is_4_all"])) {
  $step = 3;
  $dosave=htmlspecialchars($_POST["save"]);
  $dodata=$_POST["just_is_4_all"];
  echo "<h3>Next step</h3>".PHP_EOL;
  echo "<p>Send or cancel: $dosave</p>".PHP_EOL;
  if ($dosave === "Send") {
    $dataobj=json_decode($dodata);
    echo "<h4>Data</h4>".PHP_EOL;
    echo '<table class="table-condensed table-striped">'.PHP_EOL;
    echo "<tr>";
    //-echo '<th width="10%">ID</th>';
    echo '<th width="20%">NAME</th>';
    echo '<th width="20%">Status</th>';
    echo "</tr>".PHP_EOL;
    if (array_key_exists("data", $dataobj)) {
      foreach ($dataobj->data as $k => $d) {
        //-$id = $d->id;
        $contact = $d->contact;
        echo "<tr>".PHP_EOL;
        //-echo "<td>$id</td>";
        echo "<td>$contact->firstname $contact->lastname</td>".PHP_EOL;
        //-$result = upsertContact($id,json_encode($contact));//contact data is actually string..
        $result = upsertContact(json_encode($contact));//contact data is actually string..
        if ($result) echo '<td class="bg-success">=> SUCCESS WITH '.$result.'</td>'.PHP_EOL;
        else echo '<td class="bg-danger">=> FAILED</td>'.PHP_EOL;
        echo "</tr>".PHP_EOL;
      }
    }
    echo "</table>".PHP_EOL;
  }
}

// - data processing steps done
?>

<?php
// show checkup/send
if ($step == 2) {
?>

<div class="row"><!-- checkup -->
<div class="col-xs-12">
  <h4>This is how the data looks like</h4>
  <table class="table table-condensed table-striped">
  <tr>
    <th><small><small>#</small></small></th>
<?php
  foreach ($contactdata->meta->columns as $col) {
    echo "<th>".$col."</th>".PHP_EOL;
  }
?>
  </tr>
  <!-- data -->
<?php
  $rowcount = 0;
  foreach ($contactdata->data as $d) {
    echo "<tr>".PHP_EOL;
    echo "<th><small><small>".(++$rowcount)."</small></small></th>".PHP_EOL;
    foreach ($contactdata->meta->columns as $col) {
      echo "<td>";
      if (array_key_exists($col, $d->contact)) {
        echo $d->contact->{$col};
      }
      echo "</td>".PHP_EOL;
    }
    echo "</tr>".PHP_EOL;
  }
?>
  </table>
</div>

<div class="col-xs-12">
  <h4>And in JSON form side-by-side with old data (if exists)</h4>
  <table class="table table-condensed table-striped">
  <tr>
    <th><small><small>#</small></small></th>
    <th>ID<small><br>action</small></th>
    <th>CONTACT</th>
    <th>OLD</th>
  </tr>
<?php
  $rowcount = 0;
  foreach ($contactdata->data as $d) {
    echo "<tr>".PHP_EOL;
    echo "<th><small><small>".(++$rowcount)."</small></small></th>".PHP_EOL;
    echo "<td>".PHP_EOL;
    if (array_key_exists($d->contact->phonenumber, $dbcontactdata)) {
      echo "  <span>".$dbcontactdata[$d->contact->phonenumber]["id"]."</span>".PHP_EOL;
      echo "  <br><span>UPDATE";
      $newMatchOld = json_decode(json_encode($d->contact)) == json_decode(json_encode($dbcontactdata[$d->contact->phonenumber]["contact"]));
      //$newMatchOld ? 'match!' : 'dont match!';
      if ($newMatchOld) {
        echo " <span>but no changes</span>";
      }
      echo "</span>".PHP_EOL;
    } else {
      echo "  <br><span>INSERT</span>".PHP_EOL;
    }
    echo "</td>".PHP_EOL;
    echo "<td>".PHP_EOL;
    echo "  <pre>".json_encode($d->contact,JSON_PRETTY_PRINT)."</pre>".PHP_EOL;
    echo "</td>".PHP_EOL;
    echo "<td>".PHP_EOL;
    if (array_key_exists($d->contact->phonenumber, $dbcontactdata)) {
      echo "  <pre>".json_encode($dbcontactdata[$d->contact->phonenumber]["contact"],JSON_PRETTY_PRINT)."</pre>".PHP_EOL;
    }
    echo "</td>".PHP_EOL;
    echo "</td>".PHP_EOL;
    echo "</tr>".PHP_EOL;
  }
?>
  </table>
</div>
<div class="col-xs-12 text-center">
  <h3>Shall we save the data?</h3>
  <form action="?save" method="POST" enctype="application/x-www-form-urlencoded">
    <input type="hidden" name="just_is_4_all" value='<?php echo json_encode($contactdata); ?>'>
    <input type="submit" name="save" value="Send" class="btn btn-success">
    <input type="submit" name="save" value="Cancel" class="btn btn-danger">
  </form>
</div>
</div><!-- / row (checkup) -->

<?php //step==2
// - show checkup/send
}
?>

</div><!-- / col-xs-12 -->
</div><!-- / row -->

</div><!-- / container -->

</body>
</html>
