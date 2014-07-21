<?php

namespace SimpleSmtpServer;

use \EventBase;
use \EventUtil;
use \EventListener;
use \SimpleSmtpServer\Interfaces\MailStoreInterface;

class Server
{
	public $base, $listener, $socket;
	private static $conn = array();
	private static $established = array();

	public function __construct(MailStoreInterface $store){
		$this->store = $store;
		$this->base = new EventBase();
		$this->browserListener = new EventListener($this->base,
			array($this, "connectionCallback"), $this->base,
			EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE, -1,
			"0.0.0.0:25"
		);

		$this->browserListener = new EventListener($this->base,
			array($this, "sslConnectionCallback"), $this->base,
			EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE, -1,
			"0.0.0.0:465"
		);

		$this->browserListener->setErrorCallback(array($this, "accept_error_cb"));
	}

	public function __destruct() {
		foreach (self::$conn as &$c) $c = NULL;
	}

	public function connectionCallback($listener, $fd, $address, $ctx) {
		$base = $this->base;
		$ident = $this->getUUID();
		self::$conn[$ident] = new ServerConnection($base, $fd, $ident, $this->store);
	}

	public function sslConnectionCallback($listener, $fd, $address, $ctx) {
		$base = $this->base;
		$ident = $this->getUUID();
		self::$conn[$ident] = new ServerConnection($base, $fd, $ident, $this->store, true);
	}

	public static function disconnect($ident){
		unset(self::$conn[$ident]);
	}

	public function loop(){
		$this->base->loop();
	}

	public function dispatch() {
		$this->base->dispatch();
	}

	public static function assignUUID($ident, $uuid){
		if(empty(self::$established[$uuid])){
			self::$established[$uuid] = self::$conn[$ident];
		}
		unset(self::$conn[$ident]);
	}

	public static function sendMessage($serverident, $uuid, $message){
		self::$established[$uuid]->send('message: '.$message);
		self::$conn[$serverident] = null;
	}

	public function accept_error_cb($listener, $ctx) {
		$base = $this->base;

		fprintf(STDERR, "Got an error %d (%s) on the listener. "
			."Shutting down.\n",
			EventUtil::getLastSocketErrno(),
			EventUtil::getLastSocketError());

		$base->exit(NULL);
	}

	private function getUUID(){
		return microtime(true);
	}
}

function E($val){
	error_log(var_export($val,true));
}

