<?php
/* ============================================================================
(c) Copyright 2012-2014 Hewlett-Packard Development Company, L.P.

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
============================================================================ */
/**
 * @file
 * This is a simple command-line test for authentication.
 *
 * You can run the test with `php test/AuthTest.php username key`.
 */

$base = dirname(__DIR__);
require_once $base . '/src/OpenStack/Bootstrap.php';

use \OpenStack\Storage\ObjectStorage;
use \OpenStack\Services\IdentityService;

$config = array(
  'transport' => '\OpenStack\Transport\CURLTransport',
  'transport.timeout' => 240,
  //'transport.debug' => 1,
  'transport.ssl.verify' => 0,
);

\OpenStack\Bootstrap::useAutoloader();
\OpenStack\Bootstrap::setConfiguration($config);

$help = "Authenticate against OpenStack Identity Service.

You can authenticate either by account number and access key, or (by using the
-u flag) by username, password.

While Tenant ID is optional, it is recommended.

In both cases, you must supply a URL to the Identity Services endpoint.
";

$usage = "php {$argv[0]} [-u] ID SECRET URL [TENANT_ID]";

if ($argc > 1 && $argv[1] == '--help') {
  print PHP_EOL . "\t" . $usage . PHP_EOL;
  print PHP_EOL . $help . PHP_EOL;
  exit(1);
}
elseif ($argc < 4) {
  print 'ID, Key, and URL are all required.' . PHP_EOL;
  print $usage . PHP_EOL;
  exit(1);
}

$asUser = FALSE;
$offset = 0;
if ($argv[1] == '-u') {
  $asUser = TRUE;
  ++$offset;
}

$user = $argv[1 + $offset];
$key = $argv[2 + $offset];
$uri = $argv[3 + $offset];

$tenantId = NULL;
if (!empty($argv[4 + $offset])) {
  $tenantId = $argv[4 + $offset];
}

/*
$store = ObjectStorage::newFromSwiftAuth($user, $key, $uri);

$token = $store->token();
 */
$cs = new IdentityService($uri);

if ($asUser) {
  $token = $cs->authenticateAsUser($user, $key, $tenantId);
}
else {
  $token = $cs->authenticateAsAccount($user, $key, $tenantId);
}

if (empty($token)) {
  print "Authentication seemed to succeed, but no token was returned." . PHP_EOL;
  exit(1);
}

$t = "You are logged in as %s with token %s (good until %s)." . PHP_EOL;
$tokenDetails = $cs->tokenDetails();
$user = $cs->user();

printf($t, $user['name'], $cs->token(), $tokenDetails['expires']);

print "The following services are available on this account:" . PHP_EOL;

$services = $cs->serviceCatalog();
foreach ($services as $service) {
  print "\t" . $service['name'] . PHP_EOL;
}

//print_r($services);
