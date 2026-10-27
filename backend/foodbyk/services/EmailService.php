<?php

use PHPMailer\PHPMailer\PHPMailer;

class EmailService {
    public function send(string $to, string $subject, string $body): bool {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $subject . $to) === 1) {
            error_log('Email was not sent because its recipient or subject is invalid.');
            return false;
        }

        return match (EMAIL_PROVIDER) {
            'resend' => $this->sendWithResend($to, $subject, $body),
            'smtp' => $this->sendWithSmtp($to, $subject, $body),
            default => $this->invalidProvider(),
        };
    }

    private function sendWithResend(string $to, string $subject, string $body): bool {
        if (RESEND_API_KEY === '') {
            error_log('Email was not sent: EMAIL_PROVIDER is resend but RESEND_API_KEY is not configured.');
            return false;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer " . RESEND_API_KEY . "\r\nContent-Type: application/json\r\n",
            'content' => json_encode([
                'from' => RESEND_FROM_EMAIL,
                'to' => [$to],
                'subject' => $subject,
                'text' => $body,
            ], JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents('https://api.resend.com/emails', false, $context);
        $status = $http_response_header[0] ?? '';
        if ($response === false || preg_match('/\s2\d\d\s/', $status) !== 1) {
            error_log('Resend email delivery failed (' . ($status ?: 'no HTTP status') . ').');
            return false;
        }

        return true;
    }

    private function sendWithSmtp(string $to, string $subject, string $body): bool {
        if (!class_exists(PHPMailer::class)) {
            error_log('SMTP email was not sent because PHPMailer is not installed.');
            return false;
        }
        if (EMAIL_SMTP_HOST === '' || EMAIL_SMTP_PORT < 1 || EMAIL_SMTP_USERNAME === ''
            || EMAIL_SMTP_PASSWORD === '' || filter_var(EMAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL) === false) {
            error_log('SMTP email was not sent because required SMTP settings are missing or invalid.');
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = EMAIL_SMTP_HOST;
            $mail->Port = EMAIL_SMTP_PORT;
            $mail->SMTPAuth = true;
            $mail->Username = EMAIL_SMTP_USERNAME;
            $mail->Password = EMAIL_SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
            $mail->Timeout = 10;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->send();
            return true;
        } catch (Throwable $error) {
            error_log('SMTP email delivery failed: ' . get_class($error));
            return false;
        }
    }

    private function invalidProvider(): bool {
        error_log('Email was not sent: EMAIL_PROVIDER must be set to resend or smtp.');
        return false;
    }
}
