<?php
/**
 * POST /submit.php — validate, insert score for a registered competitor (idempotent).
 * Paper sheet streams to S3 before the JSON response; PDF archive runs deferred after
 * the response is flushed (not emailed here — admin-triggered in Phase 3).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/competitors.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/events.php';
require_once __DIR__ . '/../includes/stage_markers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

requireRole('judge');

$judge = currentUser();
if ($judge === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'errors' => ['_form' => 'Not authenticated.']]);
    exit;
}

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

if (isSubmitCoolingDown()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'errors'  => ['_form' => 'Please wait a few seconds before submitting again.'],
    ]);
    exit;
}

$competitorId = filter_var($_POST['competitor_id'] ?? null, FILTER_VALIDATE_INT);
if ($competitorId === false || $competitorId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => ['competitor_id' => 'Select a registered competitor.']]);
    exit;
}

$competitor = findCompetitorById($competitorId);
if ($competitor === null || !competitorIsScoreable($competitor)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'errors'  => ['_form' => 'This competitor is not available for scoring (missing, or already scored).'],
    ]);
    exit;
}

// Trust DB for competitor identity — ignore client-supplied name/email/vehicle.
$_POST['competitor_name'] = (string) ($competitor['name'] ?? '');
$_POST['competitor_email'] = (string) ($competitor['email'] ?? '');
$_POST['vehicle_year'] = $competitor['vehicle_year'] !== null ? (string) $competitor['vehicle_year'] : '';
$_POST['vehicle_make'] = (string) ($competitor['vehicle_make'] ?? '');
$_POST['vehicle_model'] = (string) ($competitor['vehicle_model'] ?? '');
$_POST['vehicle_color'] = (string) ($competitor['vehicle_color'] ?? '');
$_POST['judge_name'] = $judge['name'];

$eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
$resolvedEventId = null;
if ($eventId !== false && $eventId > 0) {
    $event = findEventById($eventId);
    if ($event === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => ['event_id' => 'Select a valid event.']]);
        exit;
    }
    $_POST['event_name'] = (string) $event['name'];
    $_POST['event_date'] = (string) $event['event_date'];
    $resolvedEventId = (int) $event['id'];
}

$paperUpload = validatePaperSheetUpload(
    isset($_FILES['paper_sheet']) && is_array($_FILES['paper_sheet'])
        ? $_FILES['paper_sheet']
        : null
);
if (!$paperUpload['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => ['paper_sheet' => $paperUpload['error']]]);
    exit;
}

$result = validateScoreSubmission($_POST);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $result['errors']]);
    exit;
}

$data = $result['data'];
$data['competitor_id'] = $competitorId;
$data['event_id'] = $resolvedEventId;

// Idempotency: return existing row for duplicate UUID
$existing = dbFetchOne(
    'SELECT id FROM scores WHERE submission_uuid = ?',
    [$data['submission_uuid']]
);
if ($existing !== null) {
    markSubmitAttempt();
    http_response_code(200);
    echo json_encode([
        'success'    => true,
        'scoreId'    => (int) $existing['id'],
        'duplicate'  => true,
        'grandTotal' => $data['grand_total'],
    ]);
    exit;
}

$pdo = db();
$scoreId = 0;

try {
    $pdo->beginTransaction();

    // Re-check inside transaction (one score per competitor)
    $locked = dbFetchOne(
        'SELECT id FROM scores WHERE competitor_id = ? FOR UPDATE',
        [$competitorId]
    );
    if ($locked !== null) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'errors'  => ['_form' => 'This competitor already has a score.'],
        ]);
        exit;
    }

    dbQuery(
        'INSERT INTO scores (
            submission_uuid, competitor_id, judge_user_id, event_id,
            event_date, event_name, judge_name,
            competitor_name, competitor_email,
            vehicle_year, vehicle_make, vehicle_model, vehicle_color,
            sub_bass, mid_bass, midrange, high_freq, spectral_balance, tonal_notes,
            listening_position, width, height, depth, ambience, stage_notes,
            stage_markers_wh, stage_markers_depth,
            imaging_score, imaging_notes,
            noise, listening_pleasure, noise_notes, listening_notes,
            tonal_total, stage_total, grand_total, placement, paper_sheet_key
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )',
        [
            $data['submission_uuid'],
            $competitorId,
            $judge['id'],
            $data['event_id'],
            $data['event_date'],
            $data['event_name'],
            $judge['name'],
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
            encodeStageMarkers($data['stage_markers_wh'] ?? []),
            encodeStageMarkers($data['stage_markers_depth'] ?? []),
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
            null,
        ]
    );

    $scoreId = (int) $pdo->lastInsertId();

    dbQuery(
        'UPDATE competitors SET status = \'scored\' WHERE id = ? AND status = \'registered\'',
        [$competitorId]
    );

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Race on UNIQUE submission_uuid or competitor_id
    if ((int) $e->getCode() === 23000) {
        markSubmitAttempt();
        $row = dbFetchOne(
            'SELECT id FROM scores WHERE submission_uuid = ? OR competitor_id = ?',
            [$data['submission_uuid'], $competitorId]
        );
        http_response_code(200);
        echo json_encode([
            'success'    => true,
            'scoreId'    => $row ? (int) $row['id'] : 0,
            'duplicate'  => true,
            'grandTotal' => $data['grand_total'],
        ]);
        exit;
    }
    error_log('Score insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => ['_form' => 'Could not save score.']]);
    exit;
}

markSubmitAttempt();

$paperStored = false;
if (
    !$paperUpload['skip']
    && $paperUpload['tmp_path'] !== null
    && $paperUpload['mime'] !== null
    && $paperUpload['ext'] !== null
) {
    try {
        $paperStore = storePaperSheetImage(
            $scoreId,
            $paperUpload['tmp_path'],
            $paperUpload['mime'],
            $paperUpload['ext'],
            (string) $data['event_name']
        );
        $paperStored = $paperStore['ok'];
        if ($paperStored && $paperStore['key'] !== null) {
            try {
                dbQuery(
                    'UPDATE scores SET paper_sheet_key = ? WHERE id = ?',
                    [$paperStore['key'], $scoreId]
                );
            } catch (Throwable $e) {
                error_log('paper_sheet_key update failed: ' . $e->getMessage());
            }
        } else {
            error_log('Paper sheet archive skipped/failed: ' . ($paperStore['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('Paper sheet archive failed after save: ' . $e->getMessage());
    }
}

// Respond to the judge immediately; PDF archive is best-effort and deferred.
http_response_code(201);
echo json_encode([
    'success'     => true,
    'scoreId'     => $scoreId,
    'pdfStored'   => false,
    'pdfQueued'   => true,
    'paperStored' => $paperStored,
    'grandTotal'  => $data['grand_total'],
    'redirect'    => '/score.php',
]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

ignore_user_abort(true);
@ini_set('memory_limit', PDF_ARCHIVE_MEMORY_LIMIT);
@set_time_limit(PDF_ARCHIVE_TIME_LIMIT);

try {
    $pdf = generateScorecardPdf($data);
    $store = storeScorecardPdf($scoreId, $pdf, (string) $data['event_name']);
    unset($pdf);
    if (!$store['ok']) {
        error_log('PDF archive skipped/failed: ' . ($store['error'] ?? 'unknown'));
    }
} catch (Throwable $e) {
    error_log('Deferred PDF archive failed after save: ' . $e->getMessage());
}