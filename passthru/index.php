<?php
/* index.php
 * Copyright (c) 2019-2021 Annie Advisor
 * All rights reserved.
 * Contributors:
*  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
  *
 * Index script for safety.
 *
 * NB! This script is not meant to be used for anything especially meaningful. Only version info for now.
 * NB! Authorization done via lower level IP restriction!
 */

require 'my_annie/settings.php';
require 'my_annie/http_response_code.php';

$headers = array();
$headers[]='Access-Control-Allow-Headers: Content-Type';
$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, PUT, POST, DELETE';
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

echo '{';
echo '"hostname":"'.gethostname().'",';
echo '"version":"'.file_get_contents("/var/www/html/annieversion").'"';
if ("dev" == explode(".",gethostname())[0]) {
  echo ',';
  echo '"component":{';
  echo '"db":"'.$dbschm.'",';
  echo '"library":"'.file_get_contents("/opt/annie/anniebuild").'",';
  echo '"watch":"'.file_get_contents("/opt/watch/anniebuild").'",';
  echo '"api":"'.file_get_contents("/var/www/html/api/anniebuild").'",';
  echo '"passthru":"'.file_get_contents("/var/www/html/passthru/anniebuild").'",';
  echo '"landbot":"'.file_get_contents("/var/www/html/landbot/anniebuild").'",';
  echo '"ui":"'.file_get_contents("/var/www/html/dist/anniebuild").'",';
  echo '"admin":"'.file_get_contents("/var/www/html/admin/anniebuild").'",';
  echo '"cupload":"'.file_get_contents("/var/www/html/cupload/anniebuild").'",';
  echo '"stats":"'.file_get_contents("/var/www/html/stats/anniebuild").'"';
  echo '}';
}
echo '}';

http_response_code(200);
?>