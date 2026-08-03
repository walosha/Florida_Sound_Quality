<?php
/**
 * Server-side scoring validation and total recalculation.
 */

declare(strict_types=1);

/**
 * Score field definitions: name => [min, max, label, section].
 *
 * @return array<string, array{min:int,max:int,label:string,section:string}>
 */
function scoreFieldDefs(): array
{
    return [
        'sub_bass'           => ['min' => 1, 'max' => 20, 'label' => 'Sub-Bass', 'section' => 'tonal'],
        'mid_bass'           => ['min' => 1, 'max' => 20, 'label' => 'Mid-Bass', 'section' => 'tonal'],
        'midrange'           => ['min' => 1, 'max' => 20, 'label' => 'Midrange', 'section' => 'tonal'],
        'high_freq'          => ['min' => 1, 'max' => 20, 'label' => 'High Frequency', 'section' => 'tonal'],
        'spectral_balance'   => ['min' => 1, 'max' => 20, 'label' => 'Spectral Balance', 'section' => 'tonal'],
        'listening_position' => ['min' => 1, 'max' => 15, 'label' => 'Listening Position', 'section' => 'stage'],
        'width'              => ['min' => 1, 'max' => 15, 'label' => 'Width', 'section' => 'stage'],
        'height'             => ['min' => 1, 'max' => 15, 'label' => 'Height', 'section' => 'stage'],
        'depth'              => ['min' => 1, 'max' => 10, 'label' => 'Depth', 'section' => 'stage'],
        'ambience'           => ['min' => 1, 'max' => 10, 'label' => 'Ambience', 'section' => 'stage'],
        'imaging_score'      => ['min' => 1, 'max' => 50, 'label' => 'Imaging', 'section' => 'imaging'],
        'noise'              => ['min' => 1, 'max' => 5,  'label' => 'Noise', 'section' => 'noise'],
        'listening_pleasure' => ['min' => 1, 'max' => 10, 'label' => 'Listening Pleasure', 'section' => 'noise'],
    ];
}

/**
 * Validate POST/input data. Returns ['ok' => bool, 'errors' => [...], 'data' => [...]].
 * Totals in data are always server-calculated.
 *
 * @param array<string, mixed> $input
 * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
 */
function validateScoreSubmission(array $input): array
{
    $errors = [];
    $data = [];

    // Header — required strings
    $requiredStrings = [
        'submission_uuid'  => 'Submission ID',
        'event_name'       => 'Event name',
        'judge_name'       => 'Judge name',
        'competitor_name'  => 'Competitor name',
        'competitor_email' => 'Competitor email',
    ];

    foreach ($requiredStrings as $key => $label) {
        $val = trim((string) ($input[$key] ?? ''));
        if ($val === '') {
            $errors[$key] = "{$label} is required.";
        } else {
            $data[$key] = $val;
        }
    }

    if (isset($data['submission_uuid']) && !preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $data['submission_uuid']
    )) {
        $errors['submission_uuid'] = 'Invalid submission ID.';
    }

    if (isset($data['competitor_email']) && !filter_var($data['competitor_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['competitor_email'] = 'Enter a valid email address.';
    }

    // Event date
    $eventDate = trim((string) ($input['event_date'] ?? ''));
    if ($eventDate === '') {
        $errors['event_date'] = 'Event date is required.';
    } else {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $eventDate);
        $ok = $dt && $dt->format('Y-m-d') === $eventDate;
        if (!$ok) {
            $errors['event_date'] = 'Event date must be YYYY-MM-DD.';
        } else {
            $data['event_date'] = $eventDate;
        }
    }

    // Optional vehicle fields
    $vehicleYear = trim((string) ($input['vehicle_year'] ?? ''));
    if ($vehicleYear === '') {
        $data['vehicle_year'] = null;
    } else {
        $y = filter_var($vehicleYear, FILTER_VALIDATE_INT);
        if ($y === false || $y < 1900 || $y > 2100) {
            $errors['vehicle_year'] = 'Vehicle year must be between 1900 and 2100.';
        } else {
            $data['vehicle_year'] = $y;
        }
    }

    foreach (['vehicle_make' => 100, 'vehicle_model' => 100, 'vehicle_color' => 50] as $key => $maxLen) {
        $val = trim((string) ($input[$key] ?? ''));
        if (mb_strlen($val) > $maxLen) {
            $errors[$key] = 'Too long.';
        } else {
            $data[$key] = $val === '' ? null : $val;
        }
    }

    // Score steppers
    foreach (scoreFieldDefs() as $key => $def) {
        $raw = $input[$key] ?? null;
        if ($raw === null || $raw === '') {
            $errors[$key] = "{$def['label']} is required ({$def['min']}–{$def['max']}).";
            continue;
        }
        $n = filter_var($raw, FILTER_VALIDATE_INT);
        if ($n === false || $n < $def['min'] || $n > $def['max']) {
            $errors[$key] = "{$def['label']} must be {$def['min']}–{$def['max']}.";
        } else {
            $data[$key] = $n;
        }
    }

    // Notes — optional text
    foreach (['tonal_notes', 'stage_notes', 'imaging_notes', 'noise_notes', 'listening_notes'] as $key) {
        $val = trim((string) ($input[$key] ?? ''));
        $data[$key] = $val === '' ? null : $val;
    }

    // Placement — optional
    $placement = trim((string) ($input['placement'] ?? ''));
    if (mb_strlen($placement) > 100) {
        $errors['placement'] = 'Placement is too long.';
    } else {
        $data['placement'] = $placement === '' ? null : $placement;
    }

    // Server-side totals (never trust client)
    if (
        isset($data['sub_bass'], $data['mid_bass'], $data['midrange'], $data['high_freq'], $data['spectral_balance'])
    ) {
        $data['tonal_total'] = $data['sub_bass'] + $data['mid_bass'] + $data['midrange']
            + $data['high_freq'] + $data['spectral_balance'];
    }

    if (
        isset($data['listening_position'], $data['width'], $data['height'], $data['depth'], $data['ambience'])
    ) {
        $data['stage_total'] = $data['listening_position'] + $data['width'] + $data['height']
            + $data['depth'] + $data['ambience'];
    }

    if (
        isset($data['tonal_total'], $data['stage_total'], $data['imaging_score'], $data['noise'], $data['listening_pleasure'])
    ) {
        $data['grand_total'] = $data['tonal_total'] + $data['stage_total']
            + $data['imaging_score'] + $data['noise'] + $data['listening_pleasure'];
    }

    return [
        'ok'     => $errors === [],
        'errors' => $errors,
        'data'   => $data,
    ];
}
