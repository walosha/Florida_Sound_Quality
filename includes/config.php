<?php
/**
 * Application config — reads Railway env vars (or local .env).
 * Lives outside the web root; never served over HTTP.
 */

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

/**
 * Load key=value pairs from a .env file into the process environment
 * when the key is not already set (Railway / shell env wins).
 */
function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Strip matching single or double quotes
        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'"))
            )
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

loadEnvFile(APP_ROOT . '/.env');

/** @return string|null */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

// --- Database ----------------------------------------------------------------

$mysqlHost = env('MYSQLHOST');
$mysqlPort = env('MYSQLPORT', '3306');
$mysqlUser = env('MYSQLUSER');
$mysqlPass = env('MYSQLPASSWORD', '');
$mysqlDb   = env('MYSQLDATABASE');

// Fall back to MYSQL_URL if discrete vars are missing
if ($mysqlHost === null || $mysqlUser === null || $mysqlDb === null) {
    $mysqlUrl = env('MYSQL_URL') ?? env('DATABASE_URL');
    if ($mysqlUrl !== null) {
        $parts = parse_url($mysqlUrl);
        if ($parts !== false) {
            $mysqlHost = $mysqlHost ?? ($parts['host'] ?? null);
            $mysqlPort = $mysqlPort ?? (isset($parts['port']) ? (string) $parts['port'] : '3306');
            $mysqlUser = $mysqlUser ?? ($parts['user'] ?? null);
            $mysqlPass = $mysqlPass !== null && $mysqlPass !== ''
                ? $mysqlPass
                : (isset($parts['pass']) ? urldecode($parts['pass']) : '');
            $mysqlDb   = $mysqlDb ?? (isset($parts['path']) ? ltrim($parts['path'], '/') : null);
        }
    }
}

define('DB_HOST', $mysqlHost ?? '127.0.0.1');
define('DB_PORT', $mysqlPort ?? '3306');
define('DB_USER', $mysqlUser ?? 'root');
define('DB_PASS', $mysqlPass ?? '');
define('DB_NAME', $mysqlDb ?? 'florida_sound_quality');

// --- Auth --------------------------------------------------------------------

define('JUDGE_PASSWORD_HASH', env('JUDGE_PASSWORD_HASH') ?? '');

// --- Mail (Phase 4) ----------------------------------------------------------

define('SMTP_HOST', env('SMTP_HOST') ?? '');
define('SMTP_PORT', (int) (env('SMTP_PORT') ?? '587'));
define('SMTP_USER', env('SMTP_USER') ?? '');
define('SMTP_PASS', env('SMTP_PASS') ?? '');
define('SMTP_SECURE', env('SMTP_SECURE') ?? 'tls');
define('MAIL_FROM', env('MAIL_FROM') ?? '');
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME') ?? 'Florida Sound Quality');

// --- HTTPS detection behind Railway's edge proxy -----------------------------

/**
 * Railway terminates TLS at the edge and forwards plain HTTP internally,
 * so $_SERVER['HTTPS'] is never set. Prefer X-Forwarded-Proto.
 */
function isHttps(): bool
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (strtolower($forwarded) === 'https') {
        return true;
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

// Rate-limit policy (Q5): 5 failures / 15 min lockout
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 900);
