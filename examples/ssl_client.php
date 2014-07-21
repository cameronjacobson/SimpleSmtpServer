<?php

date_default_timezone_set('America/New_York');

require_once(dirname(__DIR__).'/vendor/autoload.php');

use \Swift_SmtpTransport;
use \Swift_Mailer;
use \Swift_Message;

sendmail('me@localhost', 'from@otherhost.com', 'subject', 'body');

function sendmail($to, $from, $subject, $body, $headers = array()) {
    global $app;
    $config = array('host'=>'localhost','port'=>465);
    $transport = Swift_SmtpTransport::newInstance($config['host'], $config['port'], 'ssl');

    $mailer = Swift_Mailer::newInstance($transport);

    $message = Swift_Message::newInstance($subject)
      ->setFrom(is_string($from) ? array($from) : $from)
      ->setTo(is_string($to) ? array($to) : $to)
      ->setBody($body);

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

