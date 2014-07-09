<?php

namespace SimpleSmtpServer\Models;

use \Schivel\Schivel;
use \Phreezer\Storage\CouchDB;
use \SimpleSmtpServer\Interfaces\MailStoreInterface;

class MailStore extends Schivel implements MailStoreInterface
{
	public function __construct(CouchDB $db){
		parent::__construct($db);
	}

	public function store(Message $msg){
		parent::store($msg);
	}
}
