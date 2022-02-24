<?php
/*
 * Statistics for Annie.
 * (index for protection)
 */

require_once 'my_app_specific_library_dir/settings.php';//->$settings,$db*

$validated = false;

if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
  $user = $_SERVER['PHP_AUTH_USER'];
  $pass = $_SERVER['PHP_AUTH_PW'];

  $validated = ($user == $settings['api']['user'] && $pass == $settings['api']['pass']);
}

if (!$validated) {
  //header('HTTP/1.0 401 Unauthorized');
  //die ("Not authorized");
  require_once 'my_app_specific_library_dir/auth.php';//->$as,$auth
}

require 'my_app_specific_library_dir/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET';//, PUT, POST, DELETE';
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

//
//
//

switch ($method) {
  case 'GET':
    echo '{"meta":{"message":"You are here!"}}';
    break;
  case 'PUT':
  case 'POST':
  case 'DELETE':
    http_response_code(405); // Method Not Allowed
    exit;
    break;
}

// clean up & close
// -

http_response_code(200); // OK
?>