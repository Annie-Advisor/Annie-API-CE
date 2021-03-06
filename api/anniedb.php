<?php
/* anniedb.php
 * Copyright (c) 2019-2021 Annie Advisor
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
  const VERSION = "1.1.1";

  //
  // VARIABLES
  //

  private $dbh;
  private $dbhost, $dbport, $dbname, $dbschm, $dbuser, $dbpass;
  private $salt;
  private $cipher;

  //
  // CONSTRUCTORS & DESTRUCTORS
  //

  public function __construct($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt) {
    global $auth_uid;
    $this->auth_uid = $auth_uid;
    $this->dbhost = $dbhost;
    $this->dbport = $dbport;
    $this->dbname = $dbname;
    $this->dbschm = $dbschm;
    $this->dbuser = $dbuser;
    $this->dbpass = $dbpass;
    $this->salt   = $salt;
    $this->cipher = "aes-256-cbc";

    try {
      $this->dbh = new PDO("pgsql: host=$this->dbhost; port=$this->dbport; dbname=$this->dbname", $this->dbuser, $this->dbpass);
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
    $sql = "SELECT id, meta, superuser, iv FROM $dbschm.annieuser";
    if ($id) {
      $sql.= " WHERE id = :id ";
    }
    // excecute SQL statement
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

  public function insertAnnieuser($id,$input) {
    $dbschm = $this->dbschm;
    if (!array_key_exists('id', $input) || $input->{'id'}=="") {
      if (!$id) {
        return false;
      }
      $input->{'id'} = $id;
    }
    // default if missing
    if (!array_key_exists('meta', $input)) {
      $input->{'meta'} = "";
    }
    if (!array_key_exists('superuser', $input)) {
      $input->{'superuser'} = false;
    }
    //iv
    if (!array_key_exists('validuntil', $input)) {
      $input->{'validuntil'} = null;
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
    }
    if (!array_key_exists('created', $input)) {
      $input->{'created'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('createdby', $input)) {
      $input->{'createdby'} = "Annie";
    }
    // encrypt:
    $ivlen = openssl_cipher_iv_length($this->cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $enc_meta = $this->encrypt($input->{'meta'},$iv);
    $enc_iv = base64_encode($iv);//store $iv for decryption later
    //-encrypt
    $sql = "
      INSERT INTO $dbschm.annieuser (id,meta,superuser,iv,validuntil,updated,updatedby,created,createdby)
      VALUES (:id,:meta,:superuser,:iv,:validuntil,:updated,:updatedby,:created,:createdby)
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':id', $input->{'id'});
    $sth->bindParam(':meta', $enc_meta);
    $sth->bindParam(':superuser', $input->{'superuser'});
    $sth->bindParam(':iv', $enc_iv);
    $sth->bindParam(':validuntil', $input->{'validuntil'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':created', $input->{'created'});
    $sth->bindParam(':createdby', $input->{'createdby'});
    if (!$sth->execute()) return false;
    if ($sth->rowCount() < 1) return false;
    return $input->{'id'}; //nb! return the ID of inserted row
  }

  public function updateAnnieuser($id,$input) {
    $dbschm = $this->dbschm;
    // default if missing
    if (!array_key_exists('meta', $input)) {
      $input->{'meta'} = "";
    }
    if (!array_key_exists('superuser', $input)) {
      $input->{'superuser'} = false;
    }
    //iv
    if (!array_key_exists('validuntil', $input)) {
      $input->{'validuntil'} = null;
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
    }
    // encrypt:
    $ivlen = openssl_cipher_iv_length($this->cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $enc_meta = $this->encrypt($input->{'meta'},$iv);
    $enc_iv = base64_encode($iv);//store $iv for decryption later
    //-encrypt
    $sql = "
      UPDATE $dbschm.annieuser
      SET meta=:meta, superuser=:superuser, iv=:iv, validuntil=:validuntil, updated=:updated, updatedby=:updatedby
      WHERE id=:id
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':meta', $enc_meta);
    $sth->bindParam(':superuser', $input->{'superuser'});
    $sth->bindParam(':iv', $enc_iv);
    $sth->bindParam(':validuntil', $input->{'validuntil'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) return false;
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function deleteAnnieuser($id) {
    $dbschm = $this->dbschm;
    if (!$id) {
      return false;
    }
    $sql = "DELETE FROM $dbschm.annieuser WHERE id = :id";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) return false;
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function selectAnnieusersurvey($id) {
    $dbschm = $this->dbschm;
    $sql = "SELECT id, annieuser, survey, meta FROM $dbschm.annieusersurvey";
    if ($id) {
      $sql.= " WHERE id = :id ";
      //todo: annieuser = :annieuser
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($id) {
      $sth->bindParam(':id', $id);
    }
    $sth->execute();
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      if (array_key_exists('meta', $row)) {
        $row['meta'] = json_decode($row['meta']);
      }
    }
    return $rows;
  }

  public function insertAnnieusersurvey($input) { // nb! no id, generated!
    $dbschm = $this->dbschm;
    if (!array_key_exists('annieuser', $input) || $input->{'annieuser'}=="") {
      return false;
    }
    if (!array_key_exists('survey', $input) || $input->{'survey'}=="") {
      return false;
    }
    if (!array_key_exists('meta', $input)) {
      $input->{'meta'} = "";
    }
    // default if missing
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
    }
    $sql = "
      INSERT INTO $dbschm.annieusersurvey (annieuser,survey,meta,updated,updatedby)
      VALUES (:annieuser,:survey,:meta,:updated,:updatedby)
    ";
    $sth = $this->dbh->prepare($sql);
    //$sth->bindParam(':id', $input->{'id'});
    $sth->bindParam(':annieuser', $input->{'annieuser'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':meta', $input->{'meta'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    if (!$sth->execute()) return false;
    if ($sth->rowCount() < 1) return false;
    return $this->dbh->lastInsertId(); //nb! return the ID of inserted row
  }

  public function updateAnnieusersurvey($id,$input) {
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
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
    }
    $sql = "
      UPDATE $dbschm.annieusersurvey
      SET annieuser=:annieuser, survey=:survey, meta=:meta, updated=:updated, updatedby=:updatedby
      WHERE id=:id
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':annieuser', $input->{'annieuser'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':meta', $input->{'meta'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) return false;
    if ($sth->rowCount() < 1) return false;
    return true;
  }

  public function deleteAnnieusersurvey($id) {
    $dbschm = $this->dbschm;
    if (!$id) {
      return false;
    }
    $sql = "DELETE FROM $dbschm.annieusersurvey WHERE id = :id";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':id', $id);
    if (!$sth->execute()) return false;
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
      GROUP BY codeset
    ";
    if ($codeset) {
      $sql = "
        SELECT jsonb_object_agg(code,value) as json
        FROM $dbschm.codes
        WHERE codeset = :codeset
      ";
      if ($code) {
        $sql = "
          SELECT value as json
          FROM $dbschm.codes
          WHERE codeset = :codeset
          AND code = :code
        ";
      }
    }
    // prepare SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($codeset) {
      $sth->bindParam(':codeset', $codeset);
    }
    if ($code) {
      $sth->bindParam(':code', $code);
    }
    // excecute SQL statement
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

  public function selectContact($contact) {
    $dbschm = $this->dbschm;
    $sql = "SELECT id,contact,iv FROM $dbschm.contact ";
    if ($contact) {
      $sql.= " WHERE id = :contact ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
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
      if (array_key_exists('contact', $row)) {
        $row['contact'] = json_decode($this->decrypt($row['contact'],$iv));
      }
    }
    return $rows;
  }

  // no plan to insert or update contact data from here

  public function selectContactsurvey($contact) {
    $dbschm = $this->dbschm;
    // for listing all latest data
    $sql = "
      SELECT id,updated,updatedby,contact,survey,status
      FROM $dbschm.contactsurvey
    ";
    if ($contact) {
      $sql.= "
      WHERE contact = :contact
      -- take only one (the last one) for contact (NB! not used! see supportneed API)
      ORDER BY updated DESC
      LIMIT 1
    ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($contact) {
      $sth->bindParam(':contact', $contact);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function insertContactsurvey($contact,$input) {
    $dbschm = $this->dbschm;
    // contact can not be missing
    if (!$contact) {
      return false;
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
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
    $sth->bindParam(':contact', $contact);
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->execute();
    return $this->dbh->lastInsertId();
  }

  public function deleteContactsurvey($contactsurvey) {
    $dbschm = $this->dbschm;
    if ($contactsurvey) {
      $sql = "DELETE FROM $dbschm.contactsurvey WHERE id = :contactsurvey";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':contactsurvey', $contactsurvey);
      $sth->execute();
      //echo $sth->rowCount();
      return true;
    }
    return false;
  }

  // no "normal* selectMessage (using selectContactMessages instead)

  public function insertMessage($contact,$input) {
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
      if (!$contact) {
        return false;
      }
      $input->{'contact'} = $contact;
    }
    if (!array_key_exists('created', $input)) {
      $input->{'created'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('createdby', $input)) {
      $input->{'createdby'} = "Annie";
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
    }
    if (!array_key_exists('body', $input)) {
      $input->{'body'} = "";
    }
    if (!array_key_exists('sender', $input)) {
      $input->{'sender'} = "";
    }
    if (!array_key_exists('survey', $input)) {
      return false;
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
      INSERT INTO $dbschm.message (id,updated,updatedby,contact,body,sender,survey,status,created,createdby,iv)
      VALUES (:message,:updated,:updatedby,:contact,:body,:sender,:survey,:status,:created,:createdby,:iv)
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':message', $input->{'id'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':body', $enc_body);
    $sth->bindParam(':sender', $enc_sender);
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->bindParam(':created', $input->{'created'});
    $sth->bindParam(':createdby', $input->{'createdby'});
    $sth->bindParam(':iv', $enc_iv);
    if (!$sth->execute()) return false;
    //if ($sth->rowCount() < 1) return false;
    return $input->{'id'}; //nb! return the ID of inserted row
  }

  public function updateMessage($message,$input) {
    $dbschm = $this->dbschm;
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
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

  public function deleteMessage($message) {
    $dbschm = $this->dbschm;
    if (!$message) {
      return false;
    }
    $sql = "DELETE FROM $dbschm.message WHERE id = :message";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':message', $message);
    $sth->execute();
    //to-do-ish: check count?
    return true;
  }


  //to-do-ish: "normal" selectSupportneed (using selectSupportneedsPage instead)

  public function insertSupportneed($contact,$input) {
    $dbschm = $this->dbschm;
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
    }
    if (!array_key_exists('updatedby', $input)) {
      $input->{'updatedby'} = "Annie";
    }
    if (!array_key_exists('contact', $input)) {
      $input->{'contact'} = $contact;
    }
    if (!array_key_exists('category', $input)) {
      $input->{'category'} = "Z";//unknown
    }
    if (!array_key_exists('status', $input)) {
      $input->{'status'} = "1";//New
    }
    if (!array_key_exists('survey', $input)) {
      return false;
    }
    if (!array_key_exists('userrole', $input)) {
      $input->{'userrole'} = "";
    }

    $sql = "
      DELETE FROM $dbschm.supportneed
      WHERE contact = :contact AND survey = :survey
    ";//NB! used to be per category and survey (and ofc contact)
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->execute();

    $sql = "
      INSERT INTO $dbschm.supportneed (updated,updatedby,contact,category,status,survey,userrole)
      VALUES (:updated,:updatedby,:contact,:category,:status,:survey,:userrole)
    ";
    $sth = $this->dbh->prepare($sql);
    //$sth->bindParam(1, $input->{'id'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':category', $input->{'category'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':userrole', $input->{'userrole'});
    $sth->execute();
    $input->{'id'} = $this->dbh->lastInsertId(); //works without parameter as the id column is clear

    $sql = "
      INSERT INTO $dbschm.supportneedhistory (id,updated,updatedby,contact,category,status,survey,userrole)
      VALUES (:supportneed,:updated,:updatedby,:contact,:category,:status,:survey,:userrole)
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':supportneed', $input->{'id'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':category', $input->{'category'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':userrole', $input->{'userrole'});
    $sth->execute();
    return $input->{'id'}; //nb! return the ID of inserted row
  }

  public function deleteSupportneed($supportneed) {
    $dbschm = $this->dbschm;
    if ($supportneed) {
      $sql = "DELETE FROM $dbschm.supportneed WHERE id = :supportneed ";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':supportneed', $supportneed);
      $sth->execute();
      //echo $sth->rowCount();
      return true;
    }
    return false;
  }

  public function selectSupportneedcomment($supportneed) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,updated,updatedby,supportneed,body,iv
      FROM $dbschm.supportneedcomment
    ";
    if ($supportneed) {
      $sql.= "
      WHERE supportneed IN (
        select b.id --b has them all
        from $dbschm.supportneedhistory a
        left join $dbschm.supportneedhistory b
          on b.contact=a.contact
          -- and b.category=a.category
          and b.survey=a.survey
        where a.id = :supportneed
      )
    ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($supportneed) {
      $sth->bindParam(':supportneed', $supportneed);
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

  public function insertSupportneedcomment($supportneed,$input) {
    $dbschm = $this->dbschm;
    if ($supportneed && $input) {
      // database generated
      //if (!array_key_exists('id', $input)) {
      //  http_response_code(400); // bad request
      //  exit;
      //}
      if (!array_key_exists('updated', $input)) {
        //$input->{'updated'} = date('Y-m-d G:i:s');
        $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = "Annie";
      }
      // with $supportneed
      //if (!array_key_exists('supportneed', $input)) {
      //  http_response_code(400); // bad request
      //  exit;
      //}
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
      $sth->bindParam(':supportneed', $supportneed);
      $sth->bindParam(':body', $enc_body);
      $sth->bindParam(':iv', $enc_iv);
      $sth->execute();
      //echo $sth->rowCount();
      return $this->dbh->lastInsertId(); //works without parameter as the id column is clear
    }
    return false;
  }

  public function deleteSupportneedcomment($supportneedcomment) {
    $dbschm = $this->dbschm;
    if ($supportneedcomment) {
      $sql = "DELETE FROM $dbschm.supportneedcomment WHERE id = :supportneedcomment";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':supportneedcomment', $supportneedcomment);
      $sth->execute();
      //echo $sth->rowCount();
      return true;
    }
    return false;
  }

  public function selectSurvey($survey) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,updated,updatedby,starttime,endtime,config,status,contacts
      FROM $dbschm.survey
    ";
    if ($survey) {
      $sql.= " WHERE id = :survey ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($survey) {
      $sth->bindParam(':survey', $survey);
    }
    $sth->execute();
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
        $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = "Annie";
      }
      if (!array_key_exists('starttime', $input)) {
        return false;
      }
      if (!array_key_exists('endtime', $input)) {
        return false;
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
        $sth->bindParam(':config', $input->{'config'});
        $sth->bindParam(':status', $input->{'status'});
        $sth->bindParam(':contacts', $input->{'contacts'});
        $sth->execute();
        //echo $sth->rowCount();
        return $survey; // return given id back indicating success
      }
      return $survey; //most likely existed already
    }
    return false;
  }


  public function updateSurvey($survey,$input) {
    $dbschm = $this->dbschm;
    if ($survey) {
      if (!array_key_exists('updated', $input)) {
        $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v");
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = "Annie";
      }
      if (!array_key_exists('starttime', $input)) {
        return false;
      }
      if (!array_key_exists('endtime', $input)) {
        return false;
      }
      if (!array_key_exists('config', $input)) {
        $input->{'config'} = null;
      }
      if (!array_key_exists('status', $input)) {
        $input->{'status'} = null;
      }
      if (!array_key_exists('contacts', $input)) {
        $input->{'contacts'} = null;
      }

      // does it exist already
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
      $sth->bindParam(':config', $input->{'config'});
      $sth->bindParam(':status', $input->{'status'});
      $sth->bindParam(':contacts', $input->{'contacts'});
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
      $sth->execute();
      //echo $sth->rowCount();
      return true;
    }
    return false;
  }
  //*/

  //
  // ADDITIONAL / HELPING FUNCTIONS
  //

  // FLOWENGINE / WATCH

  public function selectContactId($phonenumber) {
    $dbschm = $this->dbschm;
    // must fetch all since queried data is encrypted (todo how to limit)
    $sql = "SELECT id, contact, iv FROM $dbschm.contact ";
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
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
        $contactdata = json_decode($this->decrypt($row['contact'],$iv));
        $dataphonenumber = $contactdata->{'phonenumber'};
        if ($dataphonenumber) {
          if ($dataphonenumber == $phonenumber) {
            array_push($ret,["id" => $row["id"]]);
            break;
          }
        }
      }
    }
    return $ret;
  }

  public function selectLastContactsurvey($contact) {
    $dbschm = $this->dbschm;
    if ($contact) {
      // for listing all latest data
      // take only one (the last one) for contact
      $sql = "
        SELECT cs.id,cs.updated,cs.updatedby,cs.contact
        , case
          -- grace period, delay for last message, choose whatever was -active- recently
          when cs.updated > current_timestamp - make_interval(hours := cast(config.value as int)) then cs.survey
          -- contactsurvey has ended -> Y
          when cs.status='100' then 'Y'
          -- entire survey has ended -> Y
          when su.endtime < current_timestamp then 'Y'
          else cs.survey
          end survey
        , case
          -- grace period, delay for last message, choose whatever was -active- recently
          when cs.updated > current_timestamp - make_interval(hours := cast(config.value as int)) then cs.status
          -- contactsurvey has ended -> start (Y)
          when cs.status='100' then 'Y'
          -- entire survey has ended -> start (Y)
          when su.endtime < current_timestamp then 'Y'
          else cs.status
          end status -- or category!
        FROM $dbschm.contactsurvey cs
        JOIN $dbschm.survey su on su.id=cs.survey
        JOIN $dbschm.config ON config.segment='survey' and config.field='lastMessageDelay'
        WHERE cs.contact = :contact
        ORDER BY updated DESC LIMIT 1
      ";
      // excecute SQL statement
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':contact', $contact);
      $sth->execute();
      return $sth->fetchAll(PDO::FETCH_ASSOC);
    }
    return false;
  }

  public function selectSurveyConfig($survey) {
    $dbschm = $this->dbschm;
    // for listing all data
    $sql = "SELECT id,config,starttime,endtime,status FROM $dbschm.survey WHERE 1=1 ";
    if ($survey) {
      $sql.= " AND id = :survey ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($survey) {
      $sth->bindParam(':survey', $survey);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  // OTHER

  public function selectContactMessages($contact) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT m.id,m.updated,m.updatedby,m.contact,m.body,m.sender,m.survey,m.iv
      ,m.created,m.createdby
      FROM $dbschm.message m
      WHERE (m.contact,m.survey) in (
        select sn.contact, sn.survey
        from $dbschm.supportneed sn
        where(1=0
          or (
            select au.superuser
            from $dbschm.annieuser au
            where au.id = :auth_uid
          )
          or (
            select j.value::boolean
            from $dbschm.annieusersurvey aus
            cross join jsonb_each(aus.meta->'category') j
            where aus.meta is not null and aus.meta->'category' is not null
            and aus.annieuser = :auth_uid
            and aus.survey = sn.survey
            and j.key = sn.category
          )
        )
      )
    ";
    if ($contact) {
      $sql.= " AND m.contact = :contact ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':auth_uid',$this->auth_uid);
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

  public function selectContactMeta($contact) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT count(*) contacts
      ,(select count(distinct sn.contact)
        from $dbschm.supportneed sn
        where sn.status!='100'
      ) contactswithissue
      FROM $dbschm.contact co
    ";
    if ($contact) {
      $sql.= " WHERE id = :contact ";
    }
    // prepare SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($contact) {
      $sth->bindParam(':contact', $contact);
    }
    // excecute SQL statement
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function selectSupportneedsPage($contact,$history,$getarr) {
    $dbschm = $this->dbschm;

    $in_category = implode(',', array_fill(0, count($getarr["category"]), '?'));
    $in_status   = implode(',', array_fill(0, count($getarr["status"]), '?'));
    $in_survey   = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    $in_userrole = implode(',', array_fill(0, count($getarr["userrole"]), '?'));
    //error_log("inQueries: (".$in_category.") / (".$in_status.") / (".$in_survey.") / (".$in_userrole.")");
    //error_log("inArrays length: ".count(array_merge($getarr["category"],$getarr["status"],$getarr["survey"],$getarr["userrole"])));

    // for listing all latest data
    $sql = "
      SELECT sn.id
      ,sn.updated,sn.updatedby
      ,sn.contact,sn.category,sn.status,sn.survey
      ,sn.userrole
      ,su.starttime,su.endtime
      FROM $dbschm.supportneed sn
      JOIN $dbschm.survey su ON su.id=sn.survey
      WHERE 1=1
      AND(1=0
        or (
          select au.superuser
          from $dbschm.annieuser au
          where au.id = ?
        )
        or (
          select j.value::boolean
          from $dbschm.annieusersurvey aus
          cross join jsonb_each(aus.meta->'category') j
          where aus.meta is not null and aus.meta->'category' is not null
          and aus.annieuser = ?
          and aus.survey = sn.survey
          and j.key = sn.category
        )
      )
    ";
    if ($contact)      $sql.= " AND sn.contact = ?";
    if ($in_category)  $sql.= " AND sn.category in ($in_category)";//part of list of strings
    if ($in_status)    $sql.= " AND sn.status in ($in_status)";//part of list of strings
    if ($in_survey)    $sql.= " AND sn.survey in ($in_survey)";//part of list of strings
    if ($in_userrole)  $sql.= " AND sn.userrole in ($in_userrole)";//part of list of strings

    if ($history) {
      // take only history, since the last one is there also!
      $sql = "
        SELECT sn.id
        ,sn.updated,sn.updatedby
        ,sn.contact,sn.category,sn.status,sn.survey
        ,sn.userrole
        ,su.starttime,su.endtime
        FROM $dbschm.supportneedhistory sn
        JOIN $dbschm.survey su ON su.id=sn.survey
        WHERE 1=1
        AND(1=0
          or (
            select au.superuser
            from $dbschm.annieuser au
            where au.id = ?
          )
          or (
            select j.value::boolean
            from $dbschm.annieusersurvey aus
            cross join jsonb_each(aus.meta->'category') j
            where aus.meta is not null and aus.meta->'category' is not null
            and aus.annieuser = ?
            and aus.survey = sn.survey
            and j.key = sn.category
          )
        )
        AND sn.contact = ?
      ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    // bind parameters/values for both queries (length of array matters)
    // first "key" as named then the rest of them from specialized array(s) for each '?'
    $sqlparams = [];
    $sqlparams = array_merge($sqlparams,[$this->auth_uid,$this->auth_uid]);
    if ($contact) {
      $sqlparams = array_merge($sqlparams,[$contact]);
    }
    $sth->execute(array_merge(
      $sqlparams,
      $getarr["category"],$getarr["status"],$getarr["survey"],
      $getarr["userrole"]
    ));
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

}//class

?>