<?php
/**
 * Local-dev front controller for `php -S localhost:8000 router.php`.
 * Mirrors Railway/Apache behaviour: only files under public/ are reachable.
 */

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$public = __DIR__ . '/public';
$candidate = $public . $uri;
$path = file_exists($candidate) ? realpath($candidate) : false;

if ($path !== false && is_dir($path)) {
    $index = $path . DIRECTORY_SEPARATOR . 'index.php';
    $path = is_file($index) ? realpath($index) : false;
}

$publicReal = realpath($public);
if ($path === false || $publicReal === false || !str_starts_with($path, $publicReal)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '404 Not Found';
    return true;
}

if (str_ends_with($path, '.php')) {
    require $path;
    return true;
}

return false; // static assets → built-in server
