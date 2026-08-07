<?php
/**
 * Admin — download PDF scorecard for a scored competitor.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin.php';

requireRole('admin');

$competitorId = filter_var($_GET['competitor_id'] ?? null, FILTER_VALIDATE_INT);
if ($competitorId === false || $competitorId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing competitor.';
    exit;
}

$score = findScoreByCompetitorId($competitorId);
if ($score === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No score on file.';
    exit;
}

$competitor = findCompetitorById($competitorId);
if ($competitor !== null) {
    if (!empty($competitor['email'])) {
        $score['competitor_email'] = (string) $competitor['email'];
    }
    if (!empty($competitor['name'])) {
        $score['competitor_name'] = (string) $competitor['name'];
    }
}

    try {
        @ini_set('memory_limit', PDF_ARCHIVE_MEMORY_LIMIT);
        @set_time_limit(PDF_ARCHIVE_TIME_LIMIT);
        $pdf = generateScorecardPdf($score);
    } catch (Throwable $e) {
        error_log('Admin PDF download failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Could not generate PDF.';
        exit;
    }

$eventSlug = preg_replace('/[^a-zA-Z0-9_-]+/u', '-', (string) $score['event_name']) ?: 'event';
$nameSlug = preg_replace('/[^a-zA-Z0-9_-]+/u', '-', (string) $score['competitor_name']) ?: 'competitor';
$filename = 'FSQ-scorecard-' . $eventSlug . '-' . $nameSlug . '.pdf';
// ASCII-only fallback for legacy clients; RFC 5987 filename* for Unicode-safe names.
$asciiFilename = preg_replace('/[^\x20-\x7E]/', '', $filename) ?: 'FSQ-scorecard.pdf';
$asciiFilename = str_replace(['"', '\\'], '', $asciiFilename);

header('Content-Type: application/pdf');
header(
    'Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\''
    . rawurlencode($filename)
);
header('Content-Length: ' . (string) strlen($pdf));
header('Cache-Control: no-store');
echo $pdf;
