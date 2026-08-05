<?php
/**
 * Admin panel helpers: score overview, scorecard email, judge accounts.
 */

declare(strict_types=1);

require_once __DIR__ . '/competitors.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/email.php';

/**
 * All competitors with optional linked score for the admin dashboard.
 *
 * @return list<array<string, mixed>>
 */
function listAdminCompetitors(): array
{
    return dbFetchAll(
        'SELECT c.*,
                s.id AS score_id,
                s.grand_total,
                s.event_name AS score_event_name,
                s.event_date AS score_event_date,
                s.judge_name AS score_judge_name,
                s.placement AS score_placement,
                s.created_at AS scored_at
         FROM competitors c
         LEFT JOIN scores s ON s.competitor_id = c.id
         ORDER BY c.created_at DESC, c.id DESC'
    );
}

/**
 * Submitted scores newest-first (one per competitor by design).
 *
 * @return list<array<string, mixed>>
 */
function listSubmittedScores(): array
{
    return dbFetchAll(
        'SELECT s.*,
                c.scorecard_sent_at,
                c.status AS competitor_status
         FROM scores s
         LEFT JOIN competitors c ON c.id = s.competitor_id
         ORDER BY s.created_at DESC, s.id DESC'
    );
}

/**
 * @return array<string, mixed>|null
 */
function findScoreByCompetitorId(int $competitorId): ?array
{
    return dbFetchOne('SELECT * FROM scores WHERE competitor_id = ?', [$competitorId]);
}

/**
 * Generate PDF and email scorecard for a scored competitor; mark sent on success.
 *
 * @return array{ok:bool,error:?string,provider:?string}
 */
function sendCompetitorScorecard(int $competitorId): array
{
    $competitor = findCompetitorById($competitorId);
    if ($competitor === null) {
        return ['ok' => false, 'error' => 'Competitor not found.', 'provider' => null];
    }

    $score = findScoreByCompetitorId($competitorId);
    if ($score === null) {
        return ['ok' => false, 'error' => 'No score on file for this competitor.', 'provider' => null];
    }

    // Prefer live competitor contact if present
    if (!empty($competitor['email'])) {
        $score['competitor_email'] = (string) $competitor['email'];
    }
    if (!empty($competitor['name'])) {
        $score['competitor_name'] = (string) $competitor['name'];
    }

    $email = trim((string) ($score['competitor_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Competitor has no valid email address.', 'provider' => null];
    }

    try {
        $pdf = generateScorecardPdf($score);
    } catch (Throwable $e) {
        error_log('Scorecard PDF failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not generate PDF scorecard.', 'provider' => null];
    }

    $mail = sendScorecardEmail($score, $pdf, null);
    if (!$mail['ok']) {
        return $mail;
    }

    dbQuery(
        'UPDATE competitors SET scorecard_sent_at = CURRENT_TIMESTAMP WHERE id = ?',
        [$competitorId]
    );

    return $mail;
}

/**
 * @return list<array<string, mixed>>
 */
function listStaffUsers(): array
{
    return dbFetchAll(
        'SELECT id, email, name, role, created_at
         FROM users
         ORDER BY FIELD(role, \'admin\', \'judge\'), name ASC, id ASC'
    );
}

/**
 * Create a judge account.
 *
 * @return array{ok:bool,errors:array<string,string>,user:?array<string,mixed>}
 */
function createJudgeAccount(string $email, string $name, string $password): array
{
    $errors = [];
    $email = strtolower(trim($email));
    $name = trim($name);

    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (mb_strlen($email) > 255) {
        $errors['email'] = 'Email is too long.';
    }

    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 255) {
        $errors['name'] = 'Name is too long.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $errors['password'] = 'Password is too long.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'user' => null];
    }

    $existing = dbFetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing !== null) {
        return [
            'ok'     => false,
            'errors' => ['email' => 'An account with this email already exists.'],
            'user'   => null,
        ];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    if ($hash === false) {
        return [
            'ok'     => false,
            'errors' => ['_form' => 'Could not hash password.'],
            'user'   => null,
        ];
    }

    try {
        dbQuery(
            'INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, \'judge\')',
            [$email, $hash, $name]
        );
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return [
                'ok'     => false,
                'errors' => ['email' => 'An account with this email already exists.'],
                'user'   => null,
            ];
        }
        throw $e;
    }

    $id = (int) db()->lastInsertId();
    $user = dbFetchOne(
        'SELECT id, email, name, role, created_at FROM users WHERE id = ?',
        [$id]
    );

    return ['ok' => true, 'errors' => [], 'user' => $user];
}
