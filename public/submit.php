<?php
/**
 * POST /submit.php — validate, insert score (idempotent), return JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/storage.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['_form' => 'Method not allowed.']]);
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['_form' => 'Invalid CSRF token.']]);
    exit;
}

$result = validateScoreSubmission($_POST);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $result['errors']]);
    exit;
}

$data = $result['data'];

// Idempotency: return existing row for duplicate UUID
$existing = dbFetchOne(
    'SELECT id FROM scores WHERE submission_uuid = ?',
    [$data['submission_uuid']]
);
if ($existing !== null) {
    http_response_code(200);
    echo json_encode([
        'success'   => true,
        'scoreId'   => (int) $existing['id'],
        'duplicate' => true,
        'emailSent' => false,
        'grandTotal'=> $data['grand_total'],
    ]);
    exit;
}

try {
    dbQuery(
        'INSERT INTO scores (
            submission_uuid, event_date, event_name, judge_name,
            competitor_name, competitor_email,
            vehicle_year, vehicle_make, vehicle_model, vehicle_color,
            sub_bass, mid_bass, midrange, high_freq, spectral_balance, tonal_notes,
            listening_position, width, height, depth, ambience, stage_notes,
            imaging_score, imaging_notes,
            noise, listening_pleasure, noise_notes, listening_notes,
            tonal_total, stage_total, grand_total, placement
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?
        )',
        [
            $data['submission_uuid'],
            $data['event_date'],
            $data['event_name'],
            $data['judge_name'],
            $data['competitor_name'],
            $data['competitor_email'],
            $data['vehicle_year'],
            $data['vehicle_make'],
            $data['vehicle_model'],
            $data['vehicle_color'],
            $data['sub_bass'],
            $data['mid_bass'],
            $data['midrange'],
            $data['high_freq'],
            $data['spectral_balance'],
            $data['tonal_notes'],
            $data['listening_position'],
            $data['width'],
            $data['height'],
            $data['depth'],
            $data['ambience'],
            $data['stage_notes'],
            $data['imaging_score'],
            $data['imaging_notes'],
            $data['noise'],
            $data['listening_pleasure'],
            $data['noise_notes'],
            $data['listening_notes'],
            $data['tonal_total'],
            $data['stage_total'],
            $data['grand_total'],
            $data['placement'],
        ]
    );
} catch (PDOException $e) {
    // Race on UNIQUE — treat as duplicate
    if ((int) $e->getCode() === 23000) {
        $row = dbFetchOne(
            'SELECT id FROM scores WHERE submission_uuid = ?',
            [$data['submission_uuid']]
        );
        http_response_code(200);
        echo json_encode([
            'success'   => true,
            'scoreId'   => $row ? (int) $row['id'] : 0,
            'duplicate' => true,
            'emailSent' => false,
            'grandTotal'=> $data['grand_total'],
        ]);
        exit;
    }
    error_log('Score insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => ['_form' => 'Could not save score.']]);
    exit;
}

$scoreId = (int) db()->lastInsertId();
$emailSent = false;
$emailWarning = null;
$pdfStored = false;

try {
    $pdf = generateScorecardPdf($data);
    $store = storeScorecardPdf($scoreId, $pdf, (string) $data['event_name']);
    $pdfStored = $store['ok'];
    if (!$pdfStored) {
        error_log('PDF archive skipped/failed: ' . ($store['error'] ?? 'unknown'));
    }
    // Private bucket URLs are not emailable as public links — attachment only.
    $mailResult = sendScorecardEmail($data, $pdf, null);
    $emailSent = $mailResult['ok'];
    if (!$emailSent) {
        $emailWarning = 'Score saved, email failed.';
    }
} catch (Throwable $e) {
    error_log('PDF/email failed after save: ' . $e->getMessage());
    $emailWarning = 'Score saved, email failed.';
}

http_response_code(201);
$payload = [
    'success'    => true,
    'scoreId'    => $scoreId,
    'emailSent'  => $emailSent,
    'pdfStored'  => $pdfStored,
    'grandTotal' => $data['grand_total'],
];
if ($emailWarning !== null) {
    $payload['emailWarning'] = $emailWarning;
}
echo json_encode($payload);
