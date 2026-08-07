<?php
/**
 * Local-dev front controller for `php -S localhost:8000 router.php`.
 * Mirrors Railway/Apache behaviour: only files under public/ are reachable.
 */

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$public = __DIR__ . '/public';

// Pretty competitor registration: /competitor
if (preg_match('#^/competitor/?$#', $uri)) {
    require $public . '/competitor.php';
    return true;
}

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

// Serve static files from public/ ourselves. Returning false makes the built-in
// server look under the project CWD (not public/), which 404s assets that exist
// only under public/ (e.g. /assets/svg/width-height.svg).
$mimeByExt = [
    'css'  => 'text/css; charset=UTF-8',
    'js'   => 'application/javascript; charset=UTF-8',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'ico'  => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'map'  => 'application/json',
    'json' => 'application/json',
    'txt'  => 'text/plain; charset=UTF-8',
    'pdf'  => 'application/pdf',
];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mimeByExt[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
readfile($path);
return true;
