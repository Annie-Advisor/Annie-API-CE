<?php
/* supportneed.php
 * Copyright (c) 2021 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * AnnieAPI for Landbot
 *
 * NB! Authorization done via lower level IP restriction!
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
//no auth, ip restriction

require_once 'my_app_specific_library_dir/anniedb.php';
$anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);

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
// go based on HTTP method
$areyouokay = true; // status of process here
switch ($method) {
  case 'PUT':
    http_response_code(400); // Bad Request
    exit;
    break;
  case 'DELETE':
    http_response_code(400); // Bad Request
    exit;
    break;
  case 'GET':
    //nothing to do?
    http_response_code(200);
    exit;
    break;
  case 'POST':
    if ($input) {
      //error_log("DEBUG: Landbot supportneed: input: ".json_encode($input));
      /* Landbot example:
      // We get:
      /*{
        "phonenumber": "@phone",
        "supportneed": {
          "category": "A1",
          "status": 1,
          "survey": "1"
        }
      }*/
      // And we want to pass on:
      /*{
        "contact": CONTACTID,
        "category": "A1",
        "status": 1,
        "survey": "1"
      }*/

      // variables
      $phonenumber = null;
      $supportneed = null;
      if (array_key_exists('phonenumber', $input)) {
        $phonenumber = $input->{'phonenumber'};
      }
      if (array_key_exists('supportneed', $input)) {
        $supportneed = $input->{'supportneed'};
      }

      // test for mandatories
      if (!$phonenumber || !$supportneed) {
        http_response_code(200); // OK
        // but no need to continue
        exit;
      }

      // if phonenumbers are somehow scuffed "+358..." -> " 358..."
      $phonenumber = trim($phonenumber);
      if (!preg_match('/^[+].*/', $phonenumber)) {
        $phonenumber = "+".$phonenumber;
      }
      //error_log("DEBUG: Landbot supportneed: (edit)phonenumber: ".$phonenumber);

      // do your thing....

      // figure out contactid from phonenumber
      $contactid = null;
      $contactids = json_decode(json_encode($anniedb->selectContactId($phonenumber)));
      if (count($contactids)>0) {
        $contactid = $contactids[0]->{'id'};
      } else {
        $areyouokay = false;
        //TODO: errors
        http_response_code(200); // OK
        // but no need to continue
        exit;
      }

      //error_log("DEBUG: Landbot supportneed: contactid: ".$contactid);
      $supportneed->{'contact'} = $contactid;

      //
      // actions
      //

      // insert supportneed
      $ret = $anniedb->insertSupportneed($supportneed);
      if ($ret !== false) {
        http_response_code(200);
        echo json_encode(array("status"=>"OK", "id"=>$ret));
      } else {
        echo json_encode(array("status"=>"FAILED"));
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