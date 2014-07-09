<?php

require_once(dirname(__DIR__).'/vendor/autoload.php');

use SimpleSmtpServer\Server;
use SimpleSmtpServer\Models\MailStore;
use Phreezer\Storage\CouchDB;

$db = new MailStore(new CouchDB(array(
	'scheme'=>'http',
	'host'=>'localhost',
	'port'=>'5984',
	'database'=>'mymail'
)));

$server = new Server($db);
$server->loop();
