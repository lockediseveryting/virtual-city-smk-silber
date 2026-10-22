<?php
// AvatarStyle: kustomisasi karakter (format v2) — satu sumber tampilan untuk dashboard,
// Dunia Pixel (pixel-avatar.js) dan Dunia 3D (world3d.js). Nama selalu nama akun (users.full_name).
// Disimpan di user_profiles.avatar_config (JSON) + user_profiles.bio. Referensi pakaian:
// /var/www/assets/custom-assets (seragam TJKT, batik, jas lab, kemeja kerja, koki, dll.).
declare(strict_types=1);

namespace VC\Domain;

use PDO;
use VC\Database;
use VC\Security\AuditLog;

final class AvatarStyle
{
    // v8.1: + gaya & kostum untuk Event Kostum Anime (Domain/CostumeEvent.php)
    public const HAIR_STYLES = ['short', 'spiky', 'messy', 'curly', 'long', 'ponytail', 'bun', 'hijab', 'bob', 'twintail', 'braids', 'topknot'];
    public const OUTFITS = ['tjkt', 'putih_abu', 'pramuka', 'olahraga', 'hoodie', 'almamater', 'batik', 'jas_lab', 'koki', 'kerja', 'casual',
        'maid', 'dress', 'sailor', 'gakuran', 'armor', 'kimono', 'robe', 'tunic'];
    public const ACCESSORIES = ['backpack', 'glasses', 'cap', 'lanyard', 'headphones', 'tie', 'chef_hat', 'watch', 'sword', 'witch_hat', 'ribbon'];
    public const EMOTES = ['wave', 'point', 'clap', 'cheer', 'sit', 'love', 'laugh', 'think', 'ok', 'cool',
        'dance', 'salute', 'bow', 'sad', 'angry', 'sleep', 'shock', 'hype'];
    public const BACKGROUNDS = ['bengkel', 'perpustakaan', 'lab', 'studio', 'dapur', 'kelas', 'taman', 'kota'];
    public const MOTIFS = ['checker', 'triangle', 'halfsplit', 'flame', 'butterfly', 'muzzle', 'boar', 'scarf', 'wings', 'maidcap', 'cape', 'prosthetic', 'horns', 'rose'];
    public const MAX_ACC = 3;
    public const BIO_MAX = 160;

    private const STAFF = ['guru', 'wali_kelas', 'kepala_program', 'kepala_sekolah', 'root', 'pembina'];

    /** Tampilan awal sesuai peran (murid: seragam TJKT; guru: batik + lanyard). */
    public static function defaults(string $role): array
    {
        $staff = in_array($role, self::STAFF, true);
        return [
            'v' => 2,
            'skin' => '#e2ad84',
            'hairStyle' => 'short',
            'hair' => '#2b1d16',
            'outfit' => $staff ? 'batik' : 'tjkt',
            'top' => $staff ? '#7a4a28' : '#1d4ed8',
            'bottom' => $staff ? '#2c2c34' : '#1e3a8a',
            'shoes' => '#111827',
            'acc' => $staff ? ['lanyard'] : ['backpack'],
            'accColor' => '#2f8f4e',
            'emotes' => ['wave', 'point', 'clap', 'cheer', 'sit'],
            'background' => $staff ? 'perpustakaan' : 'bengkel',
            'story' => '',
        ];
    }

    /** Validasi ketat: hanya nilai dari daftar putih & warna hex yang disimpan. */
    public static function sanitize(array $in, string $role): array
    {
        $out = self::defaults($role);
        $hex = static fn($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1;
        foreach (['skin', 'hair', 'top', 'bottom', 'shoes', 'accColor'] as $k) {
            if (isset($in[$k]) && $hex($in[$k])) $out[$k] = strtolower($in[$k]);
        }
        if (isset($in['hairStyle']) && in_array($in['hairStyle'], self::HAIR_STYLES, true)) $out['hairStyle'] = $in['hairStyle'];
        if (isset($in['outfit']) && in_array($in['outfit'], self::OUTFITS, true)) $out['outfit'] = $in['outfit'];
        if (isset($in['background']) && in_array($in['background'], self::BACKGROUNDS, true)) $out['background'] = $in['background'];
        if (isset($in['acc']) && is_array($in['acc'])) {
            $acc = array_values(array_unique(array_filter($in['acc'], fn($a) => is_string($a) && in_array($a, self::ACCESSORIES, true))));
            $out['acc'] = array_slice($acc, 0, self::MAX_ACC);
        }
        if (isset($in['emotes']) && is_array($in['emotes'])) {
            $em = array_values(array_unique(array_filter($in['emotes'], fn($e) => is_string($e) && in_array($e, self::EMOTES, true))));
            if (count($em) >= 1) $out['emotes'] = array_slice($em, 0, 8);
        }
        if (isset($in['story']) && is_string($in['story']) && preg_match('/^[a-z0-9-]{0,40}$/', $in['story'])) $out['story'] = $in['story'];
        // ciri khas kostum event anime (pola haori, syal, topeng, jubah…) — daftar putih
        $out['motif'] = (isset($in['motif']) && in_array($in['motif'], self::MOTIFS, true)) ? $in['motif'] : '';
        $out['costume'] = (isset($in['costume']) && is_string($in['costume']) && preg_match('/^[a-z0-9_-]{1,48}$/', $in['costume'])) ? $in['costume'] : ''; // kostum event anime
        return $out;
    }

    /** Konfigurasi + bio milik user (default bila belum pernah diatur / format lama). */
    public static function forUser(int $userId, string $role): array
    {
        $st = Database::get()->prepare('SELECT avatar_config, bio FROM user_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $cfg = json_decode((string)($row['avatar_config'] ?? ''), true);
        $config = (is_array($cfg) && (int)($cfg['v'] ?? 0) === 2) ? self::sanitize($cfg, $role) : self::defaults($role);
        return ['config' => $config, 'bio' => (string)($row['bio'] ?? ''), 'custom' => is_array($cfg) && (int)($cfg['v'] ?? 0) === 2];
    }

    public static function save(int $userId, string $role, array $in, ?string $bio): array
    {
        $config = self::sanitize($in, $role);
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $pdo = Database::get();
        $bioClean = $bio === null ? null : mb_substr(trim(strip_tags($bio)), 0, self::BIO_MAX);
        $upd = $pdo->prepare('UPDATE user_profiles SET avatar_config = ?, bio = COALESCE(?, bio), updated_at = datetime("now") WHERE user_id = ?');
        $upd->execute([$json, $bioClean, $userId]);
        if ($upd->rowCount() === 0) {
            $pdo->prepare('INSERT INTO user_profiles (user_id, qr_token, avatar_config, bio, updated_at) VALUES (?, ?, ?, ?, datetime("now"))')
                ->execute([$userId, bin2hex(random_bytes(16)), $json, $bioClean ?? '']);
        }
        $pdo->prepare('UPDATE active_players SET avatar_config = ? WHERE user_id = ?')->execute([$json, $userId]);
        AuditLog::log('avatar_customize', ['outfit' => $config['outfit'], 'acc' => $config['acc']], $userId);
        return ['status' => 'ok', 'config' => $config, 'bio' => $bioClean ?? self::forUser($userId, $role)['bio']];
    }
}
