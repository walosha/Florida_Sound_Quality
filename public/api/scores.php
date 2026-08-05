<?php
/**
 * GET /api/scores.php — staff-only live scoreboard JSON.
 * Never returns email, notes, or judge name.
 * Prefers live competitor profile fields when a score is linked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

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

$event = trim((string) ($_GET['event'] ?? ''));
if ($event === '') {
    $latest = dbFetchOne(
        'SELECT event_name FROM scores ORDER BY created_at DESC LIMIT 1'
    );
    $event = $latest['event_name'] ?? '';
}

if ($event === '') {
    echo json_encode([]);
    exit;
}

$rows = dbFetchAll(
    'SELECT
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
    [$event]
);

$out = [];
$rank = 1;
foreach ($rows as $row) {
    $out[] = [
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

echo json_encode($out);
