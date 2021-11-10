<?php
/*
 * Copyright © 2014 South Telecom
 * 
 */
require_once './vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use Pheanstalk\Pheanstalk;

date_default_timezone_set('Asia/Ho_Chi_Minh');

function UpdateSendStatus($mailPayload) {
    echo $mailPayload->SendResult . PHP_EOL;
    if (!$mailPayload->SendResult) {
        echo $mailPayload->ErrorInfo . PHP_EOL;
    }

    //Update status to db
    $data = json_encode((array) $mailPayload);

    $curl = curl_init('http://localhost:8000/framework-wf/updateSendStatus');

    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data))
    );

    curl_exec($curl);
    
    curl_close($curl);
}

$WATCHTUBE = "smailer";
$queue = Pheanstalk::create('127.0.0.1'); 

$PIDFILE = __DIR__ . "/worker-emmail-smailer.pid";

touch($PIDFILE);

echo "Worker " . __FILE__ . " have started. To exit, delete pid file  " .  $PIDFILE . PHP_EOL;

while (file_exists($PIDFILE)) {
    while ($job = $queue->watch($WATCHTUBE)->ignore('default')->reserve(15)) {
        try {
            $mailPayload = json_decode($job->getData(), false);
            $mail = new PHPMailer();
            $mail->isSMTP();
            $mail->Host = $mailPayload->Host; 
            $mail->SMTPAuth = $mailPayload->SMTPAuth;                              
            $mail->Username = $mailPayload->Username;                 
            $mail->Password = $mailPayload->Password;                          
            $mail->SMTPSecure = $mailPayload->SMTPSecure;                            
            $mail->Port = $mailPayload->Port; 

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $mail->setFrom($mailPayload->FromEmail, $mailPayload->FromName);

            foreach (explode(',', $mailPayload->To) as $to) {
                $mail->addAddress($to);
            }
            // Name is optional
            $mail->isHTML($mailPayload->isHTML);                                  
            $mail->Subject = $mailPayload->Subject;
            $mail->Body = $mailPayload->Body;
            $mailPayload->SendResult = $mail->send();
            if (!$mailPayload->SendResult) {
                $mailPayload->ErrorInfo = $mail->ErrorInfo;
            }

            $mailPayload->SendTimestamp = time();
            $mail->smtpClose();

            //Excute Callback function
            if (function_exists($mailPayload->Callback)) {
                call_user_func($mailPayload->Callback, $mailPayload);
            }
            //End Callback function  
            $queue->delete($job);
        } catch (Exception $e) {
            $jobData = $job->getData();
            $queue->delete($job);
            var_dump($e);
            
            $queue->useTube($WATCHTUBE)
            ->put(json_encode($jobData));
            exit();
        }
        if(!file_exists($PIDFILE)){
            exit();
        }
    }
}
