<?php
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_email_with_phpmailer($pdo, $to, $subject, $message, $from) {
    // Retrieve SMTP settings from your database
    $stmt = $pdo->query("SELECT `key`, `value` FROM `settings` WHERE `key` IN ('smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass')");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }

    if (!isset($settings['smtp_host'], $settings['smtp_user'], $settings['smtp_pass'], $settings['smtp_port'])) {
        echo "SMTP settings are incomplete.";
        return;
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['smtp_user'];
        $mail->Password = $settings['smtp_pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)$settings['smtp_port'];

        // Recipients
        $mail->setFrom($from, 'TopBar Asssist PH');
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
    } catch (Exception $e) {
        echo "Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
}