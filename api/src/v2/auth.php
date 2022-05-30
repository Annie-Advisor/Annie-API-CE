<?php
/* auth.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Backend script for Annie UI.
 * Check for authentication. Just return HTTP status and JSON.
 */

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, POST';//GET, PUT, DELETE
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

/*$key = null;
if (count($request)>=1) {
  $key = array_shift($request);
}
*/

// resolve values for variables:
$source = 'my_app_specific_saml';
$returnto = 'my_app_specific_logout_url';

switch ($method) {
  case 'POST':
    if ($input) {
      if (array_key_exists('returnto', $input)) {
        $returnto = $input->{'returnto'};
      }
      if (array_key_exists('returnTo', $input)) {
        $returnto = $input->{'returnTo'};
      }
      if (array_key_exists('ReturnTo', $input)) {
        $returnto = $input->{'ReturnTo'};
      }
      if (array_key_exists('source', $input)) {
        $source = $input->{'source'};
      }
      if (array_key_exists('Source', $input)) {
        $source = $input->{'Source'};
      }
    }
    break;
  case 'GET':
  case 'PUT':
  case 'DELETE':
  default:
    http_response_code(405);
    echo json_encode(array("status"=>"FAILED"));
    exit;
    break;
}

//
//
//

require_once 'my_app_specific_simplesaml_dir/lib/_autoload.php';
$auth = new \SimpleSAML\Auth\Simple($source);
//SimpleSAML\Session::getSessionFromRequest()->cleanup(); //Reverts to our PHP session - we don't want that!

$ret = (object)['logoutURL' => $auth->getLogoutURL($returnto)];

foreach ($auth->getAttributes() as $k => $v) {
  // nb! $v is an array but we get the first one in any case
  switch ($k) {
    case 'uid':
      $ret->uid = strtolower($v[0]);
      break;
    case 'givenName':
      $ret->firstname = $v[0];
      break;
    case 'sn':
      $ret->lastname = $v[0];
      break;
    // no default
  }
}
if ($auth->isAuthenticated()) {
  http_response_code(200); //OK
  $ret->status = "OK";
  echo json_encode($ret);
} else {
  http_response_code(401); //Unauthorized
  echo json_encode(array(
    "status" => "FAILED",
    "reason" => "Unauthorized",
    "loginURL" => $auth->getLoginURL($returnto)
  ));
}
?>