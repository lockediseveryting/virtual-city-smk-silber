<?php
// Foto profil pengguna. Server tidak punya GD/Imagick → gambar sudah diperkecil di browser
// (canvas → JPEG 320 px); di sini divalidasi ketat (tipe dari ISI berkas, dimensi, ukuran)
// lalu disimpan dengan nama acak di public/assets/avatars (.htaccess: hanya p_<hex>.jpg|png|webp,
// tanpa eksekusi skrip, nosniff). Path disimpan di user_profiles.photo.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;

final class ProfilePhoto
{
    private const MAX_BYTES = 600 * 1024;
    private const MAX_DIM = 1024;
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    private static function dir(): string
    {
        return VC_PUBLIC . '/assets/avatars';
    }

    private static function ensureColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo = Database::get();
        $cols = array_column($pdo->query('PRAGMA table_info(user_profiles)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('photo', $cols, true)) {
            $pdo->exec('ALTER TABLE user_profiles ADD COLUMN photo TEXT NOT NULL DEFAULT ""');
        }
        $done = true;
    }

    /** URL foto (/assets/avatars/p_….jpg) atau '' bila belum ada. */
    public static function url(int $userId): string
    {
        self::ensureColumn();
        $st = Database::get()->prepare('SELECT photo FROM user_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        $p = (string)($st->fetchColumn() ?: '');
        return preg_match('#^/assets/avatars/p_[a-f0-9]{16}\.(jpg|png|webp)$#', $p) ? $p : '';
    }

    /** @return array{status:string,message?:string,url?:string} */
    public static function save(int $userId, string $bytes): array
    {
        self::ensureColumn();
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return ['status' => 'error', 'message' => 'Foto maks. 600 KB (otomatis diperkecil di HP/laptop).'];
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !isset(self::TYPES[$info[2]])) {
            return ['status' => 'error', 'message' => 'Format foto harus JPG, PNG, atau WebP.'];
        }
        [$w, $h] = $info;
        if ($w < 32 || $h < 32 || $w > self::MAX_DIM || $h > self::MAX_DIM) {
            return ['status' => 'error', 'message' => 'Ukuran foto tidak valid.'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['status' => 'error', 'message' => 'Berkas bukan gambar.'];
        }
        $ext = self::TYPES[$info[2]];
        $name = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = self::dir();
        if (!is_dir($dir) || file_put_contents($dir . '/' . $name, $bytes) === false) {
            return ['status' => 'error', 'message' => 'Gagal menyimpan foto. Coba lagi.'];
        }
        @chmod($dir . '/' . $name, 0644);
        $old = self::url($userId);
        $pdo = Database::get();
        $pdo->prepare('INSERT OR IGNORE INTO user_profiles (user_id, qr_token) VALUES (?, ?)')->execute([$userId, bin2hex(random_bytes(16))]);
        $pdo->prepare('UPDATE user_profiles SET photo = ?, updated_at = datetime("now") WHERE user_id = ?')
            ->execute(['/assets/avatars/' . $name, $userId]);
        self::unlinkUrl($old);
        AuditLog::log('profile_photo_set', ['file' => $name], $userId);
        return ['status' => 'ok', 'url' => '/assets/avatars/' . $name];
    }

    public static function remove(int $userId): array
    {
        $old = self::url($userId);
        if ($old === '') {
            return ['status' => 'ok'];
        }
        Database::get()->prepare('UPDATE user_profiles SET photo = "" WHERE user_id = ?')->execute([$userId]);
        self::unlinkUrl($old);
        AuditLog::log('profile_photo_remove', [], $userId);
        return ['status' => 'ok'];
    }

    private static function unlinkUrl(string $url): void
    {
        if ($url !== '' && preg_match('#^/assets/avatars/(p_[a-f0-9]{16}\.(jpg|png|webp))$#', $url, $m)) {
            @unlink(self::dir() . '/' . $m[1]);
        }
    }
}
