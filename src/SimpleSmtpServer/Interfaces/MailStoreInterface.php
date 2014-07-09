<?php

namespace SimpleSmtpServer\Interfaces;

use SimpleSmtpServer\Models\Message;

interface MailStoreInterface
{
	public function store(Message $msg);
}
