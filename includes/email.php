<?php
/**
 * Email scorecard via Resend API (preferred) or SMTP fallback.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * @param array<string, mixed> $score
 * @return array{ok:bool,error:?string,provider:?string}
 */
function sendScorecardEmail(array $score, string $pdfBinary, ?string $pdfUrl = null): array
{
    if (MAIL_FROM === '') {
        return ['ok' => false, 'error' => 'MAIL_FROM is not configured.', 'provider' => null];
    }

    if (RESEND_API_KEY !== '') {
        return sendScorecardViaResend($score, $pdfBinary, $pdfUrl);
    }

    if (SMTP_HOST !== '') {
        return sendScorecardViaSmtp($score, $pdfBinary, $pdfUrl);
    }

    return ['ok' => false, 'error' => 'No mail provider configured (set RESEND_API_KEY or SMTP_*).', 'provider' => null];
}

/**
 * @param array<string, mixed> $score
 * @return array{ok:bool,error:?string,provider:?string}
 */
function sendScorecardViaResend(array $score, string $pdfBinary, ?string $pdfUrl): array
{
    $event = (string) $score['event_name'];
    $total = (string) $score['grand_total'];
    $name = (string) $score['competitor_name'];
    $filename = 'FSQ-scorecard-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $event) . '.pdf';

    $text = "Hello {$name},\n\n"
        . "Attached is your Florida Sound Quality scorecard for {$event}.\n"
        . "Grand total: {$total} / 230.\n";
    if ($pdfUrl !== null && $pdfUrl !== '') {
        $text .= "\nYou can also download it here:\n{$pdfUrl}\n";
    }
    $text .= "\nThank you for competing.\n";

    $from = MAIL_FROM_NAME !== ''
        ? MAIL_FROM_NAME . ' <' . MAIL_FROM . '>'
        : MAIL_FROM;

    $payload = [
        'from'    => $from,
        'to'      => [(string) $score['competitor_email']],
        'subject' => "Florida Sound Quality scorecard — {$event}",
        'text'    => $text,
        'attachments' => [[
            'filename' => $filename,
            'content'  => base64_encode($pdfBinary),
        ]],
    ];

    $url = rtrim(RESEND_API_URL, '/') . '/emails';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Could not init HTTP client.', 'provider' => 'resend'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);

    if ($body === false) {
        error_log('Resend curl error: ' . $cerr);
        return ['ok' => false, 'error' => $cerr, 'provider' => 'resend'];
    }

    if ($status < 200 || $status >= 300) {
        error_log('Resend failed (' . $status . '): ' . substr((string) $body, 0, 300));
        return ['ok' => false, 'error' => 'Resend HTTP ' . $status, 'provider' => 'resend'];
    }

    return ['ok' => true, 'error' => null, 'provider' => 'resend'];
}

/**
 * @param array<string, mixed> $score
 * @return array{ok:bool,error:?string,provider:?string}
 */
function sendScorecardViaSmtp(array $score, string $pdfBinary, ?string $pdfUrl): array
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = SMTP_USER !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
        }
        if (SMTP_SECURE === 'tls' || SMTP_SECURE === 'ssl') {
            $mail->SMTPSecure = SMTP_SECURE;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $score['competitor_email'], (string) $score['competitor_name']);

        $event = (string) $score['event_name'];
        $total = (string) $score['grand_total'];
        $mail->Subject = "Florida Sound Quality scorecard — {$event}";
        $body = "Hello {$score['competitor_name']},\n\n"
            . "Attached is your Florida Sound Quality scorecard for {$event}.\n"
            . "Grand total: {$total} / 230.\n";
        if ($pdfUrl !== null && $pdfUrl !== '') {
            $body .= "\nYou can also download it here:\n{$pdfUrl}\n";
        }
        $body .= "\nThank you for competing.\n";
        $mail->Body = $body;
        $mail->AltBody = $body;

        $filename = 'FSQ-scorecard-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $event) . '.pdf';
        $mail->addStringAttachment($pdfBinary, $filename, 'base64', 'application/pdf');

        $mail->send();
        return ['ok' => true, 'error' => null, 'provider' => 'smtp'];
    } catch (MailException $e) {
        error_log('Scorecard SMTP failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage(), 'provider' => 'smtp'];
    }
}
