<?php
/**
 * PHPMailer wrapper — email PDF scorecard to competitor.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * @param array<string, mixed> $score
 * @return array{ok:bool,error:?string}
 */
function sendScorecardEmail(array $score, string $pdfBinary): array
{
    if (SMTP_HOST === '' || MAIL_FROM === '') {
        return ['ok' => false, 'error' => 'SMTP is not configured.'];
    }

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
        $mail->Body = "Hello {$score['competitor_name']},\n\n"
            . "Attached is your Florida Sound Quality scorecard for {$event}.\n"
            . "Grand total: {$total} / 230.\n\n"
            . "Thank you for competing.\n";
        $mail->AltBody = $mail->Body;

        $filename = 'FSQ-scorecard-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $event) . '.pdf';
        $mail->addStringAttachment($pdfBinary, $filename, 'base64', 'application/pdf');

        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (MailException $e) {
        error_log('Scorecard email failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
