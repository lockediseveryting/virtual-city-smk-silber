<?php
// CSRF: per-session synchronizer token + Origin/Referer validation on POST.
declare(strict_types=1);

namespace VC\Security;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    /** Call on every state-changing request. Kills the request (log + 403) on failure. */
    public static function check(?string $provided): void
    {
        $originOk = true;
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        $forwardedHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
        $allowedHosts = array_filter([
            explode(':', $host)[0] ?: null,
            explode(':', $forwardedHost)[0] ?: null,
            'localhost',
            '127.0.0.1',
        ]);
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
            $v = $_SERVER[$h] ?? '';
            if ($v !== '') {
                if (!str_starts_with($v, 'http')) {
                    continue;
                }
                $host_from_url = parse_url($v, PHP_URL_HOST);
                if ($host_from_url !== null) {
                    $matched = false;
                    foreach ($allowedHosts as $ah) {
                        if (strcasecmp((string)$host_from_url, $ah) === 0) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched && !empty($allowedHosts)) {
                        $originOk = false;
                    }
                }
            }
        }
        $tokenOk = is_string($provided) && $provided !== ''
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $provided);
        if (!$tokenOk || !$originOk) {
            SecurityLog::write('csrf_rejected', ['origin_ok' => $originOk ? '1' : '0']);
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Sesi tidak valid. Muat ulang halaman dan coba lagi.']);
            exit;
        }
    }
}
