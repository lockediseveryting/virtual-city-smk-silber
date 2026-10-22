<?php
// Database bootstrap: PDO SQLite connection with hardened pragmas.
declare(strict_types=1);

namespace VC;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $db = null;

    public static function get(): PDO
    {
        if (self::$db instanceof PDO) {
            return self::$db;
        }
        $path = dirname(__DIR__, 2) . '/private/data/virtual_city.sqlite';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=10000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        self::$db = $pdo;
        return $pdo;
    }

    /** Separate small DB for security bookkeeping (rate limits, bans, login attempts). */
    public static function security(): PDO
    {
        static $sec = null;
        if ($sec instanceof PDO) {
            return $sec;
        }
        $path = dirname(__DIR__, 2) . '/private/data/security.sqlite';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=10000');
        self::ensureSecuritySchema($pdo);
        $sec = $pdo;
        return $pdo;
    }

    private static function ensureSecuritySchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limit_hits (
            bucket TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_rlh ON rate_limit_hits(bucket, ts)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ip_blocks (
            ip TEXT PRIMARY KEY, kind TEXT NOT NULL, reason TEXT, expires_at INTEGER, created_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS login_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, username TEXT, ok INTEGER, ts INTEGER NOT NULL,
            note TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_le ON login_events(ip, ts)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS honeypot_hits (
            ip TEXT, url TEXT, ts INTEGER NOT NULL)');
    }
}
