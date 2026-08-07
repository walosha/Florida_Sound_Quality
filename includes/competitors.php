<?php
/**
 * Competitor registration helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pagination.php';

/**
 * Absolute base URL for public links (no trailing slash).
 */
function appBaseUrl(): string
{
    if (APP_BASE_URL !== '') {
        return APP_BASE_URL;
    }

    $scheme = isHttps() ? 'https' : 'http';
    $hostHeader = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', $hostHeader)[0]);
    if ($host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host;
}

/**
 * Public open registration URL (shared by all competitors).
 */
function competitorRegistrationUrl(): string
{
    return appBaseUrl() . '/competitor.php';
}

/**
 * Create a registered competitor from validated form data.
 *
 * @param array<string, mixed> $data Validated registration data
 * @return array{ok:bool,error:?string,competitor:?array<string,mixed>}
 */
function createRegisteredCompetitor(array $data): array
{
    try {
        dbQuery(
            'INSERT INTO competitors (
                status, name, email,
                vehicle_year, vehicle_make, vehicle_model, vehicle_color,
                registered_at
             ) VALUES (
                \'registered\', ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
             )',
            [
                $data['name'],
                $data['email'],
                $data['vehicle_year'],
                $data['vehicle_make'],
                $data['vehicle_model'],
                $data['vehicle_color'],
            ]
        );
    } catch (Throwable $e) {
        error_log('createRegisteredCompetitor failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save registration.', 'competitor' => null];
    }

    $id = (int) db()->lastInsertId();
    $row = findCompetitorById($id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Could not save registration.', 'competitor' => null];
    }

    return ['ok' => true, 'error' => null, 'competitor' => $row];
}

/**
 * Display status for admin/judge UI.
 *
 * @param array<string, mixed> $competitor
 */
function competitorEffectiveStatus(array $competitor): string
{
    return (string) ($competitor['status'] ?? '');
}

/**
 * @return array<string, mixed>|null
 */
function findCompetitorById(int $id): ?array
{
    return dbFetchOne('SELECT * FROM competitors WHERE id = ?', [$id]);
}

/**
 * Bounded listing for ad-hoc use. Prefer listAdminCompetitors() / listJudgeCompetitors().
 *
 * @return list<array<string, mixed>>
 */
function listCompetitors(): array
{
    return dbFetchAll(
        'SELECT c.*, u.name AS created_by_name
         FROM competitors c
         LEFT JOIN users u ON u.id = c.created_by_user_id
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT 500'
    );
}

/**
 * Competitors visible to judges (registered or scored), with score summary (paginated).
 *
 * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int,offset:int,from:int,to:int}
 */
function listJudgeCompetitors(int $page = 1, int $perPage = PAGINATION_DEFAULT_PER_PAGE): array
{
    $where = 'WHERE c.status IN (\'registered\', \'scored\')';
    return dbPaginate(
        "SELECT COUNT(*) AS cnt FROM competitors c {$where}",
        "SELECT c.*,
                s.id AS score_id,
                s.grand_total,
                s.event_name AS score_event_name,
                s.created_at AS scored_at
         FROM competitors c
         LEFT JOIN scores s ON s.competitor_id = c.id
         {$where}
         ORDER BY
           CASE c.status WHEN 'registered' THEN 0 ELSE 1 END,
           c.registered_at DESC,
           c.id DESC",
        [],
        $page,
        $perPage
    );
}

/**
 * Format vehicle fields for display.
 *
 * @param array<string, mixed> $row
 */
function competitorVehicleLabel(array $row): string
{
    $parts = array_filter([
        $row['vehicle_year'] !== null && $row['vehicle_year'] !== ''
            ? (string) $row['vehicle_year']
            : '',
        (string) ($row['vehicle_make'] ?? ''),
        (string) ($row['vehicle_model'] ?? ''),
        (string) ($row['vehicle_color'] ?? ''),
    ], static fn (string $p): bool => $p !== '');

    return $parts !== [] ? implode(' ', $parts) : '—';
}

/**
 * Whether a competitor can receive a new score (registered, no score row yet).
 *
 * @param array<string, mixed> $competitor
 */
function competitorIsScoreable(array $competitor): bool
{
    if (($competitor['status'] ?? '') !== 'registered') {
        return false;
    }
    $existing = dbFetchOne(
        'SELECT id FROM scores WHERE competitor_id = ?',
        [(int) $competitor['id']]
    );
    return $existing === null;
}

/**
 * Validate competitor self-registration fields.
 *
 * @param array<string, mixed> $input
 * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
 */
function validateCompetitorRegistration(array $input): array
{
    $errors = [];
    $data = [];

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 255) {
        $errors['name'] = 'Name must be 255 characters or fewer.';
    } else {
        $data['name'] = $name;
    }

    $email = trim((string) ($input['email'] ?? ''));
    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (mb_strlen($email) > 255) {
        $errors['email'] = 'Email must be 255 characters or fewer.';
    } else {
        $data['email'] = strtolower($email);
    }

    $yearRaw = trim((string) ($input['vehicle_year'] ?? ''));
    if ($yearRaw === '') {
        $errors['vehicle_year'] = 'Vehicle year is required.';
    } else {
        $year = filter_var($yearRaw, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1900 || $year > 2100) {
            $errors['vehicle_year'] = 'Vehicle year must be between 1900 and 2100.';
        } else {
            $data['vehicle_year'] = $year;
        }
    }

    foreach (
        [
            'vehicle_make'  => ['label' => 'Make', 'max' => 100],
            'vehicle_model' => ['label' => 'Model', 'max' => 100],
            'vehicle_color' => ['label' => 'Color', 'max' => 50],
        ] as $key => $meta
    ) {
        $val = trim((string) ($input[$key] ?? ''));
        if ($val === '') {
            $errors[$key] = "{$meta['label']} is required.";
        } elseif (mb_strlen($val) > $meta['max']) {
            $errors[$key] = "{$meta['label']} must be {$meta['max']} characters or fewer.";
        } else {
            $data[$key] = $val;
        }
    }

    return [
        'ok'     => $errors === [],
        'errors' => $errors,
        'data'   => $data,
    ];
}

/**
 * Human-readable status label.
 */
function competitorStatusLabel(string $status): string
{
    return match ($status) {
        'invited'    => 'Invited',
        'registered' => 'Registered',
        'scored'     => 'Scored',
        'revoked'    => 'Revoked',
        'expired'    => 'Expired',
        default      => $status,
    };
}
