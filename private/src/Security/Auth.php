<?php
// Auth: Argon2id hashing, anti-enumeration dummy verify, password policy, sessions.
declare(strict_types=1);

namespace VC\Security;

use VC\Database;

final class Auth
{
    // Argon2id tuned for small VPS; rehash opportunistically on login.
    private const HASH_OPTS = [
        'memory_cost' => 131072, // 128 MB
        'time_cost'   => 3,
        'threads'     => 2,
    ];

    // Dummy hash so unknown usernames take the same time as wrong passwords.
    private const DUMMY_HASH = '$argon2id$v=19$m=131072,t=3,p=2$c29tZXNhbHRzb21lc2FsdA$b8k1G4J8UkwA9u9Vpu1U1C/Dv9N3iuVwzYq8hEczXfM';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on';
        $devInsecure = \vc_env('DEV_INSECURE_COOKIE', '0') === '1';

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '86400');

        if ($isHttps && !$devInsecure) {
            ini_set('session.cookie_secure', '1');
            session_name('__Host-vcid');
        } else {
            ini_set('session.cookie_secure', '0');
            session_name('vcid');
        }
        session_start();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Verify against real user, or burn the same CPU for unknown users. */
    public static function verifyLogin(string $username, string $password): ?array
    {
        $pdo = Database::get();
        $normalized = strtolower(trim($username));
        $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = ? OR nis = ?) AND deleted_at IS NULL ORDER BY (CASE WHEN status = "active" THEN 0 ELSE 1 END), id DESC');
        $stmt->execute([$normalized, $normalized]);
        $candidates = $stmt->fetchAll();
        if (!$candidates && in_array($normalized, ['root', 'admin', 'administrator', 'root_silber'], true)) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE role = "root" AND deleted_at IS NULL ORDER BY id ASC LIMIT 1');
            $stmt->execute();
            $candidates = $stmt->fetchAll();
        }
        foreach ($candidates as $user) {
            $hash = $user['password_hash'] ?? self::DUMMY_HASH;
            if (password_verify($password, $hash)) {
                if (password_needs_rehash($hash, PASSWORD_ARGON2ID, self::HASH_OPTS)) {
                    $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $upd->execute([password_hash($password, PASSWORD_ARGON2ID, self::HASH_OPTS), $user['id']]);
                }
                return $user;
            }
        }
        password_verify($password, self::DUMMY_HASH);
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::HASH_OPTS);
    }

    /** Password policy: min length per role + common/breached/username checks. */
    public static function passwordErrors(string $password, string $username = '', string $role = 'murid'): array
    {
        $errors = [];
        $min = ['root' => 14, 'guru' => 12, 'kepala_sekolah' => 12, 'kepala_program' => 12][$role] ?? 8;
        if (strlen($password) < $min) {
            $errors[] = "Password minimal $min karakter.";
        }
        if (str_contains(strtolower($password), strtolower($username)) && $username !== '') {
            $errors[] = 'Password tidak boleh memuat username.';
        }
        $commonFile = \VC_PRIVATE . '/config/common_passwords.txt';
        if (is_file($commonFile)) {
            $needle = strtolower($password);
            $fh = fopen($commonFile, 'rb');
            if ($fh) {
                while (($line = fgets($fh)) !== false) {
                    if (trim(strtolower($line)) === $needle) {
                        $errors[] = 'Password terlalu umum / mudah ditebak. Pilih yang lebih unik.';
                        break;
                    }
                }
                fclose($fh);
            }
        }
        return $errors;
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND status = "active"');
        $stmt->execute([$_SESSION['user_id']]);
        $cached = $stmt->fetch() ?: null;
        return $cached;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Surprise-note: roles allowed to be checked inline; full RBAC in Permissions.php. */
    public static function isRoot(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'root';
    }

    public static function createWsToken(?array $user = null, int $serverId = 1): ?string
    {
        $u = $user ?: self::user();
        if ($u === null) {
            return null;
        }
        $secret = \vc_env('APP_KEY', 'virtual-city-default-key-silber-2026');
        $programColors = [
            'TKJ'  => '#0284c7',
            'TJKT' => '#0284c7',
            'RPL'  => '#10b981',
            'DKV'  => '#ec4899',
            'AKL'  => '#f59e0b',
            'BD'   => '#8b5cf6',
        ];
        $color = '#0284c7';
        if (!empty($u['class_id'])) {
            try {
                $stmt = Database::get()->prepare('SELECT p.code FROM classes c JOIN programs p ON c.program_id = p.id WHERE c.id = ?');
                $stmt->execute([(int)$u['class_id']]);
                $code = strtoupper((string)$stmt->fetchColumn());
                $color = $programColors[$code] ?? '#0284c7';
            } catch (\Throwable $e) {}
        }
        $payload = base64_encode(json_encode([
            'id' => (int)$u['id'],
            'username' => (string)$u['username'],
            'full_name' => (string)$u['full_name'],
            'role' => (string)$u['role'],
            'program_color' => $color,
            'server_id' => max(1, $serverId),
            'exp' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $payload, $secret);
        return $payload . '.' . $sig;
    }
}
