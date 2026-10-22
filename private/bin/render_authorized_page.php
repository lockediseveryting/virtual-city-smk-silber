<?php
// Test helper (CLI only): renders a public route exactly like a browser would,
// optionally with a synthetic active student session so auth-gated pages render.
// Fixture only — never invoked by the web server.
declare(strict_types=1);

$uri = $argv[1] ?? '/';
$userId = isset($argv[2]) && $argv[2] !== '' ? (int)$argv[2] : null;

$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require __DIR__ . '/../src/bootstrap.php';

\VC\Security\Auth::startSession();
if ($userId !== null) {
    $_SESSION['user_id'] = $userId;
}

require \VC_PUBLIC . '/index.php';
