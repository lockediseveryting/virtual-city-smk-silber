<?php
// RBAC: deny by default; role bundles + granular grants with scope + expiry.
declare(strict_types=1);

namespace VC\Security;

use VC\Database;

final class Permissions
{
    private const ROLE_BUNDLES = [
        'root'            => ['*'],
        'kepala_sekolah'  => ['report.view_all', 'schedule.view_all', 'schedule.import', 'schedule.rollback', 'user.view', 'user.suspend', 'user.approve_registration', 'role.assign', 'audit.view', 'ai.view', 'ai.orchestrate', 'map.edit', 'world.edit_details', 'chat.moderate', 'mission.manage'],
        'kepala_program'  => ['report.view_program', 'schedule.view_all', 'schedule.import', 'schedule.rollback', 'user.view', 'user.approve_registration', 'class.manage', 'subject.manage', 'ai.view', 'ai.orchestrate', 'map.edit', 'mission.manage'],
        'guru'            => ['material.create', 'task.create', 'task.grade', 'task.view_submissions', 'quiz.create', 'event.create',
                              'room.create', 'schedule.view_all', 'schedule.import', 'schedule.rollback', 'report.view_class', 'ai.view', 'chat.moderate'],
        'wali_kelas'      => ['user.approve_registration', 'user.view', 'report.view_class', 'schedule.view_all', 'schedule.import', 'schedule.rollback'],
        'pembina'         => ['org.manage', 'org.approve_member', 'org.post', 'event.create'],
        'pengurus'        => ['org.post', 'org.approve_member'],
        'murid'           => [],
        'murid_akses'     => [],
        'tamu'            => [], // tamu gabung-cepat (kode room + nama): hanya /dunia, tanpa izin RBAC
    ];

    /** Cache keyed by user id so one process can evaluate several users (e.g. the bot). */
    private static array $cache = [];

    /** Does the current (session) user hold $perm? Checked server-side everywhere. */
    public static function can(string $perm, string $scopeType = 'global', string $scopeId = '0'): bool
    {
        $user = Auth::user();
        return $user !== null && self::canFor($user, $perm, $scopeType, $scopeId);
    }

    /**
     * Permission check for an explicit user record (web, API and Telegram all share this).
     * This is the single source of truth for role bundles + granular grants.
     */
    public static function canFor(array $user, string $perm, string $scopeType = 'global', string $scopeId = '0'): bool
    {
        if (!isset($user['id'], $user['role'])) {
            return false;
        }
        $all = self::allFor((int)$user['id'], (string)$user['role']);
        if (in_array('*', $all['perms'], true)) {
            return true;
        }
        if (in_array($perm, $all['perms'], true)) {
            return true;
        }
        foreach ($all['grants'] as $g) {
            $grantPerm = $g['permission'] ?? $g['perm'] ?? '';
            if ($grantPerm !== $perm) {
                continue;
            }
            if ($g['scope_type'] === 'global' || ($g['scope_type'] === $scopeType && (string)$g['scope_id'] === $scopeId)) {
                if ($g['expires_at'] === null || (int)$g['expires_at'] > time()) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function isSuperuser(array $user): bool
    {
        $all = self::allFor((int)($user['id'] ?? 0), (string)($user['role'] ?? ''));
        return in_array('*', $all['perms'], true);
    }

    /** Enforce or die. Attach to every endpoint. */
    public static function require(string $perm, string $scopeType = 'global', string $scopeId = '0'): void
    {
        if (!self::can($perm, $scopeType, $scopeId)) {
            SecurityLog::write('perm_denied', ['perm' => $perm, 'scope' => "$scopeType:$scopeId"]);
            http_response_code(403);
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
            if (str_starts_with($path, '/api/') || str_starts_with($path, '/tg/')) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Anda tidak memiliki izin untuk aksi ini.']);
                exit;
            }
            // HTML pages must never show raw JSON — render a friendly 403 screen.
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Akses Ditolak</title></head>'
                . '<body style="font-family:system-ui,sans-serif;background:#090d16;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">'
                . '<div style="max-width:420px;text-align:center;padding:24px">'
                . '<div style="font-size:56px">🔒</div>'
                . '<h1 style="font-size:1.4rem;margin:12px 0 6px">Akses Ditolak</h1>'
                . '<p style="color:#94a3b8;line-height:1.7">Akun kamu tidak punya izin <code>' . htmlspecialchars($perm, ENT_QUOTES) . '</code> untuk membuka halaman ini. Hubungi guru/administrator sekolah.</p>'
                . '<a href="/" style="display:inline-block;margin-top:16px;background:#22d3ee;color:#04121a;font-weight:700;padding:10px 20px;border-radius:10px;text-decoration:none">← Kembali ke Beranda</a>'
                . '</div></body></html>';
            exit;
        }
    }

    private static function allFor(int $userId, string $role): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $perms = self::ROLE_BUNDLES[$role] ?? [];
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT permission, scope_type, scope_id, expires_at FROM user_permissions
            WHERE user_id = ? AND (expires_at IS NULL OR expires_at > ?)');
        $stmt->execute([$userId, time()]);
        $grants = $stmt->fetchAll();
        self::$cache[$userId] = ['perms' => $perms, 'grants' => $grants];
        return self::$cache[$userId];
    }

    /** Invalidate after any role/permission change. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
