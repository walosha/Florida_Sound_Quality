<?php
/**
 * GET /api/scores.php — staff-only live scoreboard JSON.
 * List action omits email, notes, and judge name (safe for display screens).
 * Detail action returns full score breakdown for staff click-through.
 * Prefers live competitor profile fields when a score is linked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pagination.php';
require_once __DIR__ . '/../../includes/stage_markers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

startAppSession();
$user = currentUser();
if ($user === null || !in_array($user['role'], ['admin', 'judge'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Staff login required.']);
    exit;
}

$action = $_GET['action'] ?? 'scores';

if ($action === 'events') {
    $rows = dbFetchAll(
        'SELECT event_name, MAX(created_at) AS latest
         FROM scores
         GROUP BY event_name
         ORDER BY latest DESC'
    );
    $events = array_map(static fn (array $r): string => (string) $r['event_name'], $rows);
    echo json_encode(['events' => $events, 'default' => $events[0] ?? null]);
    exit;
}

if ($action === 'detail') {
    $scoreId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($scoreId === false || $scoreId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing score id.']);
        exit;
    }

    $row = dbFetchOne(
        'SELECT
            s.id,
            s.event_name,
            s.event_date,
            s.judge_name,
            COALESCE(c.name, s.competitor_name) AS competitor_name,
            COALESCE(c.email, s.competitor_email) AS competitor_email,
            COALESCE(c.vehicle_year, s.vehicle_year) AS vehicle_year,
            COALESCE(c.vehicle_make, s.vehicle_make) AS vehicle_make,
            COALESCE(c.vehicle_model, s.vehicle_model) AS vehicle_model,
            COALESCE(c.vehicle_color, s.vehicle_color) AS vehicle_color,
            s.sub_bass,
            s.mid_bass,
            s.midrange,
            s.high_freq,
            s.spectral_balance,
            s.tonal_notes,
            s.tonal_total,
            s.listening_position,
            s.width,
            s.height,
            s.depth,
            s.ambience,
            s.stage_notes,
            s.stage_markers_wh,
            s.stage_markers_depth,
            s.stage_total,
            s.imaging_score,
            s.imaging_notes,
            s.noise,
            s.listening_pleasure,
            s.noise_notes,
            s.listening_notes,
            s.grand_total,
            s.placement
         FROM scores s
         LEFT JOIN competitors c ON c.id = s.competitor_id
         WHERE s.id = ?',
        [$scoreId]
    );

    if ($row === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Score not found.']);
        exit;
    }

    $int = static fn ($v): int => (int) $v;
    $nullableInt = static fn ($v): ?int => $v !== null ? (int) $v : null;
    $str = static fn ($v): ?string => $v !== null && $v !== '' ? (string) $v : null;

    echo json_encode([
        'id'                 => $int($row['id']),
        'event_name'         => (string) $row['event_name'],
        'event_date'         => (string) $row['event_date'],
        'judge_name'         => (string) $row['judge_name'],
        'competitor_name'    => (string) $row['competitor_name'],
        'competitor_email'   => (string) $row['competitor_email'],
        'vehicle_year'       => $nullableInt($row['vehicle_year']),
        'vehicle_make'       => $str($row['vehicle_make']),
        'vehicle_model'      => $str($row['vehicle_model']),
        'vehicle_color'      => $str($row['vehicle_color']),
        'sub_bass'           => $int($row['sub_bass']),
        'mid_bass'           => $int($row['mid_bass']),
        'midrange'           => $int($row['midrange']),
        'high_freq'          => $int($row['high_freq']),
        'spectral_balance'   => $int($row['spectral_balance']),
        'tonal_notes'        => $str($row['tonal_notes']),
        'tonal_total'        => $int($row['tonal_total']),
        'listening_position' => $int($row['listening_position']),
        'width'              => $int($row['width']),
        'height'             => $int($row['height']),
        'depth'              => $int($row['depth']),
        'ambience'           => $int($row['ambience']),
        'stage_notes'        => $str($row['stage_notes']),
        'stage_markers_wh'   => parseStageMarkers($row['stage_markers_wh'] ?? null, 'width_height') ?? [],
        'stage_markers_depth'=> parseStageMarkers($row['stage_markers_depth'] ?? null, 'depth') ?? [],
        'stage_total'        => $int($row['stage_total']),
        'imaging_score'      => $int($row['imaging_score']),
        'imaging_notes'      => $str($row['imaging_notes']),
        'noise'              => $int($row['noise']),
        'listening_pleasure' => $int($row['listening_pleasure']),
        'noise_notes'        => $str($row['noise_notes']),
        'listening_notes'    => $str($row['listening_notes']),
        'grand_total'        => $int($row['grand_total']),
        'placement'          => $str($row['placement']),
    ]);
    exit;
}

$event = trim((string) ($_GET['event'] ?? ''));
if ($event === '') {
    $latest = dbFetchOne(
        'SELECT event_name FROM scores ORDER BY created_at DESC LIMIT 1'
    );
    $event = $latest['event_name'] ?? '';
}

if ($event === '') {
    echo json_encode([
        'scores'      => [],
        'total'       => 0,
        'page'        => 1,
        'per_page'    => PAGINATION_DEFAULT_PER_PAGE,
        'total_pages' => 1,
        'from'        => 0,
        'to'          => 0,
    ]);
    exit;
}

$pager = paginationParams();
$result = dbPaginate(
    'SELECT COUNT(*) AS cnt FROM scores s WHERE s.event_name = ?',
    'SELECT
        s.id,
        COALESCE(c.name, s.competitor_name) AS competitor_name,
        COALESCE(c.vehicle_year, s.vehicle_year) AS vehicle_year,
        COALESCE(c.vehicle_make, s.vehicle_make) AS vehicle_make,
        COALESCE(c.vehicle_model, s.vehicle_model) AS vehicle_model,
        s.grand_total,
        s.placement
     FROM scores s
     LEFT JOIN competitors c ON c.id = s.competitor_id
     WHERE s.event_name = ?
     ORDER BY s.grand_total DESC, s.id ASC',
    [$event],
    $pager['page'],
    $pager['per_page']
);

$out = [];
$rank = (int) $result['offset'] + 1;
foreach ($result['rows'] as $row) {
    $out[] = [
        'id'              => (int) $row['id'],
        'rank'            => $rank,
        'competitor_name' => $row['competitor_name'],
        'vehicle_year'    => $row['vehicle_year'] !== null ? (int) $row['vehicle_year'] : null,
        'vehicle_make'    => $row['vehicle_make'],
        'vehicle_model'   => $row['vehicle_model'],
        'total_score'     => (int) $row['grand_total'],
        'placement'       => $row['placement'],
    ];
    $rank++;
}

echo json_encode([
    'scores'      => $out,
    'total'       => $result['total'],
    'page'        => $result['page'],
    'per_page'    => $result['per_page'],
    'total_pages' => $result['total_pages'],
    'from'        => $result['from'],
    'to'          => $result['to'],
]);
