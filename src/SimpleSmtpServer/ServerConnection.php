<?php

namespace SimpleSmtpServer;

use \Event;
use \EventBuffer;
use \EventBufferEvent;

class ServerConnection
{
    private $bev, $base;

    public function __destruct() {
        $this->bev->free();
    }

    public function __construct($base, $fd, $ident){
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
			var_dump($this->state);
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
    private function processRequest(){
        /**
         *  TODO:
         *   - Read received headers for Last-Event-ID
         */
        $request = $this->buffer;
        if(strpos($request, "\r\n\r\n") !== false){
            list($headers,$body) = explode("\r\n\r\n", $request,2);
            $headers = explode("\r\n", $headers);
            $firstline = array_shift($headers);
            preg_match("|^POST\s+?/([^\s]+?)\s|",$firstline,$match);
            $clientUUID = $match[1];

            $output = $this->bev->output;
            $output->add(
                'HTTP/1.1 200 OK'."\r\n".
                'Date: '.gmdate('D, d M Y H:i:s').' GMT'."\r\n".
                'Server: Server-Sent-Events 0.1'."\r\n".
                'MIME-version: 1.0'."\r\n".
                "Content-Type: text/plain; charset=utf-8\r\n".
                "Content-Length: 0\r\n\r\n"
            );

            Server::sendMessage($clientUUID, $body);
        }
    }
}

