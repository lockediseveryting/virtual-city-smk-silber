<?php
// Correct client IP with trusted-proxy awareness, IPv6 normalized to /64.
declare(strict_types=1);

namespace VC\Security;

final class Ip
{
    public static function isPrivate(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Proxy yang boleh mengirim header IP asli: loopback + TRUSTED_PROXIES. */
    public static function fromTrustedProxy(): bool
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if ($remote === '127.0.0.1' || $remote === '::1') {
            return true;
        }
        $trusted = array_filter(array_map('trim', explode(',', (string)\vc_env('TRUSTED_PROXIES', ''))));
        return $trusted !== [] && in_array($remote, $trusted, true);
    }

    public static function client(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        // Header IP (X-Forwarded-For dll.) HANYA dari proxy tepercaya. Gateway NAT sekolah (10.10.26.1)
        // TIDAK menambahkan header ini (dicek 17 Okt 2026), jadi nilainya selalu buatan klien → dulu
        // scanner bisa memalsukan IP tiap permintaan dan lolos dari blokir / batas laju / batas login.
        if (self::fromTrustedProxy()) {
            foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $h) {
                $v = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '';
                if ($v !== '') {
                    $parts = array_map('trim', explode(',', $v));
                    $candidate = $parts[0];
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        return $candidate;
                    }
                }
            }
        }
        return $remote;
    }

    /**
     * Semua pengguna dari internet tampak sebagai IP gateway NAT yang sama → kunci per-IP saja = satu
     * kuota untuk seluruh sekolah (13–14 Okt: 1.223× 429 untuk semua). Untuk IP privat non-loopback
     * kunci dipersempit dengan tanda User-Agent.
     */
    public static function signature(): string
    {
        $ip = self::client();
        if (self::isPrivate($ip) && $ip !== '127.0.0.1' && $ip !== '::1') {
            return $ip . ':ua_' . substr(md5($_SERVER['HTTP_USER_AGENT'] ?? 'none'), 0, 16);
        }
        return $ip;
    }

    /** Bucket key: full IPv4, /64 prefix for IPv6 so one dial-up household = one bucket. */
    public static function bucket(): string
    {
        $ip = self::client();
        if (self::isPrivate($ip) && $ip !== '127.0.0.1' && $ip !== '::1') {
            return self::signature();
        }
        if (str_contains($ip, ':')) {
            $b = @inet_pton($ip);
            if ($b !== false) {
                return bin2hex(substr($b, 0, 8)) . '::/64';
            }
        }
        return $ip;
    }
}
