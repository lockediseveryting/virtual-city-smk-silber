<?php
// Scanner traps: honeypot URLs auto-block; 404-storms and pattern probes throttle.
declare(strict_types=1);

namespace VC\Security;

final class Honeypot
{
    private const TRAP_PATHS = [
        '/wp-login.php', '/wp-admin/', '/xmlrpc.php', '/phpmyadmin/', '/adminer.php',
        '/.env', '/.git/config', '/.gitignore', '/backup.zip', '/db.sqlite',
        '/admin.php.bak', '/vendor/', '/config.php.bak', '/.DS_Store', '/feed',
        '/wp-content/', '/admin.html', '/panel/login', '/manager/html',
        '/_bot_trap_sink', '/_katana_trap', '/api/v1/__crawler_trap',
    ];

    /**
     * User-Agent bawaan alat pemindai / crawler otomatis (katana, nuclei, sqlmap, ffuf, …).
     * Alat yang memalsukan UA browser tetap tertangkap jebakan (tautan tersembunyi, URL umpan, badai 404).
     */
    private const SCANNER_UA = '/katana|projectdiscovery|nuclei|interactsh|sqlmap|nikto|nmap|masscan|zgrab|'
        . 'gobuster|feroxbuster|dirbuster|\\bdirb\\b|ffuf|fuzz faster|wfuzz|wpscan|joomscan|whatweb|wappalyzer|'
        . 'hakrawler|gospider|crawlergo|crawl4ai|crawlee|photon|arachni|acunetix|netsparker|invicti|'
        . 'openvas|nessus|qualys|w3af|skipfish|commix|xsstrike|dalfox|jaeles|httpx|naabu|subfinder|'
        . 'python-requests|python-urllib|aiohttp|scrapy|go-http-client|colly|libwww-perl|java\\/|okhttp|'
        . 'headlesschrome|phantomjs|zmeu|morfeus|masscan|censys|expanse|internet-measurement/i';

    /** Nama alat bila User-Agent permintaan ini milik scanner/crawler, selain itu null. Loopback dikecualikan (uji lokal). */
    public static function scannerUa(): ?string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if ($remote === '127.0.0.1' || $remote === '::1') {
            return null;
        }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (trim($ua) === '') {
            return 'tanpa-user-agent';
        }
        return preg_match(self::SCANNER_UA, $ua, $m) ? strtolower($m[0]) : null;
    }

    /** Returns true if the request hit a trap (and the caller should hard-exit). */
    public static function inspect(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = strtolower(rawurldecode($path));

        foreach (self::TRAP_PATHS as $trap) {
            if (str_starts_with($path, $trap)) {
                self::block(Ip::client(), 'honeypot_hit', 'Honeypot URL: ' . $path);
                SecurityLog::write('honeypot_hit', ['path' => $path]);
                return true;
            }
        }
        // Obvious probes.
        $probes = ['../', '..%2f', '%2e%2e', 'union+select', 'union%20select', '<script'];
        foreach ($probes as $p) {
            if (str_contains($path, $p)) {
                SecurityLog::write('probe_pattern', ['path' => $path]);
            }
        }
        return false;
    }

    /** Count recent 404s and hard-block IP if scanning. */
    public static function notFound(): void
    {
        $pdo = \VC\Database::security();
        $ip = Ip::bucket();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND ts >= ?');
        $stmt->execute(['404:' . $ip, time() - 600]);
        $c = (int)$stmt->fetchColumn();
        if ($c >= 20) {
            self::block($ip, 'scanner_404_storm', 'Too many 404s in 10min');
        }
        $pdo->prepare('INSERT INTO rate_limit_hits (bucket, ts) VALUES (?, ?)')
            ->execute(['404:' . $ip, time()]);
    }

    /** Escalation ladder: 15m → 1h → 24h → 7d permanent. */
    public static function block(string $ip, string $reason, string $note): void
    {
        // Never block loopback
        if ($ip === '127.0.0.1' || $ip === '0.0.0.0') {
            SecurityLog::write('ip_block_skipped_local', ['ip' => $ip, 'reason' => $reason]);
            return;
        }

        // For private gateway IPs (NAT), block client signature to avoid collateral DoS to the whole LAN
        $target = Ip::isPrivate($ip)
            ? $ip . ':ua_' . substr(md5($_SERVER['HTTP_USER_AGENT'] ?? 'none'), 0, 16)
            : $ip;

        $pdo = \VC\Database::security();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ip_blocks WHERE ip = ?");
        $stmt->execute([$target]);
        $prior = (int)$stmt->fetchColumn();
        $ttl = match (min($prior, 3)) {
            0 => 900,
            1 => 3600,
            2 => 86400,
            default => 604800,
        };
        $pdo->prepare('INSERT INTO ip_blocks (ip, kind, reason, expires_at, created_at) VALUES (?, ?, ?, ?, ?) ' .
            'ON CONFLICT(ip) DO UPDATE SET kind = excluded.kind, reason = excluded.reason, expires_at = excluded.expires_at, created_at = excluded.created_at')
            ->execute([$target, $reason, $note, time() + $ttl, time()]);
        // WAL mode: newly committed rows are visible to readers in other processes immediately.
        SecurityLog::write('ip_blocked', ['ip' => $target, 'reason' => $reason, 'ttl' => $ttl]);
    }

    /** Loader for bootstrap: returns true when the current IP is blocked. */
    public static function isBlocked(): bool
    {
        $pdo = \VC\Database::security();
        $ip = Ip::client();
        $uaTarget = $ip . ':ua_' . substr(md5($_SERVER['HTTP_USER_AGENT'] ?? 'none'), 0, 16);
        $stmt = $pdo->prepare('SELECT 1 FROM ip_blocks WHERE (ip = ? OR ip = ?) AND (expires_at IS NULL OR expires_at > ?)');
        $stmt->execute([$ip, $uaTarget, time()]);
        return (bool)$stmt->fetchColumn();
    }
}
