<?php
// Central bootstrap: constants, autoload, environment, security headers.
declare(strict_types=1);

if (!defined('VC_ROOT')) {
    define('VC_ROOT', dirname(__DIR__, 2));
    define('VC_PRIVATE', VC_ROOT . '/private');
    define('VC_PUBLIC', VC_ROOT . '/public');
}

// Config loader (.env files with 600 perms, never committed).
if (!function_exists('vc_env')) {
function vc_env(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ([VC_PRIVATE . '/config/app.env', VC_PRIVATE . '/config/seed.env', VC_PRIVATE . '/config/telegram.env', VC_PRIVATE . '/config/ai.env'] as $f) {
            if (is_file($f)) {
                foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                    $cache[trim($k)] = trim($v);
                }
            }
        }
    }
    return $cache[$key] ?? (getenv($key) ?: $default);
}
}

static $loader_registered = false;
if (!$loader_registered) {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'VC\\')) {
            $path = VC_PRIVATE . '/src/' . str_replace('\\', '/', substr($class, 3)) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}

require_once VC_PRIVATE . '/src/Compat.php';
require_once VC_PRIVATE . '/src/Database.php';

// Hardening: never advertise PHP, never leak errors to output.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', VC_PRIVATE . '/logs/php_error.log');
ini_set('expose_php', '0');
date_default_timezone_set('UTC');
