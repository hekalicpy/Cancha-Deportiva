<?php
class GmailMailer {
    public static function configured(): bool {
        return (bool)filter_var(getenv('GMAIL_ADDRESS') ?: '', FILTER_VALIDATE_EMAIL)
            && (getenv('GMAIL_APP_PASSWORD') ?: '') !== ''
            && is_readable(__DIR__.'/../vendor/autoload.php');
    }

    public static function send(string $to, string $subject, string $body): void {
        if (!self::configured()) throw new RuntimeException('Gmail no está configurado; instale PHPMailer y configure las credenciales de aplicación.');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Destinatario inválido.');

        require_once __DIR__.'/../vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->Port = 465;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAuth = true;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';
        $mail->Username = getenv('GMAIL_ADDRESS');
        $mail->Password = str_replace(' ', '', getenv('GMAIL_APP_PASSWORD'));
        $mail->setFrom(getenv('GMAIL_ADDRESS'), 'Arena 7');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    }
}
