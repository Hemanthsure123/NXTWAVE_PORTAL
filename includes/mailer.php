<?php
/**
 * Sending the OTP email through Gmail.
 *
 * PHP has a built-in mail() function, but it expects a mail server to be
 * installed on this machine. XAMPP has none, so mail() just fails. Instead
 * we log in to Gmail's SMTP server the same way an email app does, and hand
 * it the message. That is what PHPMailer does for us.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

function send_otp_email(string $toEmail, string $name, string $otp): bool
{
    $config = require dirname(__DIR__) . '/config/mail.php';

    $mail = new PHPMailer();

    // Who we are logging in as.
    $mail->isSMTP();
    $mail->Host       = $config['host'];
    $mail->Port       = $config['port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['username'];
    $mail->Password   = $config['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';

    // Who it is from and who it goes to.
    $mail->setFrom($config['username'], $config['from_name']);
    $mail->addAddress($toEmail, $name);

    // The message itself. AltBody is the plain text version, for mail
    // apps that refuse to show HTML.
    $mail->isHTML(true);
    $mail->Subject = 'Your NxtWave Portal password reset code';
    $mail->Body    = otp_email_body($name, $otp);
    $mail->AltBody = "Hi {$name}, your password reset code is {$otp}. It expires in 15 minutes.";

    if ($mail->send()) {
        return true;
    }

    // Something went wrong - wrong app password, no internet, Gmail busy.
    // The user should never see an SMTP error, so it goes to the PHP log.
    error_log('OTP email failed: ' . $mail->ErrorInfo);

    return false;
}

/**
 * The HTML of the email. Kept separate so you can edit the wording
 * without touching the sending code.
 */
function otp_email_body(string $name, string $otp): string
{
    return '
        <div style="font-family: Arial, sans-serif; font-size: 15px; color: #222;">
            <p>Hi ' . e($name) . ',</p>
            <p>Use this code to reset your NxtWave Portal password:</p>
            <p style="font-size: 30px; letter-spacing: 6px; font-weight: bold;">' . e($otp) . '</p>
            <p>The code expires in 15 minutes and can be used only once.</p>
            <p>If you did not ask for this, you can ignore this email.</p>
            <p>- NxtWave Portal</p>
        </div>';
}
