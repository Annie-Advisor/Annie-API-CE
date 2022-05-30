<?php
/* anniedb.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Access to Annie database.
 * NB! This script is meant to be imported into other scripts.
 *     Access authorization should be handled elsewhere!
 */

namespace Annie\Advisor;
use PDO;

class DB {
  const VERSION = "v202104+flyway";

  //
  // VARIABLES
  //

  private $dbh;
  private $dbschm;
  private $salt;
  private $cipher;
  private $updatedby;

  //
  // CONSTRUCTORS & DESTRUCTORS
  //

  public function __construct($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt) {
    global $auth_uid;
    if (!empty($auth_uid)) { // engine, watch, ...
      $this->updatedby = $auth_uid;
    } else {
      $this->updatedby = "Arnie"; // w/ typo to show this error
    }
    $this->dbschm = $dbschm;
    $this->salt   = $salt;
    $this->cipher = "aes-256-cbc";

    try {
      $this->dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
    } catch (PDOException $e) {
      die("Something went wrong while connecting to database: " . $e->getMessage() );
    }
  }

  public function __destruct() {
    // clean up & close
    $this->dbh = null;
  }

  //
  // PRIVATES
  //

  public function getDbh() {
    return $this->dbh;
  }

  public function encrypt($string,$iv) {
      $output = false;

      if (in_array($this->cipher, openssl_get_cipher_methods())) {
          $output = openssl_encrypt($string, $this->cipher, $this->salt, $options=0, $iv);
      }

      return $output;
  }

  public function decrypt($string,$iv) {
      $output = false;

      if (in_array($this->cipher, openssl_get_cipher_methods())) {
          $output = openssl_decrypt($string, $this->cipher, $this->salt, $options=0, $iv);
      }

      return $output;
  }

  //
  // ACCESSORIES (CRUD)
  //

