<?php

date_default_timezone_set('America/New_York');

require_once(dirname(__DIR__).'/vendor/autoload.php');

use \Swift_SmtpTransport;
use \Swift_Mailer;
use \Swift_Message;
use \Swift_Attachment;

sendmail('me@localhost', 'from@otherhost.com', 'subject', 'body');

function sendmail($to, $from, $subject, $body, $headers = array()) {
    global $app;
    $config = array('host'=>'localhost','port'=>25);
    $transport = Swift_SmtpTransport::newInstance($config['host'], $config['port']);

    $mailer = Swift_Mailer::newInstance($transport);

    $message = Swift_Message::newInstance($subject)
      ->setFrom(is_string($from) ? array($from) : $from)
      ->setTo(is_string($to) ? array($to) : $to)
      ->setBody($body)
      ->attach(Swift_Attachment::fromPath(__DIR__.'/sample_attachment.txt'));

    if(!empty($headers)){
        $headers = $message->getHeaders();
        foreach($headers as $key=>$value){
            $headers->addTextHeader($key,$value);
        }
    }

    $failures = array();
    if(!$mailer->send($message, $failures)){
        throw new Exception(array(
            'code' => 500,
            'message' => $failures
        ));
    }
    return true;
}

