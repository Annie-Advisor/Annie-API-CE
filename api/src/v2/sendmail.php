<?php
/* sendmail.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script for sending emails.
 * Before anything there is authentication check.
 */

require_once 'my_app_specific_library_dir/settings.php';//->settings,db*
require_once 'my_app_specific_library_dir/auth.php';

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, POST'; // PUT, DELETE
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

// figure out mailsubject and mailbody
$mailsubject = "Email from Annie ".date("Y-m-d H:i:s");
$mailbody = "<html><body><p>I have <b>nothing to say</b>. So, apparently <i>I am the <b>problem</b></i> myself.</p></body></html>";
$mailtext = "I have nothing to say. So, apparently I am the problem myself.";
switch ($method) {
  case 'GET':
    // get parameters
    // split on outer delimiter
    $pairs = explode('&', $_SERVER['QUERY_STRING']);
    // loop through each pair
    foreach ($pairs as $i) {
      if ($i) {
        // split into name and value
        list($name,$value) = explode('=', $i, 2);
        // fix value (htmlspecialchars for extra security)
        $value = urldecode(htmlspecialchars($value));
        // override if multiple given
        if (strtolower($name) === "subject") {
          $mailsubject = $value;
        }
        if (strtolower($name) === "body") {
          $mailbody = $value;
        }
        // giving a choice with "body" and "html"
        if (strtolower($name) === "html") {
          $mailbody = $value;
        }
        if (strtolower($name) === "plaintext") {
          $mailtext = $value;
        }
      }
    }
    break;
  case 'POST':
    if ($input) {
      if (array_key_exists('subject', $input)) {
        $mailsubject = $input->{'subject'};
      }
      if (array_key_exists('body', $input)) {
        $mailbody = $input->{'body'};
      }
      // giving a choice with "body" and "html"
      if (array_key_exists('html', $input)) {
        $mailbody = $input->{'html'};
      }
      if (array_key_exists('plaintext', $input)) {
        $mailtext = $input->{'plaintext'};
      }
    } else {
      http_response_code(400); // Bad Request
      echo json_encode(array("status"=>"FAILED"));
      exit;
    }
    break;
  case 'PUT':
  case 'DELETE':
  default:
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

$clientname = "annie"; // default value might be wise to hit an existing mail address
if (gethostname()) {
  $clientname = explode(".",gethostname())[0];
}

// TODO use authenticated user info? (fetch from db, not auth)
$mailsender = "Annie Advisor <".$clientname."@annieadvisor.com>";

$mailrecipients = array();
array_push($mailrecipients,(object)array('email' => $clientname.'@annieadvisor.com'));

require_once 'my_app_specific_library_dir/mail.php';
$result = mailSend($mailsender,$mailrecipients,$mailsubject,$mailbody,$mailtext,"sendmail");
if ($result !== true) {
  http_response_code(409); // Conflict
  echo json_encode(array("status"=>"FAILED"));
} else {
  http_response_code(200);
  echo json_encode(array("status"=>"OK"));
}

?>