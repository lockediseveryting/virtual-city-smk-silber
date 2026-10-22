<?php
// Sliding-window rate limiter backed by security.sqlite (cheap, early rejection).
declare(strict_types=1);

namespace VC\Security;

final class RateLimiter
{
    /** Kept in app.env-editable settings later; sane defaults now. */
    private const PRESETS = [
        'login_ip'      => [10, 600],
        'login_user'    => [5, 900],
        'register_ip'   => [120, 600],
        'guest_ip'      => [120, 600],  // gabung cepat tamu: satu kelas sering berbagi 1 IP NAT wifi sekolah
        'password_ip'   => [3, 3600],
        'api_user'      => [120, 60],
        'chat_user'     => [8, 4],
        'upload_user'   => [10, 300],
        'ws_ip'         => [20, 60],
        'tg_user'       => [20, 60],
        'report_user'   => [5, 3600],  // lapor bug / aduan ke ROOT
        'default_ip'    => [240, 60],
    ];

    /**
     * Check + record a hit. Returns milliseconds to wait (0 = allowed).
     */
    public static function hit(string $preset, string $key): int
    {        if (PHP_SAPI === 'cli' && $preset === 'default_ip') {
            return 0;
        }

        // Rate limiting is best-effort: if the security DB is locked/busy we must
        // fail OPEN instead of throwing a fatal 500 for every visitor.
        try {
        [$max, $window] = self::PRESETS[$preset] ?? [120, 60];
        if ($key === '127.0.0.1' || Ip::isPrivate($key)) {
            if ($preset === 'login_ip') {
                $max = max($max, 60);
            } elseif ($preset === 'register_ip') {
                $max = max($max, 300);
            }
        }
        $bucket = $preset . ':' . $key;
        $pdo = \VC\Database::security();
        $now = time();
        $since = $now - $window;
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND ts >= ?');
        $stmt->execute([$bucket, $since]);
        $count = (int)$stmt->fetchColumn();
        if ($count >= $max) {
            // Escalation: repeated breaches raise the effective window x4, capped at 24h.
            $penalty = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND ts >= ?");
            $penalty->execute([$bucket, $now - 86400]);
            $breaches = (int)$penalty->fetchColumn();
            $extended = min($window * (4 ** min($breaches, 4)), 86400);
            self::logBlocked($preset, $key, $count, $extended);
            $pdo->commit();
            return $extended * 1000;
        }
        $ins = $pdo->prepare('INSERT INTO rate_limit_hits (bucket, ts) VALUES (?, ?)');
        $ins->execute([$bucket, $since * 0 + $now]);
        $pdo->commit();
        // Lazy GC ~1% of calls to keep the table tiny.
        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM rate_limit_hits WHERE ts < ?')->execute([$now - 90000]);
        }
        return 0;
        } catch (\Throwable $e) {
            @error_log('[RateLimiter] fail-open: ' . $e->getMessage());
            return 0;
        }
    }

    /** Count failures in the window without recording a hit (for decision logic). */
    public static function recent(string $preset, string $key, int $window): int
    {
        $stmt = \VC\Database::security()->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND ts >= ?');
        $stmt->execute([$preset . ':' . $key, time() - $window]);
        return (int)$stmt->fetchColumn();
    }

    public static function clear(string $preset, string $key): void
    {
        \VC\Database::security()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')
            ->execute([$preset . ':' . $key]);
    }

    private static function logBlocked(string $preset, string $key, int $count, int $penaltySec): void
    {
        $pdo = \VC\Database::security();
        $pdo->prepare('INSERT INTO login_events (ip, username, ok, ts, note) VALUES (?, ?, 0, ?, ?)')
            ->execute([Ip::bucket(), $preset, time(), "rate_limited count=$count penalty={$penaltySec}s"]);
        SecurityLog::write('rate_limited', ['preset' => $preset, 'key' => $key, 'penalty' => $penaltySec]);
    }
}