  public function selectAnnieuser($id) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id, meta, superuser, notifications, iv, validuntil
      FROM $dbschm.annieuser
      WHERE 1=1
      and coalesce(validuntil,'9999-09-09') > now()
    ";
    if ($id) {
      $sql.= " AND id = :id ";
    }
    $sth = $this->dbh->prepare($sql);
    if ($id) {
      $sth->bindParam(':id', $id);
    }
    $sth->execute();
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
      }
      if (array_key_exists('meta', $row)) {
        $row['meta'] = json_decode($this->decrypt($row['meta'],$iv));
      }
    }
    return $rows;
  }

  public function insertAnnieuser($id,$inputs) {
    $dbschm = $this->dbschm;

    if (!is_array($inputs)) {
      $inputs = array($inputs);
    }

    $ret = array();
    foreach ($inputs as $input) {
      $thisisok = true;//assume good

      if (!array_key_exists('id', $input) || $input->{'id'}=="") {
        if (!$id) {
          $thisisok = false;
        }
        $input->{'id'} = $id; //for a true list of objects this is awkward, though!
      }
      // default if missing
      if (!array_key_exists('meta', $input)) {
        $input->{'meta'} = "";
      }
      if (!array_key_exists('superuser', $input)) {
        $input->{'superuser'} = false;
      }
      if (!array_key_exists('notifications', $input)) {
        $input->{'notifications'} = 'IMMEDIATE';
      }
      //iv
      if (!array_key_exists('validuntil', $input)) {
        $input->{'validuntil'} = null;
      }
      if (!array_key_exists('updated', $input)) {
        $input->{'updated'} = "now()";
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = $this->updatedby;
      }
      if (!array_key_exists('created', $input)) {
        $input->{'created'} = "now()";
      }
      if (!array_key_exists('createdby', $input)) {
        $input->{'createdby'} = $this->updatedby;
      }

      if ($thisisok) {
        // add business key here to identify object
        $retobj = array(
          "id" => $input->{'id'}
        );
        // check if ID (userid) exists already and update instead
        $sql = "
          SELECT id
          FROM $dbschm.annieuser
          WHERE id=:annieuser
        ";
        $sth = $this->dbh->prepare($sql);
        $sth->bindParam(':annieuser', $input->{'id'});
        $sth->execute();
        if ($sth->rowCount()>0) {
          $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $rownum => $row) {
            if (array_key_exists('id', $row)) {
              $rowret = $this->updateAnnieuser($row['id'],$input);
              if ($rowret === false) {
                $thisisok = false;
                array_push($ret,array(
                  "object" => $retobj,
                  "operation" => "UPDATE",
                  "status" => "FAILED"
                ));
              } else {
                array_push($ret,array(
                  "object" => $retobj,
                  "operation" => "UPDATE",
                  "status" => "OK"
                ));
              }
            }
          }
        } else { //did not exist yet, resume normal action...

          // encrypt:
          $ivlen = openssl_cipher_iv_length($this->cipher);
          $iv = openssl_random_pseudo_bytes($ivlen);
          $enc_meta = $this->encrypt(json_encode($input->{'meta'}),$iv);
          $enc_iv = base64_encode($iv);//store $iv for decryption later
          //-encrypt
          $sql = "
            INSERT INTO $dbschm.annieuser (id,meta,superuser,notifications,iv,validuntil,updated,updatedby,created,createdby)
            VALUES (:id,:meta,:superuser,:notifications,:iv,:validuntil,:updated,:updatedby,:created,:createdby)
          ";
          $sth = $this->dbh->prepare($sql);
          $sth->bindParam(':id', $input->{'id'});
          $sth->bindParam(':meta', $enc_meta);
          $_tmp_superuser = json_encode($input->{'superuser'});
          $sth->bindParam(':superuser', $_tmp_superuser);
          $sth->bindParam(':notifications', $input->{'notifications'});
          $sth->bindParam(':iv', $enc_iv);
          $sth->bindParam(':validuntil', $input->{'validuntil'});
          $sth->bindParam(':updated', $input->{'updated'});
          $sth->bindParam(':updatedby', $input->{'updatedby'});
          $sth->bindParam(':created', $input->{'created'});
          $sth->bindParam(':createdby', $input->{'createdby'});
          if (!$sth->execute()) {
            error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
            $thisisok = false;
          }
          if ($sth->rowCount() < 1) {
            $thisisok = false;
            array_push($ret,array(
              "object" => $retobj,
              "operation" => "INSERT",
              "status" => "FAILED"
            ));
          } else {
            array_push($ret,array(
              "object" => $retobj,
              "operation" => "INSERT",
              "status" => "OK"
            ));
          }
        }
      }//-thisisok for input
    }//-foreach inputs
    return $ret;
  }

  //nb! not in direct API use
  public function updateAnnieuser($id,$input) {
    $dbschm = $this->dbschm;
    // default if missing
    if (!array_key_exists('meta', $input)) {
      $input->{'meta'} = "";
    }
    if (!array_key_exists('superuser', $input)) {
      $input->{'superuser'} = false;
    }
    if (!array_key_exists('notifications', $input)) {
      $input->{'notifications'} = 'IMMEDIATE';
    }
    //iv
    if (!array_key_exists('validuntil', $input)) {
      $input->{'validuntil'} = null;
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }
    // encrypt:
    $ivlen = openssl_cipher_iv_length($this->cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $enc_meta = $this->encrypt(json_encode($input->{'meta'}),$iv);
    $enc_iv = base64_encode($iv);//store $iv for decryption later
    //-encrypt
    $sql = "
      UPDATE $dbschm.annieuser
      SET meta=:meta, superuser=:superuser, notifications=:notifications, iv=:iv, validuntil=:validuntil, updated=:updated, updatedby=:updatedby
      WHERE id=:id
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':meta', $enc_meta);
    $_tmp_superuser = json_encode($input->{'superuser'});
    $sth->bindParam(':superuser', $_tmp_superuser);
    $sth->bindParam(':notifications', $input->{'notifications'});
    $sth->bindParam(':iv', $enc_iv);
    $sth->bindParam(':validuntil', $input->{'validuntil'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      return false;
    }
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  // notifications update only
  public function updateAnnieuserNotifications($id,$notifications) {
    $dbschm = $this->dbschm;
    $updated = "now()";
    $updatedby = $this->updatedby;
    $sql = "
      UPDATE $dbschm.annieuser
      SET notifications=:notifications, updated=:updated, updatedby=:updatedby
      WHERE id=:id
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':notifications', $notifications);
    $sth->bindParam(':updated', $updated);
    $sth->bindParam(':updatedby', $updatedby);
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      return false;
    }
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function deleteAnnieuser($id) {
    $dbschm = $this->dbschm;
    if (!$id) {
      return false;
    }
    $sql = "
      DELETE FROM $dbschm.annieuser
      WHERE id = :id
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      return false;
    }
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function selectAnnieusersurvey($id,$getarr,$auth_user=null) {
    $dbschm = $this->dbschm;

    $sql = "
      SELECT id, annieuser, survey, meta
      FROM $dbschm.annieusersurvey
      WHERE 1=1
      and(1=0
        or (?) in (select annieuser from $dbschm.usageright_superuser)
        or (?,survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
      )
    ";
    // normal (intended) id argument for max one value (.../api.php/1)
    if ($id) $sql.= " AND id = ? ";

    // make lists of "?" characters from get parameters
    $in_survey = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    if ($in_survey)  $sql.= " AND survey in ($in_survey)";//part of list of strings
    // nb! id values as get parameter array (.../api.php?id=1&id=2...)
    $in_id = implode(',', array_fill(0, count($getarr["id"]), '?'));
    if ($in_id)  $sql.= " AND id in ($in_id)";

    $sth = $this->dbh->prepare($sql);
    // bind parameters/values for both queries (length of array matters)
    // first "key" then the rest from specialized array(s) for each '?'
    $sqlparams = [$auth_user,$auth_user];
    if ($id) {
      $sqlparams = array_merge($sqlparams,[$id]);
    }
    $sqlparams = array_merge($sqlparams,$getarr["survey"],$getarr["id"]);
    $sth->execute($sqlparams);
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      if (array_key_exists('meta', $row)) {
        $row['meta'] = json_decode($row['meta']);
      }
    }
    return $rows;
  }

  public function insertAnnieusersurvey($inputs,$auth_user=null) { // NB! wishes for an array! nb! no id, generated!
    $dbschm = $this->dbschm;

    if (!is_array($inputs)) {
      $inputs = array($inputs);
    }

    $ret = array();
    foreach ($inputs as $input) {
      $thisisok = true;//assume good

      if (!array_key_exists('annieuser', $input) || $input->{'annieuser'}=="") {
        $thisisok = false;
      }
      if (!array_key_exists('survey', $input) || $input->{'survey'}=="") {
        $thisisok = false;
      }
      if (!array_key_exists('meta', $input)) {
        $input->{'meta'} = "";
      }
      // default if missing
      if (!array_key_exists('updated', $input)) {
        $input->{'updated'} = "now()";
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = $this->updatedby;
      }

      if ($thisisok) {
        // add business key here to identify object
        $retobj = array(
          "annieuser" => $input->{'annieuser'},
          "survey" => $input->{'survey'}
        );
        // check if ID (or annieuser+survey) exists already and update instead
        $sql = "
          SELECT id
          FROM $dbschm.annieusersurvey
          WHERE annieuser=:annieuser AND survey=:survey
        ";
        $sth = $this->dbh->prepare($sql);
        $sth->bindParam(':annieuser', $input->{'annieuser'});
        $sth->bindParam(':survey', $input->{'survey'});
        $sth->execute();
        if ($sth->rowCount()>0) {
          $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $rownum => $row) {
            if (array_key_exists('id', $row)) {
              $rowret = $this->updateAnnieusersurvey($row['id'],$input,$auth_user);
              if ($rowret === false) {
                $thisisok = false;
                array_push($ret,array(
                  "object" => $retobj,
                  "operation" => "UPDATE",
                  "status" => "FAILED"
                ));
              } else {
                array_push($ret,array(
                  "object" => $retobj,
                  "operation" => "UPDATE",
                  "status" => "OK"
                ));
              }
            }
          }
        } else { //did not exist yet, resume normal action...
          $sql = "
            INSERT INTO $dbschm.annieusersurvey (annieuser,survey,meta,updated,updatedby)
            VALUES (:annieuser,:survey,:meta,:updated,:updatedby)
          ";
          $sth = $this->dbh->prepare($sql);
          //$sth->bindParam(':id', $input->{'id'});
          $sth->bindParam(':annieuser', $input->{'annieuser'});
          $sth->bindParam(':survey', $input->{'survey'});
          $_tmp_meta = json_encode($input->{'meta'});
          $sth->bindParam(':meta', $_tmp_meta);
          $sth->bindParam(':updated', $input->{'updated'});
          $sth->bindParam(':updatedby', $input->{'updatedby'});
          $sth->execute();
          //$ret = $this->dbh->lastInsertId(); //nb! return the ID of inserted row
          if ($sth->rowCount() < 1) {
            $thisisok = false;
            array_push($ret,array(
              "object" => $retobj,
              "operation" => "INSERT",
              "status" => "FAILED"
            ));
          } else {
            array_push($ret,array(
              "object" => $retobj,
              "operation" => "INSERT",
              "status" => "OK"
            ));
          }
        }
      }
    }
    return $ret;
  }

  public function updateAnnieusersurvey($id,$input,$auth_user=null) {
    $dbschm = $this->dbschm;
    // to-do-ish: if not given don't update, now go with all-at-once method
    if (!array_key_exists('meta', $input)) {
      $input->{'meta'} = "";
    }
    if (!array_key_exists('annieuser', $input) || $input->{'annieuser'}=="") {
      return false;
    }
    if (!array_key_exists('survey', $input) || $input->{'survey'}=="") {
      return false;
    }
    // default if missing
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }
    $sql = "
      UPDATE $dbschm.annieusersurvey
      SET annieuser=:annieuser, survey=:survey, meta=:meta, updated=:updated, updatedby=:updatedby
      WHERE id=:id
      and(1=0
        or (:auth_user) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_user,survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
      )
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':annieuser', $input->{'annieuser'});
    $sth->bindParam(':survey', $input->{'survey'});
    $_tmp_meta = json_encode($input->{'meta'});
    $sth->bindParam(':meta', $_tmp_meta);
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':id', $id);
    $sth->bindParam(':auth_user', $auth_user);
    if (!$sth->execute()) {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      return false;
    }
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function deleteAnnieusersurvey($id) {
    $dbschm = $this->dbschm;
    if (!$id) {
      return false;
    }
    $sql = "
      DELETE FROM $dbschm.annieusersurvey
      WHERE id = :id
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      return false;
    }
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function selectCodes($codeset,$code) {
    $dbschm = $this->dbschm;
    // three different kind of queries and result
    // each for the amount of arguments (0, 1, or 2)
    $sql = "
      SELECT jsonb_build_object(codeset, jsonb_object_agg(code,value)) as json
      FROM $dbschm.codes
      WHERE 1=1
      AND coalesce(validuntil,'9999-09-09') > now()
      GROUP BY codeset
    ";
    if (isset($codeset) && $codeset!="") {
      $sql = "
        SELECT jsonb_object_agg(code,value) as json
        FROM $dbschm.codes
        WHERE codeset = :codeset
        AND coalesce(validuntil,'9999-09-09') > now()
      ";
      if (isset($code) && $code!="") {
        $sql = "
          SELECT value as json
          FROM $dbschm.codes
          WHERE codeset = :codeset
          AND code = :code
          AND coalesce(validuntil,'9999-09-09') > now()
        ";
      }
    }

    $sth = $this->dbh->prepare($sql);
    if (isset($codeset) && $codeset!="") {
      $sth->bindParam(':codeset', $codeset);
    }
    if (isset($code) && $code!="") {
      $sth->bindParam(':code', $code);
    }
    $sth->execute();
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      if (array_key_exists('json', $row)) {
        //nb! move "json" up one level and replace
        $row = json_decode($row['json']);
      }
    }
    return $rows;
  }

  // upsert: insert but update if exists
  public function insertCodes($inputs) { // NB! wishes for an array!
    $dbschm = $this->dbschm;

    if (!is_array($inputs)) {
      $inputs = array($inputs);
    }

    $ret = array();
    foreach ($inputs as $input) {
      $thisisok = true;
      if (!is_object($input)) {
        $thisisok = false;
      }

      if ($thisisok) {
        // check for some mandatory data
        if (!array_key_exists('codeset', $input) || $input->{'codeset'}=="") {
          $thisisok = false;
        }
        if (!array_key_exists('code', $input) || $input->{'code'}=="") {
          $thisisok = false;
        }
        // add some defaults
        if (!array_key_exists('updated', $input)) {
          $input->{'updated'} = "now()";;
        }
        if (!array_key_exists('updatedby', $input)) {
          $input->{'updatedby'} = $this->updatedby;
        }
        // nb! validuntil is not really used but we maintain possible future
        if (!array_key_exists('validuntil', $input)) {
          $input->{'validuntil'} = null;
        }
        // do value the other way around cause of json data
        $value = null;
        if (array_key_exists('value', $input)) {
          $value = json_encode($input->{'value'});
        }
      }

      if ($thisisok) {
        // add business key here to identify object
        $retobj = array(
          "codeset" => $input->{'codeset'},
          "code" => $input->{'code'}
        );
        // check if key(codeset+code) exists already and update
        $sql = "SELECT id FROM $dbschm.codes WHERE codeset = :codeset AND code = :code";
        $sth = $this->dbh->prepare($sql);
        $sth->bindParam(':codeset', $input->{'codeset'});
        $sth->bindParam(':code', $input->{'code'});
        $sth->execute();
        if ($sth->rowCount()>0) {
          $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $rownum => $row) {
            if (array_key_exists('id', $row)) {//id doesnt matter but nice to know it exists
              $rowret = $this->updateCodes($input);
              if ($rowret === false) {
                $thisisok = false;
                array_push($ret,array(
                  "object" => $retobj,
                  "operation" => "UPDATE",
                  "status" => "FAILED"
                ));
              } else {
                array_push($ret,array(
                  "object" => $retobj,
                  "operation" => "UPDATE",
                  "status" => "OK"
                ));
              }
            }
          }
        } else {
          $sql = "
            INSERT INTO $dbschm.codes (updated,updatedby,codeset,code,value,validuntil)
            VALUES (:updated,:updatedby,:codeset,:code,:value,:validuntil)
          ";
          $sth = $this->dbh->prepare($sql);
          $sth->bindParam(':updated', $input->{'updated'});
          $sth->bindParam(':updatedby', $input->{'updatedby'});
          $sth->bindParam(':codeset', $input->{'codeset'});
          $sth->bindParam(':code', $input->{'code'});
          $sth->bindParam(':value', $value);
          $sth->bindParam(':validuntil', $input->{'validuntil'});
          $sth->execute();
          //$ret = $this->dbh->lastInsertId(); //nb! return the ID of inserted row
          if ($sth->rowCount() < 1) {
            $thisisok = false;
            array_push($ret,array(
              "object" => $retobj,
              "operation" => "INSERT",
              "status" => "FAILED"
            ));
          } else {
            array_push($ret,array(
              "object" => $retobj,
              "operation" => "INSERT",
              "status" => "OK"
            ));
          }
        }
      }
    }
    return $ret;
  }

  public function updateCodes($input) {
    $dbschm = $this->dbschm;

    if (!array_key_exists('codeset', $input) || $input->{'codeset'}=="") {
      return false;
    }
    if (!array_key_exists('code', $input) || $input->{'code'}=="") {
      return false;
    }
    // default if missing
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }
    // json
    $value = null;
    if (array_key_exists('value', $input)) {
      $value = json_encode($input->{'value'});
    }
    $sql = "
      UPDATE $dbschm.codes
      SET value=:value, updated=:updated, updatedby=:updatedby, validuntil=:validuntil
      WHERE codeset = :codeset AND code = :code
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':value', $value);
    $sth->bindParam(':validuntil', $input->{'validuntil'});
    $sth->bindParam(':codeset', $input->{'codeset'});
    $sth->bindParam(':code', $input->{'code'});
    if (!$sth->execute()) {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      return false;
    }
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function deleteCodes($codeset,$code) {
    $dbschm = $this->dbschm;
    if (isset($codeset) && $codeset!="" && isset($code) && $code!="") {
      $sql = "
        DELETE FROM $dbschm.codes
        WHERE codeset = :codeset AND code = :code
      ";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':codeset', $codeset);
      $sth->bindParam(':code', $code);
      if (!$sth->execute()) {
        error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
        return false;
      }
      if ($sth->rowCount() < 1) return false;
      return true;
    }
    return false;
  }

  // nb! multiuse with API and engine, watch
  public function selectConfig($segment,$field) {
    $dbschm = $this->dbschm;
    // for listing all data
    $sql = "
      SELECT id,segment,field,value
      FROM $dbschm.config
      WHERE 1=1
    ";
    if ($segment) {
      $sql.= " AND segment = :segment ";
    }
    if ($field) {
      $sql.= " AND field = :field ";
    }

    $sth = $this->dbh->prepare($sql);
    if ($segment) {
      $sth->bindParam(':segment', $segment);
    }
    if ($field) {
      $sth->bindParam(':field', $field);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }
  // todo: insert, update, delete

  // nb! multiuse with API and engine, watch
  public function selectContact($contact,$auth_user=null) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,updated,updatedby,contact,iv,annieuser,optout
      FROM $dbschm.contact
      WHERE 1=1
    ";
    if ($contact) {
      $sql.= "
      AND id = :contact
      ";
    }
    if ($auth_user) { // not for engine, watch
      $sql.= "
        and(1=0
          or (:auth_user) in (select annieuser from $dbschm.usageright_superuser)
          or (:auth_user) in (select annieuser from $dbschm.usageright_coordinator)
          -- survey is NOT set but user still has access
          or (:auth_user) in (select annieuser from $dbschm.usageright_provider)
          or (
            (:auth_user,contact.id) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
            --cant negate providers due to no category because of no supportneed via survey
          )
        )
      ";
    }
    $sth = $this->dbh->prepare($sql);
    if ($contact) {
      $sth->bindParam(':contact', $contact);
    }
    if ($auth_user) { // not for engine, watch
      $sth->bindParam(':auth_user', $auth_user);
    }
    $sth->execute();
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
      }
      if (array_key_exists('contact', $row)) {
        $row['contact'] = json_decode($this->decrypt($row['contact'],$iv));
      }
    }
    return $rows;
  }

  // no plan to insert or update contact data from here

  // nb! see also selectContactContactsurveys
  // nb! no select for contactsurvey

  // nb! multiuse with API and engine, watch
  public function insertContactsurvey($input) {
    $dbschm = $this->dbschm;
    // contact can not be missing
    if (!array_key_exists('contact', $input)) {
      return false;
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }
    // survey can not be missing
    if (!array_key_exists('survey', $input)) {
      return false;
    }
    // status can not be missing
    // to-do-ish: check status value?
    if (!array_key_exists('status', $input)) {
      return false;
    }

    $sql = "
      INSERT INTO $dbschm.contactsurvey (updated,updatedby,contact,survey,status)
      VALUES (:updated,:updatedby,:contact,:survey,:status)
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->execute();
    return $this->dbh->lastInsertId();
  }
  // nb! no update or delete for contactsurvey

  // nb! no select for followup
  public function insertFollowup($input) {
    $dbschm = $this->dbschm;
    // supportneed can not be missing
    if (!array_key_exists('supportneed', $input)) {
      return false;
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }
    // status can not be missing
    // to-do-ish: check status value?
    if (!array_key_exists('status', $input)) {
      return false;
    }

    $sql = "
      INSERT INTO $dbschm.followup (updated,updatedby,supportneed,status)
      VALUES (:updated,:updatedby,:supportneed,:status)
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':supportneed', $input->{'supportneed'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->execute();
    return $this->dbh->lastInsertId();
  }
  // nb! no update or delete for followup

  // nb! see selectContactMessages
  // nb! multiuse with API and engine, watch
  public function insertMessage($input) {
    $dbschm = $this->dbschm;
    if (!array_key_exists('id', $input) || $input->{'id'}=="") {
      // gererate id if not given
      // Credits: https://stackoverflow.com/a/4356295
      $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
      $charactersLength = strlen($characters);
      $randomString = 'MJ';//well, Annie you know
      for ($i = 0; $i < 32; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
      }
      $input->{'id'} = $randomString;
    }
    if (!array_key_exists('contact', $input)) {
      return false;
    }
    if (!array_key_exists('created', $input)) {
      $input->{'created'} = "now()";
    }
    if (!array_key_exists('createdby', $input)) {
      $input->{'createdby'} = $this->updatedby;
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }
    if (!array_key_exists('body', $input)) {
      $input->{'body'} = "";
    }
    if (!array_key_exists('sender', $input)) {
      $input->{'sender'} = "";
    }
    if (!array_key_exists('survey', $input)) {
      $input->{'survey'} = null;
    }
    if (!array_key_exists('context', $input)) {
      $input->{'context'} = null;
    }
    if (!array_key_exists('status', $input)) {
      $input->{'status'} = null;
    }

    // encrypt:
    $ivlen = openssl_cipher_iv_length($this->cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $enc_body = $this->encrypt($input->{'body'},$iv);
    $enc_sender = $this->encrypt($input->{'sender'},$iv);
    //store $iv for decryption later
    $enc_iv = base64_encode($iv);
    //-encrypt

    $sql = "
      INSERT INTO $dbschm.message (id,updated,updatedby,contact,body,sender,survey,context,status,created,createdby,iv)
      VALUES (:message,:updated,:updatedby,:contact,:body,:sender,:survey,:context,:status,:created,:createdby,:iv)
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':message', $input->{'id'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':body', $enc_body);
    $sth->bindParam(':sender', $enc_sender);
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':context', $input->{'context'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->bindParam(':created', $input->{'created'});
    $sth->bindParam(':createdby', $input->{'createdby'});
    $sth->bindParam(':iv', $enc_iv);
    if (!$sth->execute()) {
      error_log("ERROR: DB: ".json_encode($sth->errorInfo()));
      return false;
    }
    //if ($sth->rowCount() < 1) return false;
    return $input->{'id'}; //nb! return the ID of inserted row
  }

  // nb! multiuse with API and engine, watch
  // updates only status!
  public function updateMessage($message,$input) {
    $dbschm = $this->dbschm;
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }
    $sql = "
      UPDATE $dbschm.message
      SET status=:status, updated=:updated, updatedby=:updatedby
      WHERE id=:message
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':status', $input->{'status'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':message', $message);
    $sth->execute();
    return true;
  }
  // nb! no delete for message

  // nb! v1 API only
  // nb! see also selectAnnieuserSupportneeds, and older selectSupportneedsPage and selectSupportneedHistory
  public function selectSupportneed($supportneedid) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,updated,updatedby,contact,category,status,survey,supporttype,followuptype
      FROM $dbschm.supportneed
      WHERE 1=1
    ";
    if ($supportneedid) {
      $sql.= "AND id = :supportneed ";
    }

    $sth = $this->dbh->prepare($sql);
    if ($supportneedid) {
      $sth->bindParam(':supportneed', $supportneedid);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  // nb! multiuse with API and followupengine
  //     select entire history with given id marking latest (max id) as current=true
  public function selectSupportneedHistory($supportneedid) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id
      , updated
      , updatedby
      , contact
      , category
      , status
      , survey
      , supporttype
      , followuptype
      , followupresult
      , case
        when id = (
          select max(sn.id)
          from $dbschm.supportneed sn
          where sn.contact = supportneed.contact
          and sn.survey = supportneed.survey
        )
        then true
        else false
        end as current
      , case
        when id = (
          select max(sn.id)
          from $dbschm.supportneed sn
          where sn.contact = supportneed.contact
          and sn.survey = supportneed.survey
        )
        then (
          select fu.status
          from $dbschm.followup fu
          where fu.supportneed in (
            select sn.id from $dbschm.supportneed sn
            where sn.contact = supportneed.contact
            and sn.survey = supportneed.survey
          )
          -- last one for supportneed (contact):
          and (fu.supportneed,fu.updated) in (
            select supportneed,max(updated)
            from $dbschm.followup
            group by supportneed
          )
          order by updated desc limit 1
        )
        else null
        end as followupstatus
      FROM $dbschm.supportneed
      WHERE 1=1
    ";
    if ($supportneedid) {
      // match any point in history with business key "contact+survey"
      $sql.= "
      AND (contact,survey) IN (
        select sn.contact, sn.survey
        from $dbschm.supportneed sn
        where sn.id = :supportneed
      )
      ";
    }

    $sth = $this->dbh->prepare($sql);
    if ($supportneedid) {
      $sth->bindParam(':supportneed', $supportneedid);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  // nb! this is it's own kind of API for own kind of table(s)
  // nb! multiuse with API and engine, landbot
  public function insertSupportneed($input,$auth_user=null) {
    $dbschm = $this->dbschm;
    $contactid = null;
    $survey = null;
    $category = null;
    $status = null;
    $supporttype = null;
    $followuptype = null;
    $followupresult = null;
    // fetch previous data for supportneed (to keep)
    if (array_key_exists('id', $input)) {
      $earliersupportneedid = $input->{'id'};
      unset($input->{'id'}); // just clean up
      $supportneedhistory = $this->selectSupportneedHistory($earliersupportneedid);
      foreach ($supportneedhistory as $sni => $supportneed) {
        if ($supportneed['current'] === true) {
          $contactid = $supportneed['contact'];
          $survey = $supportneed['survey'];
          $category = $supportneed['category'];
          $status = $supportneed['status'];
          $supporttype = $supportneed['supporttype'];
          $followuptype = $supportneed['followuptype'];
          $followupresult = $supportneed['followupresult'];
          break;
        }
      }
    }
    if (!array_key_exists('contact', $input)) {
      if (!isset($contactid)) {
        return false;
      } else {
        $input->{'contact'} = $contactid;
      }
    }
    if (!array_key_exists('survey', $input)) {
      if (!isset($survey)) {
        return false;
      } else {
        $input->{'survey'} = $survey;
      }
    }
    if (!array_key_exists('category', $input)) {
      if (!isset($category)) {
        $input->{'category'} = "Z";//unknown
      } else {
        $input->{'category'} = $category;
      }
    }
    if (!array_key_exists('status', $input)) {
      if (!isset($status)) {
        $input->{'status'} = "NEW";
      } else {
        $input->{'status'} = $status;
      }
    }
    if (!array_key_exists('supporttype', $input)) {
      if (!isset($supporttype)) {
        $input->{'supporttype'} = null;
      } else {
        $input->{'supporttype'} = $supporttype;
      }
    }
    if (!array_key_exists('followuptype', $input)) {
      if (!isset($followuptype)) {
        $input->{'followuptype'} = null;
      } else {
        $input->{'followuptype'} = $followuptype;
      }
    }
    if (!array_key_exists('followupresult', $input)) {
      if (!isset($followupresult)) {
        $input->{'followupresult'} = null;
      } else {
        $input->{'followupresult'} = $followupresult;
      }
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = "now()";
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = $this->updatedby;
    }

    // auth_user has access check
    if ($auth_user) { // not for engine, watch (if used)
      $sql = "
        SELECT 1 WHERE (1=0
          or (:auth_user) in (select annieuser from $dbschm.usageright_superuser)
          or (:auth_user,:survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
          or (:auth_user,:survey,:category) in (select annieuser,survey,category from $dbschm.usageright_provider)
          or (
            (:auth_user,:contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
            and (:survey,:category) NOT in (select survey,category from $dbschm.usageright_provider)
          )
        )
      ";
      $sth = $this->dbh->prepare($sql);
      $sth->execute(array(
        ':auth_user' => $auth_user,
        ':survey' => $input->{'survey'},
        ':category' => $input->{'category'},
        ':contact' => $input->{'contact'}
      ));
      if ($sth->rowCount() <= 0) {
        return false;
      }
    }

    $sql = "
      INSERT INTO $dbschm.supportneed (updated,updatedby,contact,category,status,survey,supporttype,followuptype,followupresult)
      VALUES (:updated,:updatedby,:contact,:category,:status,:survey,:supporttype,:followuptype,:followupresult)
    ";
    $sth = $this->dbh->prepare($sql);
    //$sth->bindParam(1, $input->{'id'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':category', $input->{'category'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':supporttype', $input->{'supporttype'});
    $sth->bindParam(':followuptype', $input->{'followuptype'});
    $sth->bindParam(':followupresult', $input->{'followupresult'});
    $sth->execute();
    $input->{'id'} = $this->dbh->lastInsertId(); //works without parameter as the id column is clear

    return $input->{'id'}; //nb! return the ID of inserted row
  }
  //nb! no update for supportneed, just add new rows
  //nb! no delete for supportneed (see survey archive for example)

  // nb! no direct select for supportneedcomment (see selectSupportneedSupportneedcomments)
  public function insertSupportneedcomment($input) {
    $dbschm = $this->dbschm;
    if ($input) {
      if (!array_key_exists('supportneed', $input)) {
        return false;
      }
      if (!array_key_exists('updated', $input)) {
        $input->{'updated'} = "now()";
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = $this->updatedby;
      }
      if (!array_key_exists('body', $input)) {
        $input->{'body'} = "";
      }

      // encrypt:
      $ivlen = openssl_cipher_iv_length($this->cipher);
      $iv = openssl_random_pseudo_bytes($ivlen);
      $enc_body = $this->encrypt($input->{'body'},$iv);
      //store $iv for decryption later
      $enc_iv = base64_encode($iv);
      //-encrypt

      $sql = "
        INSERT INTO $dbschm.supportneedcomment (updated,updatedby,supportneed,body,iv)
        VALUES (:updated,:updatedby,:supportneed,:body,:iv)
      ";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':updated', $input->{'updated'});
      $sth->bindParam(':updatedby', $input->{'updatedby'});
      $sth->bindParam(':supportneed', $input->{'supportneed'});
      $sth->bindParam(':body', $enc_body);
      $sth->bindParam(':iv', $enc_iv);
      $sth->execute();
      return $this->dbh->lastInsertId(); //works without parameter as the id column is clear
    }
    return false;
  }
  // nb! no update or delete for supportneedcomment

  public function selectSurvey($survey,$getarr) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,updated,updatedby,starttime,endtime,config,status,contacts
      FROM $dbschm.survey
      WHERE 1=1
    ";
    // normal (intended) id argument for max one value (.../api.php/1)
    if ($survey) {
      $sql.= " AND id = ? ";
    }

    if (!isset($getarr)) {
      $getarr = array();
    }
    if (!array_key_exists("id", $getarr)) {
      $getarr["id"] = array();
    }
    if (!array_key_exists("status", $getarr)) {
      $getarr["status"] = array();
    }
    // make lists of "?" characters from get parameters
    $in_id = implode(',', array_fill(0, count($getarr["id"]), '?'));
    if ($in_id)      $sql.= " AND id in ($in_id)";//part of list of strings
    $in_status = implode(',', array_fill(0, count($getarr["status"]), '?'));
    if ($in_status)  $sql.= " AND status in ($in_status)";

    if (!$survey && !$in_id && !$in_status) {
      $sql.= " AND coalesce(status,'') NOT IN ('ARCHIVED','DELETED') ";
    }

    $sth = $this->dbh->prepare($sql);
    // bind parameters/values for both queries (length of array matters)
    // first "key" then the rest from specialized array(s) for each '?'
    $sqlparams = [];
    if ($survey) {
      $sqlparams = array_merge($sqlparams,[$survey]);
    }
    $sqlparams = array_merge($sqlparams,$getarr["id"],$getarr["status"]);
    $sth->execute($sqlparams);
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      if (array_key_exists('config', $row)) {
        $row['config'] = json_decode($row['config']);
      }
      if (array_key_exists('contacts', $row)) {
        $row['contacts'] = json_decode($row['contacts']);
      }
    }
    return $rows;
  }

  public function insertSurvey($survey,$input) {
    $dbschm = $this->dbschm;
    //nb! database does not generate ids for survey
    if ($survey) {
      if (!array_key_exists('updated', $input)) {
        //$input->{'updated'} = date('Y-m-d G:i:s');
        $input->{'updated'} = "now()";
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = $this->updatedby;
      }
      if (!array_key_exists('starttime', $input)) {
        return false;
      }
      if (!array_key_exists('endtime', $input)) {
        return false;
      }
      if (!array_key_exists('config', $input)) {
        $input->{'config'} = "";
      }
      if (!array_key_exists('status', $input)) {
        $input->{'status'} = "DRAFT";
      }
      if (!array_key_exists('contacts', $input)) {
        $input->{'contacts'} = array();
      }

      // does it exist already
      $sql = "SELECT 1 FROM $dbschm.survey WHERE id = :survey ";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':survey', $survey);
      $sth->execute();
      if ($sth->rowCount() == 0) {
        $sql = "
          INSERT INTO $dbschm.survey (id,updated,updatedby,starttime,endtime,config,status,contacts)
          VALUES (:survey,:updated,:updatedby,:starttime,:endtime,:config,:status,:contacts)
        ";
        $sth = $this->dbh->prepare($sql);
        $sth->bindParam(':survey', $survey);
        $sth->bindParam(':updated', $input->{'updated'});
        $sth->bindParam(':updatedby', $input->{'updatedby'});
        $sth->bindParam(':starttime', $input->{'starttime'});
        $sth->bindParam(':endtime', $input->{'endtime'});
        $_tmp_config = json_encode($input->{'config'});
        $sth->bindParam(':config', $_tmp_config);
        $sth->bindParam(':status', $input->{'status'});
        $_tmp_contacts = json_encode($input->{'contacts'});
        $sth->bindParam(':contacts', $_tmp_contacts);
        $sth->execute();
        //echo $sth->rowCount();
        return $survey; // return given id back indicating success
      }
      return $survey; //most likely existed already, update?
    }
    return false;
  }

  public function updateSurvey($survey,$input) {
    $dbschm = $this->dbschm;
    if ($survey) {
      if (!array_key_exists('updated', $input)) {
        $input->{'updated'} = "now()";
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = $this->updatedby;
      }
      if (!array_key_exists('starttime', $input)) {
        return false;
      }
      if (!array_key_exists('endtime', $input)) {
        return false;
      }
      if (!array_key_exists('config', $input)) {
        $input->{'config'} = "";
      }
      if (!array_key_exists('status', $input)) {
        $input->{'status'} = "DRAFT";
      }
      if (!array_key_exists('contacts', $input)) {
        $input->{'contacts'} = array();
      }

      $sql = "
        UPDATE $dbschm.survey
        SET updated=:updated, updatedby=:updatedby
        , starttime=:starttime, endtime=:endtime
        , config=:config, status=:status, contacts=:contacts
        WHERE id = :survey
      ";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':updated', $input->{'updated'});
      $sth->bindParam(':updatedby', $input->{'updatedby'});
      $sth->bindParam(':starttime', $input->{'starttime'});
      $sth->bindParam(':endtime', $input->{'endtime'});
      $_tmp_config = json_encode($input->{'config'});
      $sth->bindParam(':config', $_tmp_config);
      $sth->bindParam(':status', $input->{'status'});
      $_tmp_contacts = json_encode($input->{'contacts'});
      $sth->bindParam(':contacts', $_tmp_contacts);
      $sth->bindParam(':survey', $survey);
      $sth->execute();
      if ($sth->rowCount()===1) {
        return true;
      }
    }
    return false;
  }

  // nb! this is really scary to do due to cascade delete!
  /*
  public function deleteSurvey($survey) {
    $dbschm = $this->dbschm;
    if ($survey) {
      $sql = "DELETE FROM $dbschm.survey WHERE id = :survey";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':survey', $survey);
      if (!$sth->execute()) return false;
      if ($sth->rowCount() < 1) return false;
      return true;
    }
    return false;
  }
  //*/

  //
  // ADDITIONAL / HELPING FUNCTIONS
  //

  // FLOWENGINE / WATCH / INDIRECT

  // nb! multiuse with API and engine, watch, landbot
  public function selectContactId($phonenumber) {
    $dbschm = $this->dbschm;
    // must fetch all since data is encrypted
    // TO-DO-ish: how to limit? - as first aid let's order by updated for a sooner probable hit
    $sql = "
      SELECT id, contact, iv, optout
      FROM $dbschm.contact
      WHERE 1=1
      ORDER BY updated DESC
    ";

    $sth = $this->dbh->prepare($sql);
    $sth->execute();

    $ret = array();
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => $row) {
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
      }
      if (array_key_exists('contact', $row)) {
        $contactdata = json_decode($this->decrypt($row['contact'],$iv));
        $dataphonenumber = $contactdata->{'phonenumber'};
        if ($dataphonenumber) {
          if ($dataphonenumber == $phonenumber) {
            array_push($ret,array(
              "id" => $row["id"],
              "contact" => $contactdata,
              "iv" => $row["iv"],
              "optout" => $row["optout"]
            ));
            break;
          }
        }
      }
    }
    return $ret;
  }

  public function selectSurveyConfig($survey) {
    $dbschm = $this->dbschm;
    // for listing all data
    $sql = "
      SELECT id,config,starttime,endtime,status
      FROM $dbschm.survey
      WHERE 1=1
    ";
    if ($survey) {
      $sql.= " AND id = :survey ";
    }

    $sth = $this->dbh->prepare($sql);
    if ($survey) {
      $sth->bindParam(':survey', $survey);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }


  // OTHER

  // DEPRECATED
  public function selectContactContactsurveys($contact) {
    $dbschm = $this->dbschm;
    // for listing all latest data
    $sql = "
      SELECT id,updated,updatedby,contact,survey,status
      FROM $dbschm.contactsurvey
      WHERE 1=1
    ";
    //mandatory to limit per contact
    $sql.= "
      AND contact = :contact
      -- take only one (the last one)
      ORDER BY updated DESC
      LIMIT 1
    ";

    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':contact', $contact);
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function selectContactMessages($contact,$auth_user,$impersonate=null) {
    $dbschm = $this->dbschm;

    $sql = "
      SELECT m.id,m.created as updated,m.updatedby,m.contact,m.body,m.sender,m.survey,m.iv
      ,m.created,m.createdby
      ,m.status,m.context
      FROM $dbschm.message m
      WHERE 1=1
      AND (
        -- survey is set and user has access
        (m.contact,m.survey) IN (
          select sn.contact, sn.survey
          from $dbschm.supportneed sn
          where 1=1
          and sn.id = (
            select max(supportneed.id)
            from $dbschm.supportneed
            where supportneed.contact = sn.contact
            and supportneed.survey = sn.survey
          )
          and(1=0
            or (:auth_user) in (select annieuser from $dbschm.usageright_superuser)
            or (:auth_user,sn.survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
            or (:auth_user,sn.survey,sn.category) in (select annieuser,survey,category from $dbschm.usageright_provider)
            or (
              (:auth_user,sn.contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
              and (sn.survey,sn.category) NOT in (select survey,category from $dbschm.usageright_provider)
            )
          )
        ) OR (
        -- survey is NOT set but user still has access
          m.survey is null
          and (1=0
            or (:auth_user) in (select annieuser from $dbschm.usageright_superuser)
            or (
              (:auth_user,m.contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
              --cant negate providers due to no category because of no supportneed via survey
            )
          )
        )
      )
    ";
    if ($contact) {
      $sql.= " AND m.contact = :contact ";
    }

    $sth = $this->dbh->prepare($sql);

    // impersonate: switch UID if...
    if ($impersonate) {
      $sth->bindParam(':auth_user',$impersonate);
    } else {
      $sth->bindParam(':auth_user',$auth_user);
    }
    if ($contact) {
      $sth->bindParam(':contact', $contact);
    }
    $sth->execute();
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
      }
      if (array_key_exists('body', $row)) {
        $row['body'] = $this->decrypt($row['body'],$iv);
      }
      if (array_key_exists('sender', $row)) {
        $row['sender'] = $this->decrypt($row['sender'],$iv);
      }
    }
    return $rows;
  }

  public function selectContactMeta() {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT count(*) contacts
      ,(select count(distinct sn.contact)
        from $dbschm.supportneed sn
        where sn.status!='ACKED'
      ) contactswithissue
      FROM $dbschm.contact co
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function selectAnnieuserSupportneeds($getarr,$auth_user,$impersonate=null) {
    $dbschm = $this->dbschm;

    $in_category       = implode(',', array_fill(0, count($getarr["category"]), '?'));
    $in_status         = implode(',', array_fill(0, count($getarr["status"]), '?'));
    $in_survey         = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    $in_supporttype    = implode(',', array_fill(0, count($getarr["supporttype"]), '?'));
    $in_followuptype   = implode(',', array_fill(0, count($getarr["followuptype"]), '?'));
    $in_followupresult = implode(',', array_fill(0, count($getarr["followupresult"]), '?'));
    //error_log("inQueries: (".$in_category.") / (".$in_status.") / (".$in_survey.")");
    //error_log("inArrays length: ".count(array_merge($getarr["category"],$getarr["status"],$getarr["survey"])));

    // for listing all latest data, excluding archived survey data
    $sql = "
      SELECT sn.id
      , sn.updated, sn.updatedby
      , sn.contact, sn.category, sn.status, sn.survey
      , sn.supporttype, sn.followuptype
      , sn.followupresult
      , (
          select fu.status
          from $dbschm.followup fu
          where fu.supportneed in (
            select id from $dbschm.supportneed
            where contact = sn.contact
            and survey = sn.survey
          )
          -- last one for supportneed (contact):
          and (fu.supportneed,fu.updated) in (
            select supportneed,max(updated)
            from $dbschm.followup
            group by supportneed
          )
          order by fu.updated desc limit 1
        ) as followupstatus
      , su.starttime, su.endtime
      FROM $dbschm.supportneed sn
      JOIN $dbschm.survey su ON su.id = sn.survey
      WHERE 1=1
      AND sn.id = (
        select max(supportneed.id)
        from $dbschm.supportneed
        where supportneed.contact = sn.contact
        and supportneed.survey = sn.survey
      )
      AND coalesce(su.status,'') NOT IN ('ARCHIVED','DELETED')
      AND(1=0
        or (?) in (select annieuser from $dbschm.usageright_superuser)
        or (?,sn.survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
        or (?,sn.survey,sn.category) in (select annieuser,survey,category from $dbschm.usageright_provider)
        or (
            (?,sn.contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
            and (sn.survey,sn.category) NOT in (select survey,category from $dbschm.usageright_provider)
        )
      )
    ";
    if ($in_category)       $sql.= " AND sn.category in ($in_category)";//part of list of strings
    if ($in_status)         $sql.= " AND sn.status in ($in_status)";//part of list of strings
    if ($in_survey)         $sql.= " AND sn.survey in ($in_survey)";//part of list of strings
    if ($in_supporttype)    $sql.= " AND sn.supporttype in ($in_supporttype)";//part of list of strings
    if ($in_followuptype)   $sql.= " AND sn.followuptype in ($in_followuptype)";//part of list of strings
    if ($in_followupresult) $sql.= " AND sn.followupresult in ($in_followupresult)";//part of list of strings

    $sth = $this->dbh->prepare($sql);
    // bind parameters/values for both queries (length of array matters)
    $sqlparams = [];
    // impersonate: switch UID if...
    if ($impersonate) {
      $sqlparams = array_merge($sqlparams,[$impersonate,$impersonate,$impersonate,$impersonate]);
    } else {
      $sqlparams = array_merge($sqlparams,[$auth_user,$auth_user,$auth_user,$auth_user]);
    }
    $sth->execute(array_merge(
      $sqlparams,
      $getarr["category"],$getarr["status"],$getarr["survey"],
      $getarr["supporttype"],$getarr["followuptype"],
      $getarr["followupresult"]
    ));
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  // DEPRECATED
  public function selectSupportneedsPage($contact,$history,$getarr,$auth_user,$impersonate=null) {
    $dbschm = $this->dbschm;

    $in_category     = implode(',', array_fill(0, count($getarr["category"]), '?'));
    $in_status       = implode(',', array_fill(0, count($getarr["status"]), '?'));
    $in_survey       = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    $in_supporttype  = implode(',', array_fill(0, count($getarr["supporttype"]), '?'));
    $in_followuptype = implode(',', array_fill(0, count($getarr["followuptype"]), '?'));
    //error_log("inQueries: (".$in_category.") / (".$in_status.") / (".$in_survey.")";
    //error_log("inArrays length: ".count(array_merge($getarr["category"],$getarr["status"],$getarr["survey"])));
    
    // for listing all latest data
    $sql = "
      SELECT sn.id
      ,sn.updated,sn.updatedby
      ,sn.contact,sn.category,sn.status,sn.survey
      ,sn.supporttype,sn.followuptype
      ,su.starttime,su.endtime
      FROM $dbschm.supportneed sn
      JOIN $dbschm.survey su ON su.id=sn.survey
      WHERE 1=1
      AND coalesce(su.status,'') NOT IN ('ARCHIVED','DELETED')
      -- latest supportneed row (no history)
      and sn.id = (
        select max(id) from $dbschm.supportneed
        where contact = sn.contact and survey = sn.survey
      )
      AND(1=0
        or (?) in (select annieuser from $dbschm.usageright_superuser)
        or (?,sn.survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
        or (?,sn.survey,sn.category) in (select annieuser,survey,category from $dbschm.usageright_provider)
        or (
            (?,sn.contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
            and (sn.survey,sn.category) NOT in (select survey,category from $dbschm.usageright_provider)
        )
      )
    ";
    if ($contact)         $sql.= " AND sn.contact = ?";
    if ($in_category)     $sql.= " AND sn.category in ($in_category)";//part of list of strings
    if ($in_status)       $sql.= " AND sn.status in ($in_status)";//part of list of strings
    if ($in_survey)       $sql.= " AND sn.survey in ($in_survey)";//part of list of strings
    if ($in_supporttype)  $sql.= " AND sn.supporttype in ($in_supporttype)";//part of list of strings
    if ($in_followuptype) $sql.= " AND sn.followuptype in ($in_followuptype)";//part of list of strings

    if ($history) {
      // take entire history
      $sql = "
        SELECT sn.id
        ,sn.updated,sn.updatedby
        ,sn.contact,sn.category,sn.status,sn.survey
        ,sn.supporttype,sn.followuptype
        ,su.starttime,su.endtime
        FROM $dbschm.supportneed sn
        JOIN $dbschm.survey su ON su.id=sn.survey
        WHERE 1=1
        AND(1=0
          or (?) in (select annieuser from $dbschm.usageright_superuser)
          or (?,sn.survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
          or (?,sn.survey,sn.category) in (select annieuser,survey,category from $dbschm.usageright_provider)
          or (
            (?,sn.contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
            and (sn.survey,sn.category) NOT in (select survey,category from $dbschm.usageright_provider)
          )
        )
        AND sn.contact = ?
      ";
    }

    $sth = $this->dbh->prepare($sql);
    // bind parameters/values for both queries (length of array matters)
    $sqlparams = [];
    // impersonate: switch UID if...
    if ($impersonate) {
      $sqlparams = array_merge($sqlparams,[$impersonate,$impersonate,$impersonate,$impersonate]);
    } else {
      $sqlparams = array_merge($sqlparams,[$auth_user,$auth_user,$auth_user,$auth_user]);
    }
    if ($contact) {
      $sqlparams = array_merge($sqlparams,[$contact]);
    }
    $sth->execute(array_merge(
      $sqlparams,
      $getarr["category"],$getarr["status"],$getarr["survey"],
      $getarr["supporttype"],$getarr["followuptype"]
    ));
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function selectSupportneedSupportneedcomments($supportneedid,$getarr,$auth_user,$impersonate=null) {
    $dbschm = $this->dbschm;

    $sql = "
      SELECT id,updated,updatedby,supportneed,body,iv
      FROM $dbschm.supportneedcomment
      WHERE 1=1
      -- user has access (to referenced supportneed)
      AND supportneed IN (
        select sn.id
        from $dbschm.supportneed sn
        where 1=0
        or (:auth_user) in (select annieuser from $dbschm.usageright_superuser)
        or (:auth_user,sn.survey) in (select annieuser,survey from $dbschm.usageright_coordinator)
        or (:auth_user,sn.survey,sn.category) in (select annieuser,survey,category from $dbschm.usageright_provider)
        or (
          (:auth_user,sn.contact) in (select annieuser,teacherfor from $dbschm.usageright_teacher)
          and (sn.survey,sn.category) NOT in (select survey,category from $dbschm.usageright_provider)
        )
      )
    ";
    if ($supportneedid) {
      $sql.= "
      AND supportneed IN (
        select b.id --b has them all
        from $dbschm.supportneed a
        left join $dbschm.supportneed b
          on b.contact=a.contact
          -- and b.category=a.category
          and b.survey=a.survey
        where a.id = :supportneed
      )
    ";
    }
    $sth = $this->dbh->prepare($sql);

    // impersonate: switch UID if...
    if ($impersonate) {
      $sth->bindParam(':auth_user',$impersonate);
    } else {
      $sth->bindParam(':auth_user',$auth_user);
    }
    if ($supportneedid) {
      $sth->bindParam(':supportneed', $supportneedid);
    }
    $sth->execute();
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      $iv = null;
      if (array_key_exists('iv', $row)) {
        $iv = base64_decode($row['iv']);
      }
      if (array_key_exists('body', $row)) {
        $row['body'] = $this->decrypt($row['body'],$iv);
      }
    }
    return $rows;
  }

}//class

?>