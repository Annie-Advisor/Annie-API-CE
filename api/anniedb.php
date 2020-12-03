<?php
/* anniedb.php
 * Copyright (c) 2020 Annie Advisor
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
  const VERSION = "1.0.0";

  //
  // VARIABLES
  //

  private $dbh;
  private $dbhost, $dbport, $dbname, $dbschm, $dbuser, $dbpass;

  //
  // CONSTRUCTORS & DESTRUCTORS
  //

  public function __construct($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass) {
    $this->dbhost = $dbhost;
    $this->dbport = $dbport;
    $this->dbname = $dbname;
    $this->dbschm = $dbschm;
    $this->dbuser = $dbuser;
    $this->dbpass = $dbpass;

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
  // ACCESSORIES
  //

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
    $sql = "SELECT contact FROM $dbschm.contact ";
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
      if (array_key_exists('contact', $row)) {
        $row['contact'] = json_decode($row['contact']);
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
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v")." UTC"; //TODO figure out TZ thingy
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

  public function selectMessage($message) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,updated,updatedby,contact,body,sender,survey
      FROM $dbschm.message
    ";
    if ($message) {
      $sql.= " WHERE id = :message ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($message) {
      $sth->bindParam(':message', $message);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

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
      $input->{'created'} = date_format(date_create(),"Y-m-d H:i:s.v")." UTC"; //TODO figure out TZ thingy
    }
    if (!array_key_exists('createdby', $input)) {
      $input->{'createdby'} = "Annie";
    }
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v")." UTC"; //TODO figure out TZ thingy
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

    $sql = "
      INSERT INTO $dbschm.message (id,updated,updatedby,contact,body,sender,survey,status,created,createdby)
      VALUES (:message,:updated,:updatedby,:contact,:body,:sender,:survey,:status,:created,:createdby)
    ";
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':message', $input->{'id'});
    $sth->bindParam(':updated', $input->{'updated'});
    $sth->bindParam(':updatedby', $input->{'updatedby'});
    $sth->bindParam(':contact', $input->{'contact'});
    $sth->bindParam(':body', $input->{'body'});
    $sth->bindParam(':sender', $input->{'sender'});
    $sth->bindParam(':survey', $input->{'survey'});
    $sth->bindParam(':status', $input->{'status'});
    $sth->bindParam(':created', $input->{'created'});
    $sth->bindParam(':createdby', $input->{'createdby'});
    if (!$sth->execute()) return false;
    //if ($sth->rowCount() < 1) return false;
    return $input->{'id'}; //nb! return the ID of inserted row
  }

  public function updateMessage($message,$input) {
    $dbschm = $this->dbschm;
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v")." UTC"; //TODO figure out TZ thingy
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


  //to-do-ish: "normal" selectSupportneed

  public function insertSupportneed($contact,$input) {
    $dbschm = $this->dbschm;
    if (!array_key_exists('updated', $input)) {
      $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v")." UTC"; //TODO figure out TZ thingy
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
      SELECT id,updated,updatedby,supportneed,body
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
    return $sth->fetchAll(PDO::FETCH_ASSOC);
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
        $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v")." UTC"; //TODO figure out TZ thingy
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

      $sql = "
        INSERT INTO $dbschm.supportneedcomment (updated,updatedby,supportneed,body)
        VALUES (:updated,:updatedby,:supportneed,:body)
      ";
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':updated', $input->{'updated'});
      $sth->bindParam(':updatedby', $input->{'updatedby'});
      $sth->bindParam(':supportneed', $supportneed);
      $sth->bindParam(':body', $input->{'body'});
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
      SELECT id,updated,updatedby,messageflow,starttime,endtime
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
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function insertSurvey($survey,$input) {
    $dbschm = $this->dbschm;
    //nb! database does not generate ids for survey
    if ($survey) {
      if (!array_key_exists('updated', $input)) {
        //$input->{'updated'} = date('Y-m-d G:i:s');
        $input->{'updated'} = date_format(date_create(),"Y-m-d H:i:s.v")." UTC"; //TODO figure out TZ thingy
      }
      if (!array_key_exists('updatedby', $input)) {
        $input->{'updatedby'} = "Annie";
      }
      if (!array_key_exists('messageflow', $input)) {
        return false;
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
          INSERT INTO $dbschm.survey (id,updated,updatedby,messageflow,starttime,endtime)
          VALUES (:survey,:updated,:updatedby,:messageflow,:starttime,:endtime)
        ";
        $sth = $this->dbh->prepare($sql);
        $sth->bindParam(':survey', $survey);
        $sth->bindParam(':updated', $input->{'updated'});
        $sth->bindParam(':updatedby', $input->{'updatedby'});
        $sth->bindParam(':messageflow', $input->{'messageflow'});
        $sth->bindParam(':starttime', $input->{'starttime'});
        $sth->bindParam(':endtime', $input->{'endtime'});
        $sth->execute();
        //echo $sth->rowCount();
        return $survey; // return given id back indicating success
      }
      return $survey; //most likely existed already
    }
    return false;
  }

  //
  // ADDITIONAL / HELPING FUNCTIONS
  //

  public function selectContactKey($phonenumber) {
    $dbschm = $this->dbschm;
    $sql = "SELECT id as key FROM $dbschm.contact WHERE contact->>'phonenumber' = :phonenumber ";
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    $sth->bindParam(':phonenumber', $phonenumber);
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function selectContactLastContactsurvey($contact) {
    $dbschm = $this->dbschm;
    if ($contact) {
      // for listing all latest data
      // take only one (the last one) for contact
      $sql = "SELECT id,updated,updatedby,contact,survey,status FROM $dbschm.contactsurvey ";
      $sql.= " WHERE contact = :contact ";
      $sql.= " ORDER BY updated DESC LIMIT 1 ";
      // excecute SQL statement
      $sth = $this->dbh->prepare($sql);
      $sth->bindParam(':contact', $contact);
      $sth->execute();
      return $sth->fetchAll(PDO::FETCH_ASSOC);
    }
    return false;
  }

  public function selectContactMessages($contact) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,updated,updatedby,contact,body,sender,survey
      FROM $dbschm.message
    ";
    if ($contact) {
      $sql.= " WHERE contact = :contact ";
    }
    // excecute SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($contact) {
      $sth->bindParam(':contact', $contact);
    }
    $sth->execute();
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function selectContactAndSupportneeds($contact) {
    $dbschm = $this->dbschm;
    $sql = "
      SELECT id,contact
      ,(select array_to_json(array_agg(t))
        from (
          select sn.*,sv.messageflow,sv.starttime,sv.endtime
          from $dbschm.supportneed sn
          join $dbschm.survey sv on sv.id=sn.survey
          where sn.contact=contact.id
        ) t
      ) as supportneeds
      FROM $dbschm.contact
    ";
    if ($contact) {
      $sql.= " WHERE id = :contact";
    }
    // prepare SQL statement
    $sth = $this->dbh->prepare($sql);
    if ($contact) {
      $sth->bindParam(':contact', $contact);
    }
    // excecute SQL statement
    $sth->execute();
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      if (array_key_exists('contact', $row)) {
        $row['contact'] = json_decode($row['contact']);
      }
      if (array_key_exists('supportneeds', $row)) {
        $row['supportneeds'] = json_decode($row['supportneeds']);
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

  public function selectContactSupportneeds($contact,$history,$getarr) {
    $dbschm = $this->dbschm;

    $in_category = implode(',', array_fill(0, count($getarr["category"]), '?'));
    $in_status   = implode(',', array_fill(0, count($getarr["status"]), '?'));
    $in_survey   = implode(',', array_fill(0, count($getarr["survey"]), '?'));
    $in_userrole = implode(',', array_fill(0, count($getarr["userrole"]), '?'));
    $in_degree   = implode(',', array_fill(0, count($getarr["degree"]), '?'));
    $in_group    = implode(',', array_fill(0, count($getarr["group"]), '?'));
    $in_location = implode(',', array_fill(0, count($getarr["location"]), '?'));
    //error_log("inQueries: (".$in_category.") / (".$in_status.") / (".$in_survey.") / (".$in_userrole.") // (".$in_degree.") / (".$in_group.") / (".$in_location.")");
    //error_log("inArrays length: ".count(array_merge($getarr["category"],$getarr["status"],$getarr["survey"],$getarr["userrole"], $getarr["degree"],$getarr["group"],$getarr["location"])));

    // for listing all latest data
    // nb! surveystatus subquery, which is actually contact+survey based, we intentionally get per contact here
    //     table also contains all history so we want to limit the result to the one that is latest for contact.
    //     note also default value of '100' meaning resolved (todo: should fetch from codes table, actually)
    $sql = "
      SELECT sn.id
      ,sn.updated,sn.updatedby
      ,sn.contact,sn.category,sn.status,sn.survey
      ,sn.userrole
      ,su.messageflow,su.starttime,su.endtime
      ,coalesce((
        select cs.status from $dbschm.contactsurvey cs
        where cs.contact=sn.contact
        order by cs.updated desc
        limit 1
      ),'100') as surveystatus
      ,co.contact as contactdata
      FROM $dbschm.supportneed sn
      JOIN $dbschm.survey su ON su.id=sn.survey
      JOIN $dbschm.contact co ON co.id=sn.contact
      WHERE 1=1
    ";
    if ($contact)      $sql.= " AND sn.contact = :contact";
    if ($in_category)  $sql.= " AND sn.category in ($in_category)";//part of list of strings
    if ($in_status)    $sql.= " AND sn.status in ($in_status)";//part of list of strings
    if ($in_survey)    $sql.= " AND sn.survey in ($in_survey)";//part of list of strings
    if ($in_userrole)  $sql.= " AND sn.userrole in ($in_userrole)";//part of list of strings
    if ($in_degree)    $sql.= " AND co.contact->>'degree' in ($in_degree)";//part of list of strings
    if ($in_group)     $sql.= " AND co.contact->>'group' in ($in_group)";//part of list of strings
    if ($in_location)  $sql.= " AND co.contact->>'location' in ($in_location)";//part of list of strings

    if ($history) {
      // take only history, since the last one is there also!
      $sql = "
        SELECT sn.id
        ,sn.updated,sn.updatedby
        ,sn.contact,sn.category,sn.status,sn.survey
        ,sn.userrole
        ,su.messageflow,su.starttime,su.endtime
        ,coalesce((
          select cs.status from $dbschm.contactsurvey cs
          where cs.contact=sn.contact
          order by cs.updated desc
          limit 1
        ),'100') as surveystatus
        ,co.contact as contactdata
        FROM $dbschm.supportneedhistory sn
        JOIN $dbschm.survey su ON su.id=sn.survey
        JOIN $dbschm.contact co ON co.id=sn.contact
        WHERE sn.contact = :contact
      ";
    }
    // excecute SQL statement
    //error_log("SQL: ".$sql);
    $sth = $this->dbh->prepare($sql);
    // bind parameters/values for both queries (length of array matters)
    // first "key" as named then the rest of them from specialized array(s) for each '?'
    $sqlparams = [];
    if ($contact) {
      $sqlparams = array_merge($sqlparams,['contact'=>$contact]);
    }
    $sth->execute(array_merge(
      $sqlparams,
      $getarr["category"],$getarr["status"],$getarr["survey"],
      $getarr["userrole"], $getarr["degree"],$getarr["group"],$getarr["location"]
    ));
    // modify for return
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rownum => &$row) { //nb! will modify data in loop hence "&"
      if (array_key_exists('contactdata', $row)) {
        $row['contactdata'] = json_decode($row['contactdata']);
      }
    }
    return $rows;
  }

  public function selectSurveyConfig($survey) {
    $dbschm = $this->dbschm;
    // for listing all data
    $sql = "SELECT id,config FROM $dbschm.survey WHERE 1=1 ";
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

}//class

?>