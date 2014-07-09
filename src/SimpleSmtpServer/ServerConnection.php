<?php

namespace SimpleSmtpServer;

use \Event;
use \EventBuffer;
use \EventBufferEvent;
use \SimpleSmtpServer\Interfaces\MailStoreInterface;
use \SimpleSmtpServer\Models\Message;

class ServerConnection
{
	private $bev, $base;

	public function __destruct() {
		$this->bev->free();
	}

	public function __construct($base, $fd, $ident, MailStoreInterface $store){
		$this->store = $store;
		$this->base = $base;
		$this->bev = new EventBufferEvent($base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE);

		$this->ident = $ident;
		$this->lastCommand = null;
		$this->close = false;
		$this->state = array();

		$this->bev->setCallbacks(array($this, "readCallback"), array($this,"writeCallback"),
			array($this, "eventCallback"), NULL
		);

		$this->bev->enable(Event::READ | Event::WRITE);

		$this->sendLine(220, 'WELCOME');
	}

	public function readCallback($bev, $ctx) {
		$bev->readBuffer($bev->input);
		while($line = $bev->input->read(512)){
			$this->buffer .= $line;
		}
		switch($this->lastCommand){
			case 'HELO':
			case 'EHLO':
			case 'MAIL':
			case 'RCPT':
			case 'ENDDATA':
			default:
				$command = $this->extractCommand();
				$this->processCommand($command);
				break;
			case 'DATA':
				$this->processData();
				break;
		}
	}

	public function writeCallback($bev, $ctx){
		if($this->close){
			$this->__destruct();
			Server::disconnect($this->ident);
		}
	}

	private function extractCommand(){
		list($command,$tail) = explode("\r\n",$this->buffer,2);
		$this->buffer = $tail;
		return $command;
	}

	private function processCommand($command){
		list($this->lastCommand) = explode(' ',$command);
		switch($this->lastCommand){
			case 'HELO':
				$this->sendLine(250, 'HELO');
				break;
			case 'EHLO':
				$this->sendLine(250, 'EHLO');
				break;
			case 'QUIT':
				$this->sendLine(221, '');
				$this->close = true;
				break;
			case 'MAIL':
				$this->sendLine(250, 'OK');
				$this->state['from'] = $command;
				break;
			case 'RCPT':
				$this->sendLine(250, 'OK');
				$this->state['to'] = $command;
				break;
			case 'DATA':
				$this->sendLine(354, 'Start mail input');
				break;
			case 'RSET':
				$this->sendLine(250, 'OK');
				$this->state = array();
				break;
			case 'VRFY':
				$this->sendLine(550, '');
				$this->close = true;
				break;
			case 'EXPN':
				$this->sendLine(550, '');
				$this->close = true;
				break;
		}
	}

	private function processData(){
		if(substr($this->buffer, -5) === "\r\n.\r\n"){
			$this->sendLine(250, 'OK');
			$this->lastCommand = 'ENDDATA';
			$this->state['mail'] = $this->buffer;
			$mail = mailparse_msg_create();
			mailparse_msg_parse($mail, $this->buffer);

			$msg = new Message();

			$struct = mailparse_msg_get_structure($mail); 

			foreach($struct as $st) { 
				$section = mailparse_msg_get_part($mail, $st); 
				$info = mailparse_msg_get_part_data($section);

				foreach($info as $k=>$v){
					switch($k){
						case 'headers':
							$msg->headers = $v;
							break;
						default:
							$msg->meta[$k] = $v;
							break;
					}
				}
			}
			$msg->body = substr($this->buffer,$msg->meta['starting-pos-body'], $msg->meta['ending-pos-body'] - $msg->meta['starting-pos-body'] - 5);
			$this->store->store($msg);
			$this->buffer = '';
		}
	}

	public function eventCallback($bev, $events, $ctx) {
		if ($events & EventBufferEvent::ERROR) {
		}

		if ($events & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) {
		}
	}

	private function sendLine($code, $msg){
		$output = $this->bev->output;
		$output->add($code.' '.$msg."\r\n");
	}
}

