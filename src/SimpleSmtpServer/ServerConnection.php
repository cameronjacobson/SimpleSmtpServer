<?php

namespace SimpleSmtpServer;

use \Event;
use \EventBuffer;
use \EventBufferEvent;
use \SimpleSmtpServer\Interfaces\MailStoreInterface;
use \SimpleSmtpServer\Models\Message;
use \EventSslContext;

class ServerConnection
{
	private $bev, $base;

	public function __destruct() {
		$this->bev->free();
	}

	public function __construct($base, $fd, $ident, MailStoreInterface $store, $ssl = false){

		$this->ssl = $ssl;

		$this->tls_ctx = new EventSslContext(EventSslContext::TLS_SERVER_METHOD, array(
			EventSslContext::OPT_LOCAL_CERT => dirname(dirname(__DIR__)).'/config/sample-cert.pem',
			EventSslContext::OPT_LOCAL_PK => dirname(dirname(__DIR__)).'/config/sample-key.pem',
			//EventSslContext::OPT_PASSPHRASE => '',
			EventSslContext::OPT_VERIFY_PEER => false,
			EventSslContext::OPT_ALLOW_SELF_SIGNED => true,
			EventSslContext::OPT_CIPHERS => 'HIGH'
		));

		$this->store = $store;
		$this->base = $base;

		$this->bev = new EventBufferEvent($base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE);

		$this->ident = $ident;
		$this->lastCommand = null;
		$this->close = false;
		$this->state = array();

		if($ssl){
			$this->bev = EventBufferEvent::sslFilter(
				$this->base,
				$this->bev,
				$this->tls_ctx,
				EventBufferEvent::SSL_ACCEPTING,
				EventBufferEvent::OPT_CLOSE_ON_FREE
			);
		}

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
			case 'STARTTLS':
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
//				$this->sendLine(250, 'EHLO');
				$this->sendCapability(250, '8BITMIME');
				if(!$this->ssl){
					$this->sendCapability(250, 'STARTTLS');
				}
				$this->sendLine(250, 'OK');
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
			case 'NOOP':
				$this->sendLine(250, 'OK');
				break;
			case 'VRFY':
				$this->sendLine(421, '');
				$this->close = true;
				break;
			case 'STARTTLS':
				$this->sendLine(220, 'Go Ahead');

				$this->bev = EventBufferEvent::sslFilter(
					$this->base,
					$this->bev,
					$this->tls_ctx,
					EventBufferEvent::SSL_ACCEPTING,
					EventBufferEvent::OPT_CLOSE_ON_FREE
				);
				$this->bev->setCallbacks(array($this, "readCallback"), array($this,"writeCallback"),
					array($this, "eventCallback"), NULL
				);

				$this->bev->enable(Event::READ | Event::WRITE);
				$this->state = array();
				$this->ssl = true;
				break;
			case 'EXPN':
				$this->sendLine(421, '');
				$this->close = true;
				break;
			case 'HELP':
				$this->sendLine(421, '');
				$this->close = true;
				break;
			default:
				$this->sendLine(421, 'Unrecognized Command');
				$this->close = true;
				break;
		}
	}

	private function processData(){
		if(substr($this->buffer, -5) === "\r\n.\r\n"){
			$this->sendLine(250, 'OK');
			$this->lastCommand = 'ENDDATA';
			$mail = mailparse_msg_create();
			mailparse_msg_parse($mail, $this->buffer);

			$msg = new Message();
			$msg->meta = array();
			$msg->headers = array();
			$msg->content = array();

			$struct = mailparse_msg_get_structure($mail); 
			foreach($struct as $st) {
				$section = mailparse_msg_get_part($mail, $st);
				$info = mailparse_msg_get_part_data($section);
				$msg->headers[] = $info['headers'];
				unset($info['headers']);
				$msg->meta[] = $info;
				$body = substr($this->buffer, $info['starting-pos-body'], $info['ending-pos-body']-$info['starting-pos-body']);

				if(count($struct) > 1){
					$msg->content[] = count($msg->meta) == 1 ? '' : trim(preg_replace("|\.\r\n$|","",$body));
				}
				else{
					$msg->content[] = trim(preg_replace("|\.\r\n$|","",$body));
				}
			}

			$msg->raw = $this->buffer;

			$this->store->store($msg);
			$this->buffer = '';
		}
	}

	public function eventCallback($bev, $events, $ctx) {

		if ($events & EventBufferEvent::ERROR) {
			while ($err = $bev->sslError()) {
				fprintf(STDERR, "Bufferevent error %s.\n", $err);
			}
		}

		if ($events & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) {
		}
	}

	private function sendLine($code, $msg){
		$output = $this->bev->output;
		$output->add($code.' '.$msg."\r\n");
	}
	private function sendCapability($code, $msg){
		$output = $this->bev->output;
		$output->add($code.'-'.$msg."\r\n");
	}
}

