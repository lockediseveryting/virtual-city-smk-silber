<?php
// Pemilik situs: satu akun dengan kendali penuh (keputusan pemilik, 16 Okt 2026):
// moderasi chat, isi halaman Team & "Tentang game", latar login, musik semua halaman.
// Harus akun ROOT yang aktif dengan username persis OWNER_USERNAME.
declare(strict_types=1);

namespace VC\Security;

final class Owner
{
    public const USERNAME = 'coffelatte314';

    /** Username pemilik; bisa diganti lewat OWNER_USERNAME (dipakai versi Docker). */
    public static function username(): string
    {
        return strtolower((string)(\vc_env('OWNER_USERNAME', '') ?: self::USERNAME));
    }

    public static function is(?array $user): bool
    {
        return $user !== null
            && ($user['username'] ?? '') === self::username()
            && ($user['role'] ?? '') === 'root'
            && ($user['status'] ?? 'active') === 'active'
            && empty($user['deleted_at']);
    }

    public static function isId(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $st = \VC\Database::get()->prepare('SELECT username, role, status, deleted_at FROM users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch(\PDO::FETCH_ASSOC);
        return self::is($u ?: null);
    }
}
