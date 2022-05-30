<?php
/* gatekeeper.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script to work as "gatekeeper" and/or message (re-)director for SMS flow.
 *
 * NB! Authorization done via lower level IP restriction!
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
//no auth, ip restriction

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
/* + db for specialized queries */
try {
  $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $e) {
  die("Something went wrong while connecting to database: " . $e->getMessage() );
}
/* - db */

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, POST';// PUT, DELETE
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
$input = file_get_contents('php://input');

// resolve input to same array type for both content-types
// (or assume application/json if not x-www-form-urlencoded used below)
if (isset($_SERVER['CONTENT_TYPE'])
 &&($_SERVER['CONTENT_TYPE'] == "application/x-www-form-urlencoded; charset=utf-8"
  ||$_SERVER['CONTENT_TYPE'] == "application/x-www-form-urlencoded")
) {
  // parse url encoded to a object
  parse_str(urldecode($input), $parsed);
  // to json string keeping unicode
  $input = json_encode($parsed, JSON_UNESCAPED_UNICODE);
  // to array
  $input = json_decode($input);
} else {
  // not quite sure these are needed!
  $input = json_decode($input);
}

//
//
//

function getPossiblePhases($currentphase,$flow) {
  $possiblephases = array();
  if (isset($flow)) {
    foreach ($flow as $fk => $fv) {
      if (preg_match('/^branch.*$/i', $fk)) {
        $phasecandidate_ = substr($fk,strlen("branch"));
        // direct children
        array_push($possiblephases,$phasecandidate_);
        // next level(s)
        foreach ($fv as $ffk => $ffv) {
          if (preg_match('/^branch.*$/i', $ffk)) {
            $phasecandidate__ = substr($ffk,strlen("branch"));
            if ($currentphase == $phasecandidate_) {// parent phase
              array_push($possiblephases,$phasecandidate__);
            }
          } elseif ($ffk == "other") {
            if ($currentphase == $phasecandidate_) {// parent phase
              array_push($possiblephases,$phasecandidate_);
            }
          }
        }
        $possiblephases = array_merge($possiblephases,getPossiblePhases($currentphase,$fv));
      } elseif ($fk == "other") {
        if (in_array($currentphase,array("1","2"))) { // root phases
          array_push($possiblephases,$currentphase);
        }
      }
    }
  }
  return $possiblephases;
}
function getPhaseAction($currentphase,$possiblephases,$text,$flow) {
  $nextphase = null;
  $nextphaseisleaf = null;
  $nextmessage = null;
  $currentphaseconfig = null; //for checking next nextphase
  $dosupportneed = false;

  // root level
  //A, B, C...
  foreach ($flow as $fk => $fv) {
    if (strpos($fk,"branch") !== false && array_key_exists("condition", $fv)) {
      $phasecandidate_ = substr($fk,strlen("branch"));
      $pattern = "/".$fv->{'condition'}."/";
      //to-do-ish: check $possiblephases
      if (in_array($currentphase, array("1","2"))) {
        if (preg_match($pattern, $text)) { //nb! improve me!
          $nextmessage = $fv->{'message'};
          $nextphase = $phasecandidate_;
          $currentphaseconfig = $fv;
          if (array_key_exists("supportneed", $fv) && $fv->{'supportneed'}) {
            $dosupportneed = true;
          }
          //break 1;//stop all
          return array($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed);
        }
      } elseif ($currentphase == $phasecandidate_) {
        // next level
        //A1, A2...
        foreach ($fv as $ffk => $ffv) {
          if (strpos($ffk,"branch") !== false && array_key_exists("condition", $ffv)) {
            $phasecandidate__ = substr($ffk,strlen("branch"));
            $pattern = "/".$ffv->{'condition'}."/";
            //to-do-ish: check $possiblephases
            if (preg_match($pattern, $text)) { //nb! improve me!
              $nextmessage = $ffv->{'message'};
              $nextphase = $phasecandidate__;
              $currentphaseconfig = $ffv;
              //to-do-ish: next level?
              if (array_key_exists('supportneed', $ffv) && $ffv->{'supportneed'}) {
                $dosupportneed = true;
              }
              //break 2;//stop all
              return array($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed);
            }
          }
        }//-loop fv
        // no match above for subphase, but we are in currentphase
        // so SUB.other if exists
        if (!$nextphase) {
          if (array_key_exists('other', $fv)) {
            $ffv = $fv->{'other'};
            if (gettype($ffv)=="object" && array_key_exists('message', $ffv)) {
              $nextmessage = $ffv->{'message'};
              $nextphase = $phasecandidate_;
              $currentphaseconfig = $fv;
            }
            if (array_key_exists('supportneed', $ffv) && $ffv->{'supportneed'}) {
              $dosupportneed = true;
            }
            //break 1;//stop this (flow)
            return array($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed);
          }
        }
      } else {
        // recursively find next levels
        $nextnextphaseexists = false;
        foreach ($fv as $ffk => $ffv) {
          if (strpos($ffk,"branch") !== false) {
            $nextnextphaseexists = true;
          }
        }
        if ($nextnextphaseexists) {
          list ($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed) = getPhaseAction($currentphase,$possiblephases,$text,$fv);
          if (isset($nextphase)) {
            return array($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed);
          }
        }
      }
    }//-branch
  }//-loop flow
  // no match above
  if (!$nextphase) {
    if (in_array($currentphase,array("1","2"))) { // root phases
      if (gettype($flow)=="object" && array_key_exists("other", $flow)) {
        $fv = $flow->{'other'};
        if (gettype($fv)=="object" && array_key_exists('message', $fv)) {
          $nextmessage = $fv->{'message'};
          $nextphase = $currentphase;
          $currentphaseconfig = $flow;
        }
        if (array_key_exists('supportneed', $fv) && $fv->{'supportneed'}) {
          $dosupportneed = true;
        }
      }
    }  //"else" phase with no next or other (typically everything OK)
  }
  return array($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed);
}

