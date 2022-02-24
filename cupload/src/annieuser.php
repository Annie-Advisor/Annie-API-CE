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
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

$dbh = null;
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}

// selectAnnieuserData
// for finding annieuser via userid in massive amounts faster
$dbannieuserdata = array();
function selectAnnieuserData() {
  global $anniedb, $dbschm, $dbh;
  $sql = "SELECT id, meta, iv, superuser, notifications, validuntil FROM $dbschm.annieuser";
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
    if (array_key_exists('meta', $row)) {
      $dec_meta = json_decode(decrypt($row['meta'],$iv));
      // for searching/accessing via userid:
      $ret[$row["id"]] = array(
        "id" => $row["id"],
        "meta" => $dec_meta,
        //"iv" => $row["iv"],
        "superuser" => $row["superuser"],
        "notifications" => $row["notifications"],
        "validuntil" => $row["validuntil"]
      );
    }
  }
  return $ret;
}
$dbannieuserdata = selectAnnieuserData();

// update, or if doesnt exist insert, a single row
function upsertAnnieuser($id,$annieuser) {
  global $anniedb, $auth_uid;
  if (!$id) return false;

  if (!array_key_exists('createdby', $annieuser)) {
    $annieuser->{'createdby'} = $auth_uid;
  }
  if (!array_key_exists('updatedby', $annieuser)) {
    $annieuser->{'updatedby'} = $auth_uid;
  }

  $ret = $anniedb->updateAnnieuser($id,$annieuser);
  if ($ret === false) {
    $ret = $anniedb->insertAnnieuser($id,$annieuser);
    if ($ret !== false) {
      error_log("INFO: cupload/annieuser: INSERT id=$id by auth_uid=$auth_uid");
      return "INSERT";
    }
  } else {
    error_log("INFO: cupload/annieuser: UPDATE id=$id by auth_uid=$auth_uid");
    return "UPDATE";
  }
  return false;
}

?>

<title>Annie. Annieuser Upload</title>
</head>
<body>

<div class="container-fluid">

<div class="row">
<div class="col-xs-12 text-center">
  <h1>Annie</h1>
  <h2>Annieuser Upload</h2>
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
$exceldata = null; // json object (see below)
if ($uploadOk && $target_file) {
  $step = 2;
  // execute python script which is so much better in handling excel files
  // data of interest will be in $rawexceldata
  exec(
    '/usr/bin/python3 annieuserxl.py --quiet --source='.escapeshellarg($target_file)
    ,$rawexceldata
  );
  // get rid of root array since there is only one object (with array inside)
  // make php object to access data easier
  $exceldata = json_decode($rawexceldata[0]);
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
    echo '<th width="10%">ID</th>';
    echo '<th width="20%">NAME</th>';
    echo '<th width="20%">Status</th>';
    echo "</tr>".PHP_EOL;
    if (array_key_exists("rows", $dataobj)) {
      foreach ($dataobj->rows as $k => $d) {
        echo "<tr>".PHP_EOL;
        echo "<td>".$d->id."</td>";
        echo "<td>".$d->meta->firstname." ".$d->meta->lastname."</td>".PHP_EOL;
        // meta as string but later (decrypt)!
        $d->meta = $d->meta;
        $result = upsertAnnieuser($d->id,$d);
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
  foreach ($exceldata->columns as $col) {
    echo "<th>".$col."</th>".PHP_EOL;
  }
?>
  </tr>
  <!-- data -->
<?php
  $rowcount = 0;
  foreach ($exceldata->rows as $d) {
    echo "<tr>".PHP_EOL;
    echo "<th><small><small>".(++$rowcount)."</small></small></th>".PHP_EOL;
    foreach ($exceldata->columns as $col) {
      echo "<td>";
      if (array_key_exists($col, $d->meta)) {
        echo $d->meta->{$col};
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
    <th>ANNIEUSER</th>
    <th>OLD</th>
  </tr>
<?php
  $rowcount = 0;
  foreach ($exceldata->rows as $d) {
    echo "<tr>".PHP_EOL;
    echo "<th><small><small>".(++$rowcount)."</small></small></th>".PHP_EOL;
    echo "<td>".PHP_EOL;
    if (array_key_exists($d->id, $dbannieuserdata)) {
      echo "  <span>".$dbannieuserdata[$d->id]["id"]."</span>".PHP_EOL;
      echo "  <br><span>UPDATE";
      $newMatchOld = json_decode(json_encode($d)) == json_decode(json_encode($dbannieuserdata[$d->id]));
      if ($newMatchOld) {
        echo " <span>but no changes</span>";
      }
      echo "</span>".PHP_EOL;
    } else {
      echo "  <br><span>INSERT</span>".PHP_EOL;
    }
    echo "</td>".PHP_EOL;
    echo "<td>".PHP_EOL;
    echo "  <pre>".json_encode($d,JSON_PRETTY_PRINT)."</pre>".PHP_EOL;
    echo "</td>".PHP_EOL;
    echo "<td>".PHP_EOL;
    if (array_key_exists($d->id, $dbannieuserdata)) {
      echo "  <pre>".json_encode($dbannieuserdata[$d->id],JSON_PRETTY_PRINT)."</pre>".PHP_EOL;
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
    <input type="hidden" name="just_is_4_all" value='<?php echo json_encode($exceldata); ?>'>
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
