<?php
/**
 * Ad-hoc regression tests for stage markers + existing score paths.
 * Run: php scripts/test_stage_markers.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/stage_markers.php';
require_once $root . '/includes/validation.php';
require_once $root . '/includes/pdf.php';
require_once $root . '/includes/db.php';

$pass = 0;
$fail = 0;
$errors = [];

function assert_true(bool $cond, string $label): void
{
    global $pass, $fail, $errors;
    if ($cond) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        $errors[] = $label;
        echo "  FAIL  {$label}\n";
    }
}

function assert_eq(mixed $got, mixed $want, string $label): void
{
    $ok = $got === $want;
    if (!$ok) {
        echo "         got=" . var_export($got, true) . " want=" . var_export($want, true) . "\n";
    }
    assert_true($ok, $label);
}

echo "=== 1. parseStageMarkers edge cases ===\n";

assert_eq(parseStageMarkers(null, 'width_height', true), [], 'null → []');
assert_eq(parseStageMarkers('', 'width_height', true), [], 'empty string → []');
assert_eq(parseStageMarkers('[]', 'width_height', true), [], '[] → []');
assert_eq(parseStageMarkers([], 'width_height', true), [], 'array [] → []');

$one = parseStageMarkers('[{"x":10.5,"y":20.25}]', 'width_height', true);
assert_true(is_array($one) && count($one) === 1, 'single marker parses');
assert_eq($one[0]['x'] ?? null, 10.5, 'x preserved');
assert_eq($one[0]['y'] ?? null, 20.25, 'y preserved');

$five = parseStageMarkers(
    json_encode([
        ['x' => 1, 'y' => 1],
        ['x' => 2, 'y' => 2],
        ['x' => 3, 'y' => 3],
        ['x' => 4, 'y' => 4],
        ['x' => 5, 'y' => 5],
    ]),
    'width_height',
    true
);
assert_true(is_array($five) && count($five) === 4, 'caps at 4 markers');

$clamped = parseStageMarkers('[{"x":-50,"y":999}]', 'width_height', true);
assert_true(is_array($clamped), 'out-of-bounds parses');
assert_eq($clamped[0]['x'] ?? null, 0.0, 'x clamped to viewBox min');
assert_eq($clamped[0]['y'] ?? null, 94.0, 'y clamped to viewBox max');

assert_eq(parseStageMarkers('not-json', 'width_height', true), null, 'invalid JSON strict → null');
assert_eq(parseStageMarkers('not-json', 'width_height', false), [], 'invalid JSON soft → []');
assert_eq(parseStageMarkers('[{"x":"a","y":1}]', 'width_height', true), [], 'non-numeric skipped → []');
assert_eq(parseStageMarkers('[{}]', 'width_height', true), [], 'missing keys skipped → []');
assert_eq(parseStageMarkers('[{"x":1}]', 'width_height', true), [], 'missing y skipped → []');
assert_eq(parseStageMarkers('{"x":1,"y":2}', 'width_height', true), null, 'object payload strict → null');
$mixed = parseStageMarkers('[{"x":1,"y":2},{"x":"bad","y":3},{"x":4,"y":5}]', 'width_height', true);
assert_true(is_array($mixed) && count($mixed) === 2, 'mixed array keeps valid markers');
assert_eq($mixed[0]['x'] ?? null, 1.0, 'mixed first x');
assert_eq($mixed[1]['x'] ?? null, 4.0, 'mixed second x');

$dbStyle = parseStageMarkers('[{"x": 40, "y": 30}]', 'depth', true);
assert_true(is_array($dbStyle) && count($dbStyle) === 1, 'DB-style JSON string');

$depthClamp = parseStageMarkers('[{"x":300,"y":-1}]', 'depth', true);
assert_eq($depthClamp[0]['x'] ?? null, 247.0, 'depth x clamped to 247');
assert_eq($depthClamp[0]['y'] ?? null, 0.0, 'depth y clamped to 0');

echo "\n=== 2. encodeStageMarkers ===\n";
assert_eq(encodeStageMarkers([]), '[]', 'encode empty');
$enc = encodeStageMarkers([['x' => 1.23456, 'y' => 2.0]]);
assert_true(str_contains($enc, '"x"') && str_contains($enc, '"y"'), 'encode has x/y');

echo "\n=== 3. SVG load / prepare ===\n";
$rawWh = loadStageDiagramSvg('width_height');
$rawDepth = loadStageDiagramSvg('depth');
assert_true($rawWh !== '' && str_contains($rawWh, '<svg'), 'load width_height SVG');
assert_true($rawDepth !== '' && str_contains($rawDepth, '<svg'), 'load depth SVG');
assert_true(!str_contains($rawWh, '<?xml'), 'XML decl stripped from load');

$prep = prepareStageDiagramSvg('width_height', [['x' => 40, 'y' => 30]], true);
assert_true(str_contains($prep, 'stage-diagram-svg'), 'prepared has class');
assert_true(str_contains($prep, 'stage-marker-layer'), 'prepared has marker layer');
assert_true(str_contains($prep, 'stage-marker'), 'prepared has marker');
assert_true(str_contains($prep, 'sd-width-height-'), 'ids uniquified');
assert_true(!preg_match('/<svg[^>]*\swidth="/', $prep), 'fixed width removed from svg root');

$prep2 = prepareStageDiagramSvg('depth', [], false);
assert_true(str_contains($prep2, 'is-static'), 'static class');
assert_true(str_contains($prep2, 'stage-marker-layer'), 'empty layer still present');

assert_eq(loadStageDiagramSvg('nope'), '', 'unknown diagram → empty');

echo "\n=== 4. Interactive form HTML ===\n";
ob_start();
renderStageDiagramsInteractive();
$formHtml = ob_get_clean();
assert_true(substr_count($formHtml, 'class="stage-diagram"') === 2, 'two diagram wrappers');
assert_true(substr_count($formHtml, 'name="stage_markers_wh"') === 1, 'wh hidden input');
assert_true(substr_count($formHtml, 'name="stage_markers_depth"') === 1, 'depth hidden input');
assert_true(substr_count($formHtml, '<svg') === 2, 'two inline SVGs');
assert_true(str_contains($formHtml, 'value="[]"'), 'default empty JSON');

echo "\n=== 5. validateScoreSubmission (markers + regression) ===\n";

$base = [
    'submission_uuid'  => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'event_date'       => '2026-03-15',
    'event_name'       => 'Test Event',
    'judge_name'       => 'Judge',
    'competitor_name'  => 'Competitor',
    'competitor_email' => 'c@example.com',
    'sub_bass' => 10, 'mid_bass' => 10, 'midrange' => 10, 'high_freq' => 10, 'spectral_balance' => 10,
    'listening_position' => 10, 'width' => 10, 'height' => 10, 'depth' => 5, 'ambience' => 5,
    'imaging_score' => 30, 'noise' => 3, 'listening_pleasure' => 5,
];

$r0 = validateScoreSubmission($base);
assert_true($r0['ok'], 'valid submit without marker fields');
assert_eq($r0['data']['stage_markers_wh'] ?? null, [], 'wh defaults to []');
assert_eq($r0['data']['stage_markers_depth'] ?? null, [], 'depth defaults to []');
assert_eq($r0['data']['grand_total'] ?? null, 128, 'grand total unchanged (50+40+30+3+5)');

$withMarkers = $base + [
    'stage_markers_wh' => json_encode([['x' => 40, 'y' => 30], ['x' => 80, 'y' => 50]]),
    'stage_markers_depth' => '[]',
];
$r1 = validateScoreSubmission($withMarkers);
assert_true($r1['ok'], 'valid submit with markers');
assert_eq(count($r1['data']['stage_markers_wh']), 2, 'wh has 2 markers');
assert_eq($r1['data']['grand_total'] ?? null, 128, 'markers do not change grand total');

$badMarkers = $base + ['stage_markers_wh' => 'NOT_JSON'];
$r2 = validateScoreSubmission($badMarkers);
assert_true(!$r2['ok'], 'invalid marker JSON fails validation');
assert_true(isset($r2['errors']['stage_markers_wh']), 'error on stage_markers_wh');

$tooMany = $base + [
    'stage_markers_depth' => json_encode(array_fill(0, 6, ['x' => 10, 'y' => 10])),
];
$r3 = validateScoreSubmission($tooMany);
assert_true($r3['ok'], '6 markers still valid (capped)');
assert_eq(count($r3['data']['stage_markers_depth']), 4, 'capped to 4 in validated data');

// Existing required-field regression
$missing = $base;
unset($missing['width']);
$r4 = validateScoreSubmission($missing);
assert_true(!$r4['ok'] && isset($r4['errors']['width']), 'missing width still fails');

$outOfRange = $base;
$outOfRange['ambience'] = 99;
$r5 = validateScoreSubmission($outOfRange);
assert_true(!$r5['ok'] && isset($r5['errors']['ambience']), 'ambience out of range still fails');

$badUuid = $base;
$badUuid['submission_uuid'] = 'not-a-uuid';
$r6 = validateScoreSubmission($badUuid);
assert_true(!$r6['ok'] && isset($r6['errors']['submission_uuid']), 'bad UUID still fails');

echo "\n=== 6. PDF generation ===\n";
$scoreRow = $r1['data'] + [
    'vehicle_year' => 2020, 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic', 'vehicle_color' => 'Black',
    'tonal_notes' => null, 'stage_notes' => 'Wide', 'imaging_notes' => null, 'noise_notes' => null, 'listening_notes' => null,
    'placement' => null,
];
try {
    $pdfEmpty = generateScorecardPdf($r0['data'] + $scoreRow);
    assert_true(str_starts_with($pdfEmpty, '%PDF'), 'PDF without markers');
    assert_true(strlen($pdfEmpty) > 50000, 'PDF without markers has artwork payload');

    $pdfMarked = generateScorecardPdf($scoreRow);
    assert_true(str_starts_with($pdfMarked, '%PDF'), 'PDF with markers');
    assert_true(strlen($pdfMarked) > 50000, 'PDF with markers has artwork payload');

    $uri = stageDiagramPdfDataUri('width_height', [['x' => 50, 'y' => 40]]);
    assert_true(is_string($uri) && str_starts_with($uri, 'data:image/png;base64,'), 'PNG data URI with pins');
} catch (Throwable $e) {
    assert_true(false, 'PDF generation threw: ' . $e->getMessage());
}

echo "\n=== 7. Public SVG assets reachable on disk ===\n";
assert_true(is_readable($root . '/public/assets/svg/width-height.svg'), 'public width-height.svg');
assert_true(is_readable($root . '/public/assets/svg/depth.svg'), 'public depth.svg');
assert_true(is_readable($root . '/assets/svg/Width_Height.svg'), 'source Width_Height.svg');
assert_true(is_readable($root . '/assets/svg/depth.svg'), 'source depth.svg');
assert_true(is_readable($root . '/assets/svg/width-height.png'), 'pdf png wh');
assert_true(is_readable($root . '/assets/svg/depth.png'), 'pdf png depth');

// Artwork identity: public copy matches source for depth; WH renamed but same bytes
assert_eq(
    md5_file($root . '/assets/svg/depth.svg'),
    md5_file($root . '/public/assets/svg/depth.svg'),
    'depth.svg public === source'
);
assert_eq(
    md5_file($root . '/assets/svg/Width_Height.svg'),
    md5_file($root . '/public/assets/svg/width-height.svg'),
    'width-height.svg public === Width_Height.svg source'
);

echo "\n=== 8. DB schema + round-trip ===\n";
try {
    $pdo = db();
    $cols = $pdo->query("SHOW COLUMNS FROM scores")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('stage_markers_wh', $cols, true), 'column stage_markers_wh exists');
    $cols = $pdo->query("SHOW COLUMNS FROM scores")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('stage_markers_depth', $cols, true), 'column stage_markers_depth exists');

    // Existing scores still readable
    $legacy = dbFetchOne('SELECT id, grand_total, stage_markers_wh, stage_markers_depth FROM scores ORDER BY id ASC LIMIT 1');
    if ($legacy === null) {
        echo "  SKIP  no seeded scores to read\n";
    } else {
        assert_true(isset($legacy['id']), 'legacy score row readable');
        $parsedWh = parseStageMarkers($legacy['stage_markers_wh'] ?? null, 'width_height');
        assert_true(is_array($parsedWh), 'legacy NULL markers parse to array');
    }

    // Insert + select round-trip (rollback) — only columns present in this DB
    $pdo->beginTransaction();
    $uuid = sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', random_int(1, 999999999999));
    $markersJson = encodeStageMarkers([['x' => 12.3, 'y' => 45.6], ['x' => 70, 'y' => 20]]);
    $hasCompetitorId = in_array('competitor_id', $cols, true);
    if ($hasCompetitorId) {
        dbQuery(
            'INSERT INTO scores (
                submission_uuid, competitor_id, judge_user_id, event_id,
                event_date, event_name, judge_name, competitor_name, competitor_email,
                vehicle_year, vehicle_make, vehicle_model, vehicle_color,
                sub_bass, mid_bass, midrange, high_freq, spectral_balance, tonal_notes,
                listening_position, width, height, depth, ambience, stage_notes,
                stage_markers_wh, stage_markers_depth,
                imaging_score, imaging_notes, noise, listening_pleasure, noise_notes, listening_notes,
                tonal_total, stage_total, grand_total, placement
            ) VALUES (
                ?, NULL, NULL, NULL,
                ?, ?, ?, ?, ?,
                NULL, NULL, NULL, NULL,
                10,10,10,10,10, NULL,
                10,10,10,5,5, NULL,
                ?, ?,
                30, NULL, 3, 5, NULL, NULL,
                50, 40, 128, NULL
            )',
            [$uuid, '2026-08-07', 'Marker Test Event', 'Test Judge', 'Temp Competitor', 'temp@example.com', $markersJson, '[]']
        );
    } else {
        dbQuery(
            'INSERT INTO scores (
                submission_uuid,
                event_date, event_name, judge_name, competitor_name, competitor_email,
                sub_bass, mid_bass, midrange, high_freq, spectral_balance,
                listening_position, width, height, depth, ambience, stage_notes,
                stage_markers_wh, stage_markers_depth,
                imaging_score, noise, listening_pleasure,
                tonal_total, stage_total, grand_total
            ) VALUES (
                ?,
                ?, ?, ?, ?, ?,
                10,10,10,10,10,
                10,10,10,5,5, NULL,
                ?, ?,
                30, 3, 5,
                50, 40, 128
            )',
            [$uuid, '2026-08-07', 'Marker Test Event', 'Test Judge', 'Temp Competitor', 'temp@example.com', $markersJson, '[]']
        );
    }
    $row = dbFetchOne('SELECT stage_markers_wh, stage_markers_depth, grand_total FROM scores WHERE submission_uuid = ?', [$uuid]);
    assert_true($row !== null, 'inserted test score');
    $back = parseStageMarkers($row['stage_markers_wh'] ?? null, 'width_height');
    assert_eq(count($back ?? []), 2, 'DB round-trip marker count');
    assert_eq((float) ($back[0]['x'] ?? 0), 12.3, 'DB round-trip x');
    assert_eq((int) $row['grand_total'], 128, 'DB grand_total intact');

    // Update markers on existing row
    dbQuery(
        'UPDATE scores SET stage_markers_depth = ? WHERE submission_uuid = ?',
        [encodeStageMarkers([['x' => 100, 'y' => 40]]), $uuid]
    );
    $row2 = dbFetchOne('SELECT stage_markers_depth FROM scores WHERE submission_uuid = ?', [$uuid]);
    $back2 = parseStageMarkers($row2['stage_markers_depth'] ?? null, 'depth');
    assert_eq(count($back2 ?? []), 1, 'DB update depth markers');
    assert_eq((float) ($back2[0]['x'] ?? 0), 100.0, 'DB update depth x');

    $pdo->rollBack();
    echo "  INFO  rolled back test insert\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    assert_true(false, 'DB tests threw: ' . $e->getMessage());
}

echo "\n=== 9. API detail payload shape (DB-backed) ===\n";
try {
    $existing = dbFetchOne('SELECT id FROM scores ORDER BY id ASC LIMIT 1');
    if ($existing === null) {
        echo "  SKIP  no scores for API shape check\n";
    } else {
        // Mimic api/scores.php detail mapping
        $row = dbFetchOne(
            'SELECT s.stage_markers_wh, s.stage_markers_depth, s.width, s.height, s.depth, s.ambience, s.grand_total
             FROM scores s WHERE s.id = ?',
            [(int) $existing['id']]
        );
        $payload = [
            'stage_markers_wh' => parseStageMarkers($row['stage_markers_wh'] ?? null, 'width_height') ?? [],
            'stage_markers_depth' => parseStageMarkers($row['stage_markers_depth'] ?? null, 'depth') ?? [],
            'width' => (int) $row['width'],
            'grand_total' => (int) $row['grand_total'],
        ];
        assert_true(is_array($payload['stage_markers_wh']), 'API detail wh is array');
        assert_true(is_array($payload['stage_markers_depth']), 'API detail depth is array');
        assert_true($payload['width'] >= 1 && $payload['width'] <= 15, 'score fields still present');
        $encoded = json_encode($payload);
        assert_true($encoded !== false && str_contains($encoded, 'stage_markers_wh'), 'JSON encodes markers');
    }
} catch (Throwable $e) {
    assert_true(false, 'API shape threw: ' . $e->getMessage());
}

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\nFailed: {$fail}\n";
if ($errors) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}
echo "All checks passed.\n";
exit(0);
