<?php
// Dev router for `php -S host:port private/bin/router_dev.php` — mimics Nginx rewrite:
// static files (with extensions) served directly; everything else -> public/index.php.
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../../public' . $path;
if ($path !== '/' && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false; // serve static asset as-is
}
require __DIR__ . '/../../public/index.php';
