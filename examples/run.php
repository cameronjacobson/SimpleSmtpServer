<?php

require_once(dirname(__DIR__).'/vendor/autoload.php');

use SimpleSmtpServer\Server;

$server = new Server();
$server->loop();
