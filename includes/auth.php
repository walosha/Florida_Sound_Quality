<?php
/**
 * DB-backed session handler + role-based auth helpers.
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
 * Redirect to login if not authenticated.
 */
function requireLogin(): void
{
    startAppSession();

    if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require an authenticated user with one of the given roles.
 *
 * @param string|list<string> $roles
 */
function requireRole(string|array $roles): void
{
    requireLogin();

    $allowed = is_array($roles) ? $roles : [$roles];
    $role = (string) ($_SESSION['user_role'] ?? '');

    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden.';
        exit;
    }
}

/**
 * Whether the current session is authenticated.
 */
function isLoggedIn(): bool
{
    startAppSession();
    return !empty($_SESSION['authenticated']) && !empty($_SESSION['user_id']);
}

/**
 * Current user row from session, or null.
 *
 * @return array{id:int, email:string, name:string, role:string}|null
 */
function currentUser(): ?array
{
    startAppSession();
    if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id'    => (int) $_SESSION['user_id'],
        'email' => (string) ($_SESSION['user_email'] ?? ''),
        'name'  => (string) ($_SESSION['user_name'] ?? ''),
        'role'  => (string) ($_SESSION['user_role'] ?? ''),
    ];
}

/**
 * True if the logged-in user has the given role.
 */
function userHasRole(string $role): bool
{
    $user = currentUser();
    return $user !== null && $user['role'] === $role;
}

/**
 * Establish a logged-in session from a users-table row.
 *
 * @param array<string, mixed> $user
 */
function loginUser(array $user): void
{
    startAppSession();
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_role'] = (string) $user['role'];
    unset($_SESSION['csrf_token']);
}

/**
 * Look up a user by email and verify password. Returns the row or null.
 *
 * @return array<string, mixed>|null
 */
function authenticateUser(string $email, string $password): ?array
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return null;
    }

    $user = dbFetchOne(
        'SELECT id, email, password_hash, name, role FROM users WHERE email = ?',
        [$email]
    );
    if ($user === null) {
        return null;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return null;
    }

    return $user;
}

/**
 * Post-login destination by role.
 */
function homePathForRole(string $role): string
{
    return match ($role) {
        'admin' => '/admin/',
        'judge' => '/score.php',
        default => '/login.php',
    };
}

/**
 * Client IP for rate limiting.
 * Trusts the first X-Forwarded-For hop — valid when a reverse proxy (e.g. Railway)
 * overwrites that header; do not rely on this behind an untrusted edge.
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
 * Namespaced rate_limit key for registration (fits VARCHAR(45) for IPv6).
 */
function registrationRateLimitKey(string $ip): string
{
    $key = 'reg:' . $ip;
    if (strlen($key) <= 45) {
        return $key;
    }
    return 'reg:' . substr(hash('sha256', $ip), 0, 41);
}

/**
 * True if this IP has hit the public registration rate limit.
 */
function isRegistrationRateLimited(string $ip): bool
{
    $row = dbFetchOne(
        'SELECT attempts, UNIX_TIMESTAMP(last_attempt) AS last_ts
         FROM rate_limit WHERE ip_address = ?',
        [registrationRateLimitKey($ip)]
    );
    if ($row === null) {
        return false;
    }
    $elapsed = time() - (int) $row['last_ts'];
    if ($elapsed >= REGISTRATION_WINDOW_SECONDS) {
        return false;
    }
    return (int) $row['attempts'] >= REGISTRATION_MAX_ATTEMPTS;
}

/**
 * Record a registration attempt (success or validation failure after CSRF).
 */
function recordRegistrationAttempt(string $ip): void
{
    $key = registrationRateLimitKey($ip);
    $row = dbFetchOne(
        'SELECT attempts, UNIX_TIMESTAMP(last_attempt) AS last_ts
         FROM rate_limit WHERE ip_address = ?',
        [$key]
    );

    if ($row === null) {
        dbQuery(
            'INSERT INTO rate_limit (ip_address, attempts, last_attempt)
             VALUES (?, 1, CURRENT_TIMESTAMP)',
            [$key]
        );
        return;
    }

    $elapsed = time() - (int) $row['last_ts'];
    if ($elapsed >= REGISTRATION_WINDOW_SECONDS) {
        dbQuery(
            'UPDATE rate_limit SET attempts = 1, last_attempt = CURRENT_TIMESTAMP
             WHERE ip_address = ?',
            [$key]
        );
        return;
    }

    dbQuery(
        'UPDATE rate_limit SET attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP
         WHERE ip_address = ?',
        [$key]
    );
}

/**
 * True if this judge session submitted a score too recently.
 */
function isSubmitCoolingDown(): bool
{
    startAppSession();
    $last = (int) ($_SESSION['last_score_submit_at'] ?? 0);
    if ($last <= 0) {
        return false;
    }
    return (time() - $last) < SUBMIT_COOLDOWN_SECONDS;
}

/**
 * Mark a score-submit attempt for session cooldown.
 */
function markSubmitAttempt(): void
{
    startAppSession();
    $_SESSION['last_score_submit_at'] = time();
}

/**
 * True if an admin resent a scorecard for this competitor too recently.
 */
function isScorecardResendCoolingDown(int $competitorId): bool
{
    startAppSession();
    $map = $_SESSION['scorecard_resend_at'] ?? [];
    if (!is_array($map)) {
        return false;
    }
    $last = (int) ($map[$competitorId] ?? 0);
    if ($last <= 0) {
        return false;
    }
    return (time() - $last) < SCORECARD_RESEND_COOLDOWN_SECONDS;
}

/**
 * Mark a scorecard send attempt for per-competitor cooldown.
 */
function markScorecardResendAttempt(int $competitorId): void
{
    startAppSession();
    if (!isset($_SESSION['scorecard_resend_at']) || !is_array($_SESSION['scorecard_resend_at'])) {
        $_SESSION['scorecard_resend_at'] = [];
    }
    $_SESSION['scorecard_resend_at'][$competitorId] = time();
}

/**
 * Allow an immediate scorecard retry after a non-send failure (e.g. PDF generation).
 */
function clearScorecardResendAttempt(int $competitorId): void
{
    startAppSession();
    if (isset($_SESSION['scorecard_resend_at']) && is_array($_SESSION['scorecard_resend_at'])) {
        unset($_SESSION['scorecard_resend_at'][$competitorId]);
    }
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
