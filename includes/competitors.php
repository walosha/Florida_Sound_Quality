<?php
/**
 * Competitor invite + registration helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Absolute base URL for invite links (no trailing slash).
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
 * Public invite URL for a token.
 */
function competitorInviteUrl(string $token): string
{
    return appBaseUrl() . '/competitor.php?token=' . rawurlencode($token);
}

/**
 * Create a new invite row. Returns the competitor row including invite_token.
 *
 * @return array<string, mixed>
 */
function createCompetitorInvite(int $adminUserId): array
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = null;
    if (INVITE_EXPIRY_DAYS > 0) {
        $expiresAt = (new DateTimeImmutable('now'))
            ->modify('+' . INVITE_EXPIRY_DAYS . ' days')
            ->format('Y-m-d H:i:s');
    }

    dbQuery(
        'INSERT INTO competitors (invite_token, status, created_by_user_id, expires_at)
         VALUES (?, \'invited\', ?, ?)',
        [$token, $adminUserId, $expiresAt]
    );

    $id = (int) db()->lastInsertId();
    $row = findCompetitorById($id);
    if ($row === null) {
        throw new RuntimeException('Failed to load invite after create.');
    }

    return $row;
}

/**
 * Revoke an unused invite so the token can no longer register.
 *
 * @return array{ok:bool,error:?string}
 */
function revokeCompetitorInvite(int $competitorId): array
{
    $row = findCompetitorById($competitorId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Invite not found.'];
    }
    if (($row['status'] ?? '') !== 'invited') {
        return ['ok' => false, 'error' => 'Only unused invites can be revoked.'];
    }
    if (!empty($row['revoked_at'])) {
        return ['ok' => false, 'error' => 'Invite is already revoked.'];
    }

    dbQuery(
        'UPDATE competitors SET revoked_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = \'invited\' AND revoked_at IS NULL',
        [$competitorId]
    );

    return ['ok' => true, 'error' => null];
}

/**
 * True when the invite token may still be used for registration.
 *
 * @param array<string, mixed> $competitor
 */
function competitorInviteIsOpen(array $competitor): bool
{
    if (($competitor['status'] ?? '') !== 'invited') {
        return false;
    }
    if (!empty($competitor['revoked_at'])) {
        return false;
    }
    $expiresAt = $competitor['expires_at'] ?? null;
    if ($expiresAt !== null && $expiresAt !== '') {
        $ts = strtotime((string) $expiresAt);
        if ($ts !== false && $ts < time()) {
            return false;
        }
    }
    return true;
}

/**
 * Display status for admin/judge UI (includes revoked/expired overlays).
 *
 * @param array<string, mixed> $competitor
 */
function competitorEffectiveStatus(array $competitor): string
{
    $status = (string) ($competitor['status'] ?? '');
    if ($status === 'invited') {
        if (!empty($competitor['revoked_at'])) {
            return 'revoked';
        }
        $expiresAt = $competitor['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $ts = strtotime((string) $expiresAt);
            if ($ts !== false && $ts < time()) {
                return 'expired';
            }
        }
    }
    return $status;
}

/**
 * @return array<string, mixed>|null
 */
function findCompetitorById(int $id): ?array
{
    return dbFetchOne('SELECT * FROM competitors WHERE id = ?', [$id]);
}

/**
 * @return array<string, mixed>|null
 */
function findCompetitorByToken(string $token): ?array
{
    $token = strtolower(trim($token));
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    return dbFetchOne('SELECT * FROM competitors WHERE invite_token = ?', [$token]);
}

/**
 * @return list<array<string, mixed>>
 */
function listCompetitors(): array
{
    return dbFetchAll(
        'SELECT c.*, u.name AS created_by_name
         FROM competitors c
         LEFT JOIN users u ON u.id = c.created_by_user_id
         ORDER BY c.created_at DESC, c.id DESC'
    );
}

/**
 * Competitors visible to judges (registered or scored), with score summary.
 *
 * @return list<array<string, mixed>>
 */
function listJudgeCompetitors(): array
{
    return dbFetchAll(
        'SELECT c.*,
                s.id AS score_id,
                s.grand_total,
                s.event_name AS score_event_name,
                s.created_at AS scored_at
         FROM competitors c
         LEFT JOIN scores s ON s.competitor_id = c.id
         WHERE c.status IN (\'registered\', \'scored\')
         ORDER BY
           CASE c.status WHEN \'registered\' THEN 0 ELSE 1 END,
           c.registered_at DESC,
           c.id DESC'
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
 * Complete registration for an invited competitor (one-time).
 *
 * @param array<string, mixed> $data Validated registration data
 * @return array{ok:bool,error:?string,competitor:?array<string,mixed>}
 */
function registerCompetitor(int $competitorId, array $data): array
{
    $stmt = dbQuery(
        'UPDATE competitors
         SET name = ?,
             email = ?,
             vehicle_year = ?,
             vehicle_make = ?,
             vehicle_model = ?,
             vehicle_color = ?,
             status = \'registered\',
             registered_at = CURRENT_TIMESTAMP
         WHERE id = ?
           AND status = \'invited\'
           AND revoked_at IS NULL
           AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)',
        [
            $data['name'],
            $data['email'],
            $data['vehicle_year'],
            $data['vehicle_make'],
            $data['vehicle_model'],
            $data['vehicle_color'],
            $competitorId,
        ]
    );

    if ($stmt->rowCount() === 0) {
        $existing = findCompetitorById($competitorId);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'Invite not found.', 'competitor' => null];
        }
        if (!competitorInviteIsOpen($existing)) {
            $eff = competitorEffectiveStatus($existing);
            $msg = match ($eff) {
                'revoked' => 'This invite has been revoked.',
                'expired' => 'This invite has expired.',
                default   => 'This invite has already been used.',
            };
            return [
                'ok'         => false,
                'error'      => $msg,
                'competitor' => $existing,
            ];
        }
        return ['ok' => false, 'error' => 'Could not save registration.', 'competitor' => null];
    }

    return [
        'ok'         => true,
        'error'      => null,
        'competitor' => findCompetitorById($competitorId),
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
