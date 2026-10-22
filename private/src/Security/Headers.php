<?php
// Security headers on every HTTP response. Call before any output.
declare(strict_types=1);

namespace VC\Security;

final class Headers
{
    public static function nonce(): string
    {
        static $n = null;
        if ($n === null) {
            $n = bin2hex(random_bytes(16));
        }
        return $n;
    }

    public static function send(): void
    {
        $n = self::nonce();
        $csp = "default-src 'self'; "
            . "script-src 'self' 'nonce-$n'; "
            . "script-src-attr 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; "
            . "font-src 'self'; "
            . "connect-src 'self' wss: ws:; "
            . "worker-src 'self' blob:; "
            . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'";
        header("Content-Security-Policy: $csp");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // SAMEORIGIN: halaman dimuat di dalam shell layar penuh milik situs sendiri (pixel-shell.js).
        header('X-Frame-Options: SAMEORIGIN');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(self), geolocation=()');
        header_remove('X-Powered-By');
        // HTTPS expected at go-live; HSTS sent when detected.
        $isHttpsServer = (($_SERVER['HTTPS'] ?? '') === 'on');
        $isHttpsProxy = str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttpsServer || $isHttpsProxy) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
