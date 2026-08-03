<?php
/**
 * DB-backed session handler + auth helpers.
 *
 * Sessions live in MySQL so they survive Railway container redeploys
 * and can be shared across replicas.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = dbFetchOne(
            'SELECT data FROM sessions WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return '';
        }
        // MEDIUMBLOB may come back as a stream or string
        $data = $row['data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        dbQuery(
            'INSERT INTO sessions (id, data, last_access)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), last_access = VALUES(last_access)',
            [$id, $data, time()]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        dbQuery('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $cutoff = time() - $max_lifetime;
        $stmt = dbQuery('DELETE FROM sessions WHERE last_access < ?', [$cutoff]);
        return $stmt->rowCount();
    }
}

/**
 * Start a secure session with the DB handler. Call once per request
 * before reading $_SESSION.
 */
function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $handler = new DbSessionHandler();
    session_set_save_handler($handler, true);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('FSQSESSID');
    session_start();
}

/**
 * Redirect to login if the judge is not authenticated.
 */
function requireLogin(): void
{
    startAppSession();

    if (empty($_SESSION['authenticated'])) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Whether the current session is an authenticated judge.
 */
function isLoggedIn(): bool
{
    startAppSession();
    return !empty($_SESSION['authenticated']);
}

/**
 * Verify a plaintext password against JUDGE_PASSWORD_HASH.
 */
function verifyJudgePassword(string $password): bool
{
    if (JUDGE_PASSWORD_HASH === '') {
        return false;
    }
    return password_verify($password, JUDGE_PASSWORD_HASH);
}

/**
 * Client IP for rate limiting (respects X-Forwarded-For from Railway).
 */
function clientIp(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * True if this IP is currently locked out of login.
 */
function isLoginLockedOut(string $ip): bool
{
    $row = dbFetchOne(
        'SELECT attempts, UNIX_TIMESTAMP(last_attempt) AS last_ts
         FROM rate_limit WHERE ip_address = ?',
        [$ip]
    );
    if ($row === null) {
        return false;
    }
    if ((int) $row['attempts'] < LOGIN_MAX_ATTEMPTS) {
        return false;
    }
    $elapsed = time() - (int) $row['last_ts'];
    return $elapsed < LOGIN_LOCKOUT_SECONDS;
}

/**
 * Record a failed login attempt for this IP.
 */
function recordFailedLogin(string $ip): void
{
    $row = dbFetchOne(
        'SELECT attempts, UNIX_TIMESTAMP(last_attempt) AS last_ts
         FROM rate_limit WHERE ip_address = ?',
        [$ip]
    );

    if ($row === null) {
        dbQuery(
            'INSERT INTO rate_limit (ip_address, attempts, last_attempt)
             VALUES (?, 1, CURRENT_TIMESTAMP)',
            [$ip]
        );
        return;
    }

    $elapsed = time() - (int) $row['last_ts'];
    // Window expired — reset counter
    if ($elapsed >= LOGIN_LOCKOUT_SECONDS) {
        dbQuery(
            'UPDATE rate_limit SET attempts = 1, last_attempt = CURRENT_TIMESTAMP
             WHERE ip_address = ?',
            [$ip]
        );
        return;
    }

    dbQuery(
        'UPDATE rate_limit SET attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP
         WHERE ip_address = ?',
        [$ip]
    );
}

/**
 * Clear rate-limit state after a successful login.
 */
function clearLoginAttempts(string $ip): void
{
    dbQuery('DELETE FROM rate_limit WHERE ip_address = ?', [$ip]);
}

/**
 * Generate and store a CSRF token; return it.
 */
function csrfToken(): string
{
    startAppSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token (timing-safe).
 */
function verifyCsrf(?string $token): bool
{
    startAppSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || $token === null || $token === '') {
        return false;
    }
    return hash_equals($expected, $token);
}