//
//
//

// go based on HTTP method
$areyouokay = true; // status of process here
switch ($method) {
  case 'PUT':
    http_response_code(405); // Method Not Allowed
    exit;
    break;
  case 'DELETE':
    http_response_code(405); // Method Not Allowed
    exit;
    break;
  case 'GET':
    http_response_code(200); // ok, but no action
    echo '{"status":"Annie sad", "Advice":"Puny gettin\' without magic"}';
    exit;
    break;
  case 'POST':
    if ($input) {
      //
      // FLOW/ENGINE -> GATE + many directions
      //
      /*
      Input comes from SMS Provider.
      From there we get sender and destination numbers which will give us
      enough information to query db and get means to make decisions in
      which phase this current actor (student, destination number) is.
      Phase (or survey + supportneed.category) should give us sufficient
      information to decide on next action...
      */

      /* JSON data example from PUSH (from SMS Provider to Annie):
      {
        "sender": "+358500000002",
        "sendertype": "MSISDN",
        "destination": "+358400000001",
        "text": "H\u20acllo, world!",
        "sendtime": "2015-09-14T10:31:25Z",
        "status": "SENT",
        "statustime": "2015-09-14T10:31:25Z"
      }
      */
      //nb! batchid for survey

      /* JSON data example for POST (from Annie to Student via SMS Provider):
      {
        "sender": "+358500000002",
        "destination": "+358400000001",
        "text": "H\u20acllo, world!",
      }
      */

      // input variables
      $sender = null; // phonenumber
      $sendertype = null;
      $destination = null; // phonenumber
      $text = null;
      $sendtime = null;
      $status = null;
      $statustime = null;
      if (array_key_exists('sender', $input)) {
        $sender = $input->{'sender'};
      }
      if (array_key_exists('sendertype', $input)) {
        $sendertype = $input->{'sendertype'};
      }
      if (array_key_exists('destination', $input)) {
        $destination = $input->{'destination'};
      }
      if (array_key_exists('text', $input)) {
        $text = $input->{'text'};
      }
      if (array_key_exists('sendtime', $input)) {
        $sendtime = $input->{'sendtime'};
      }
      //dev:
      else {
        $sendtime = "now()";
      }
      if (array_key_exists('status', $input)) {
        $status = $input->{'status'};
      }
      if (array_key_exists('statustime', $input)) {
        $statustime = $input->{'statustime'};
      }

      //nb! SMS Provider numbers are sometimes scuffed "+358..." -> " 358..."
      $destination = trim($destination);
      $sender = trim($sender);

      if (!preg_match('/^[+].*/', $destination)) {
        $destination = "+".$destination;
      }
      if (!preg_match('/^[+].*/', $sender)) {
        $sender = "+".$sender;
      }

      // PUSHed by SMS Provider or polled by this engine (which call must be made by something)
      // Do we recognize the sender via phonenumber?
      if (array_key_exists('sendertype', $input)) { //consider this as SMS Provider PUSHed

        // direction of message (which is sender and which destination)
        // - search for destination from contacts
        // - then for sender from contacts
        $annienumber = "";
        $contactnumber = "";

        $contactid = null;

        $survey = null;
        $flow = null;
        $nextphase = null;
        $nextphaseisleaf = null;
        $nextmessage = null;
        $firstmessage = null;
        $currentphaseconfig = null; //for checking next nextphase

        $possiblephases = array();
        $dosupportneed = false;

        // figure out contactid from destination/sender number
        $contactids = json_decode(json_encode($anniedb->selectContactId($destination)));
        if (count($contactids)>0) {
          $contactnumber = $destination;
          $annienumber = $sender;
          $contactid = $contactids[0]->{'id'};
        } else {
          $contactids = json_decode(json_encode($anniedb->selectContactId($sender)));
          if (count($contactids)>0) {
            $contactnumber = $sender;
            $annienumber = $destination;
            $contactid = $contactids[0]->{'id'};
          } else {
            $areyouokay = false;
            error_log("FAILED: Gatekeeper: action: FAILED to get contactid for: ".$destination);
          }
        }

        //
        // Gatekeeper action: logic for different paths in order
        // 1. ongoing contactsurvey -> surveyengine
        // 2. ongoing followup -> followupengine
        // 3. supportneed exists -> supportprocess
        // 4. -> defaultbot
        //
        $gatekeeperaction = null;
        if ($areyouokay && $contactid)
        {
          $contacts = json_decode(json_encode($anniedb->selectContact($contactid)));
          $contact = $contacts[0]->{'contact'};
          //not used (sql does its thing already): $contactoptout = $contacts[0]->{'optout'};

          // figure out survey from contact
          // for listing all latest data
          // take only one (the last one) for contact
          $sql = "
            SELECT cs.updated
            , cs.survey
            , cs.status
            FROM $dbschm.contactsurvey cs
            WHERE cs.contact = :contact
            AND cs.status != '100' --not finished
            -- last one for contact:
            AND (cs.contact,cs.updated) in (
              select contact,max(updated)
              from $dbschm.contactsurvey
              group by contact
            )
            ORDER BY updated DESC LIMIT 1
          ";
          $sth = $dbh->prepare($sql);
          $sth->bindParam(':contact', $contactid);
          $sth->execute();
          $contactsurveys = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
          // ...test if there is such info...
          if (count($contactsurveys)>0) { // there is an ongoing contactsurvey

            // -> Gatekeeper action: "survey engine"
            $gatekeeperaction = "SURVEY";

            // can we proceed
            // - phase we are at (currentphase)
            $currentphase = $contactsurveys[0]->{'status'};
            $survey = $contactsurveys[0]->{'survey'};

            $surveyconfigs = json_decode(json_encode($anniedb->selectSurveyConfig($survey)));

            // ...and now we should know what we need
            // - contact, survey and message (text)

            // do the flow magic; figure out:
            // - which phases are possible (possiblephases)
            // - what do we actually do (nextphase, dosupportneed)

            // loop surveyconfig, phases
            // - get possible phases for current status
            foreach ($surveyconfigs[0] as $jk => $jv) {//nb! should be only one!
              if ("config" == $jk) { //must have
                $flow = json_decode($jv);
                $possiblephases = getPossiblePhases($currentphase,$flow);
                $firstmessage = $flow->{'message'};
              }//-config
            }//-loop surveyconfig

            if (isset($text) && $text!=="") {
              // loop phases again but with evaluating with message received
              list ($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed) = getPhaseAction($currentphase,$possiblephases,$text,$flow);
              $nextphaseisleaf = empty(getPossiblePhases($nextphase,$currentphaseconfig));
            }//-text
            else {//some sort of error
              error_log("WARNING: Gatekeeper: received a text message without text?!");
            }

          } else { // there is NO ongoing contactsurvey

            // is there followup ongoing?
            $sql = "
              SELECT fu.updated
              , fu.id
              , fu.status
              , fu.supportneed
              , sn.followuptype
              , sn.survey
              FROM $dbschm.followup fu
              JOIN $dbschm.supportneed req ON req.id = fu.supportneed --request
              JOIN $dbschm.supportneed sn ON sn.survey = req.survey AND sn.contact = req.contact --latest
              WHERE sn.contact = :contact
              AND fu.status != '100' --not finished
              -- last one for supportneed (contact):
              AND (fu.supportneed,fu.updated) in (
                select supportneed,max(updated)
                from $dbschm.followup
                group by supportneed
              )
              -- supportneed followup is set
              AND sn.followuptype IS NOT NULL
              -- latest supportneed (w/ followuptype):
              AND (sn.survey, sn.contact, sn.id) IN (
                select survey, contact, max(id) as latestsupportneedid
                from $dbschm.supportneed
                where survey = sn.survey and contact = sn.contact
                and followuptype is not null
                group by survey, contact
              )
              ORDER BY fu.updated DESC LIMIT 1
            ";
            $sth = $dbh->prepare($sql);
            $sth->bindParam(':contact', $contactid);
            $sth->execute();
            $followups = $sth->fetchAll(PDO::FETCH_ASSOC);

            if (count($followups)>0) { // there is an ongoing followup

              // -> Gatekeeper action: "followup"
              $gatekeeperaction = "FOLLOWUP";
              $followup = $followups[0]['id'];

              // can we proceed
              // - phase we are at (currentphase)
              $currentphase = $followups[0]['status'];
              $requestid = $followups[0]['supportneed']; //nb! supportneedid as requestid
              $followuptype = $followups[0]['followuptype'];
              $survey = $followups[0]['survey'];

              $followupconfig = json_decode($anniedb->selectConfig('followup','config')[0]['value']);
              if (array_key_exists($followuptype, $followupconfig)) {
                $flow = $followupconfig->{$followuptype};
                $possiblephases = getPossiblePhases($currentphase,$flow);
                $firstmessage = $flow->{'message'};
              } else {
                error_log("ERROR: Gatekeeper: did not find type=$followuptype from followup.config");
              }
              // ...and now we should know what we need
              // - contact, supportneed (followup) and message (text)

              if (!isset($flow) || empty($flow)) {
                error_log("ERROR: Gatekeeper: did not get flow for followup (type=$followuptype)");
              } else {
                // do the flow magic; figure out:
                // - which phases are possible (possiblephases)
                // - what do we actually do (nextphase)

                if (isset($text) && $text!=="") {
                  // loop phases again but with evaluating with message received
                  list ($nextmessage,$nextphase,$currentphaseconfig,$dosupportneed) = getPhaseAction($currentphase,$possiblephases,$text,$flow);
                  $nextphaseisleaf = empty(getPossiblePhases($nextphase,$currentphaseconfig));
                }//-text
                else {//some sort of error
                  error_log("WARNING: Gatekeeper: received a text message without text?!");
                }
              }

            } else { // there is NO ongoing followup (or contactsurvey)

              // are there any (unresolved) supportneeds for contact
              $sql = "
                SELECT sn.updated
                , sn.survey
                , sn.category
                , sn.status
                , sn.supporttype
                , sn.followuptype
                , sn.followupresult
                FROM $dbschm.supportneed sn
                WHERE sn.contact = :contact
                --AD-284: AND sn.status != 'ACKED'
                ORDER BY updated DESC LIMIT 1
              ";
              $sth = $dbh->prepare($sql);
              $sth->bindParam(':contact', $contactid);
              $sth->execute();
              $opensupportneeds = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
              if (count($opensupportneeds)>0) {

                // -> Gatekeeper action: "support process"
                $gatekeeperaction = "SUPPORTPROCESS";
                $survey = $opensupportneeds[0]->{'survey'};//for mail
                $category = $opensupportneeds[0]->{'category'};//for mail
                $supportneedstatus = $opensupportneeds[0]->{'status'};//for checking and changing supportneed.status
                $supporttype = $opensupportneeds[0]->{'supporttype'};//for updating supportneed
                $followuptype = $opensupportneeds[0]->{'followuptype'};//for updating supportneed
                $followupresult = $opensupportneeds[0]->{'followupresult'};//for updating supportneed

              } else {

                // -> Gatekeeper action: "default bot"
                $gatekeeperaction = "DEFAULTBOT";

              }

            }//-followup (exists)

          }//-contactsurvey (exists)

          // :: gatekeeper action decided ::

          // store received message to db in every case!
          $sendername = "Annie"; //default, use contact if thats the sender
          if ($sender == $contactnumber) {
            $sendername = $contact->{'firstname'}." ".$contact->{'lastname'};
          }
          $notneededmessageid = $anniedb->insertMessage(json_decode(json_encode(array(
            //id is generated: "id"=>?,
            "contact"=>$contactid,
            "created"=>$sendtime,
            "createdby"=>"Gatekeeper",
            "updated"=>$sendtime,
            "updatedby"=>"Gatekeeper",
            "body"=>$text,
            "sender"=>$sendername,
            "survey"=>$survey,
            "context"=>$gatekeeperaction,
            "status"=>"RECEIVED" // special case when message comes from contact
          ))));
          //error_log("DEBUG: Gatekeeper: insert message(1): [not needed] id: ".$notneededmessageid);
          if ($notneededmessageid === FALSE) {
            error_log("ERROR: Gatekeeper: insert message failed");
            $areyouokay = false;
          }
          //...continue anyway

          //
          // (RE-)DIRECT MESSAGE OR FLOW
          //

          //error_log("DEBUG: Gatekeeper: action chosen: ".$gatekeeperaction);
          switch ($gatekeeperaction) {
            case 'SURVEY':
              // handle CANCEL in survey context
              $surveycancelcondition = '¤not#gonna%match.any&of*your"strings¤'; //weird default but okay
              $surveycancelconfig = json_decode($anniedb->selectConfig('survey','cancel')[0]['value']);
              if (array_key_exists('condition', $surveycancelconfig)) {
                $surveycancelcondition = $surveycancelconfig->{'condition'};
              }
              $pattern = "/".$surveycancelcondition."/";
              if (preg_match($pattern, $text)) {
                // switch nextmessage based on different path
                $nextmessage = null;
                if (array_key_exists('message', $surveycancelconfig)) {
                  $nextmessage = $surveycancelconfig->{'message'};
                }
                require 'my_app_specific_library_dir/surveycancel.php';
              } else {
                require 'my_app_specific_library_dir/surveyengine.php';
              }
              break;
            case 'FOLLOWUP':
              require 'my_app_specific_library_dir/followupengine.php';
              break;
            case 'SUPPORTPROCESS':
              require 'my_app_specific_library_dir/supportprocess.php';
              break;
            case 'DEFAULTBOT':
              require 'my_app_specific_library_dir/defaultbot.php';
              break;
            default:
              error_log("WARNING: Gatekeeper: no action chosen?!");
              break;
          }

        }
      }

      // no output needed or expected
      http_response_code(200); // OK
    } else {
      http_response_code(400); // Bad Request
      exit;
    }
    break;
}

?>