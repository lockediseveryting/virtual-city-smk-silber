<?php
// Latar dashboard (semua pengguna: murid, guru, admin, ROOT). Dikendalikan PEMILIK (coffelatte314).
// settings.dash_scene = { scene: 'auto'|id, season: 'auto'|id-musim, daypart: 'auto'|pagi|siang|sore|malam }
//  - scene 'auto' → pilih dari daftar adegan yang cocok dengan musim berjalan, bergilir tiap hari.
//  - Setiap adegan punya titik pijak karakter (charX, ground) & tinggi karakter (charH) agar karakter
//    berdiri tepat di lantai/jalan gambar itu. Aset: public/assets/img/dash/<id>.jpg (+ .mp4/.webm animasi).
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use VC\Security\Owner;

final class DashScene
{
    /** id => [judul, tipe img|video, w, h, charX, ground, charH, efek, musim-cocok[]] */
    public const SCENES = [
        'kota'         => ['Kota Minimarket (bawaan)', 'img', 1200, 675, 0.342, 0.90, 0.176, 'none',   ['gugur', 'natal', 'tahunbaru', 'merdeka', 'ramadan', 'idulfitri', 'iduladha']],
        'pelabuhan'    => ['Pelabuhan Siang',          'img', 736, 414, 0.50, 0.865, 0.15, 'sea',    ['panas', 'merdeka']],
        'senja-sakura' => ['Senja di Taman Kota',      'img', 736, 552, 0.33, 0.90, 0.19, 'leaves', ['gugur', 'ramadan', 'idulfitri']],
        'bukit-awan'   => ['Bukit Awan Musim Panas',   'img', 1200, 675, 0.57, 0.856, 0.17, 'clouds', ['panas']],
        'fuji'         => ['Gerbang Gunung (Hitam-Putih)', 'img', 1200, 675, 0.27, 0.86, 0.15, 'mist', ['dingin', 'nyepi']],
        'jepang-tinta' => ['Lukisan Tinta Sakura',     'img', 1198, 514, 0.39, 0.95, 0.13, 'petals', ['semi', 'waisak']],
        'toko-sakura'  => ['Toko di Bawah Sakura',     'img', 1000, 620, 0.50, 0.965, 0.22, 'petals', ['semi', 'imlek']],
        'danau-sakura' => ['Danau Sakura (animasi)',   'video', 800, 450, 0.33, 0.985, 0.20, 'none', ['semi', 'paskah']],
        'rel-sakura'   => ['Rel Kereta Sakura (animasi)', 'video', 500, 288, 0.60, 0.96, 0.20, 'none', ['semi']],
        'kota-kabut'   => ['Kota Berkabut (animasi)',  'video', 1280, 620, 0.22, 0.925, 0.13, 'none', ['dingin', 'natal', 'tahunbaru']],
    ];
    public const SEASONS = ['tahunbaru', 'dingin', 'ramadan', 'semi', 'panas', 'merdeka', 'gugur', 'natal', 'idulfitri', 'iduladha', 'paskah', 'nyepi', 'waisak', 'imlek'];
    public const DAYPARTS = ['pagi', 'siang', 'sore', 'malam'];

    public static function state(): array
    {
        $s = null;
        try {
            $st = Database::get()->prepare('SELECT value FROM settings WHERE key = "dash_scene"');
            $st->execute();
            $v = $st->fetchColumn();
            $s = $v !== false ? json_decode((string)$v, true) : null;
        } catch (\Throwable $e) {
        }
        $s = is_array($s) ? $s : [];
        $scene = (string)($s['scene'] ?? 'auto');
        $season = (string)($s['season'] ?? 'auto');
        $day = (string)($s['daypart'] ?? 'auto');
        return [
            // efek musim & waktu (gelap malam, tint, salju…) di adegan custom; bawaan MATI = terang & kontras
            'effects' => (($s['effects'] ?? 'off') === 'on') ? 'on' : 'off',
            'scene' => ($scene === 'auto' || isset(self::SCENES[$scene])) ? $scene : 'auto',
            'season' => ($season === 'auto' || in_array($season, self::SEASONS, true)) ? $season : 'auto',
            'daypart' => ($day === 'auto' || in_array($day, self::DAYPARTS, true)) ? $day : 'auto',
        ];
    }

    /** Data untuk klien (dash-scene.js): pengaturan + katalog adegan (aset yang ada di disk saja). */
    public static function clientConfig(): array
    {
        $list = [];
        foreach (self::SCENES as $id => [$title, $type, $w, $h, $cx, $g, $ch, $fx, $seasons]) {
            $base = '/assets/img/' . ($id === 'kota' ? 'pixel/dashboard-kota' : 'dash/' . $id);
            if ($id !== 'kota' && !is_file(\VC_PUBLIC . $base . '.jpg')) {
                continue;
            }
            $list[$id] = ['title' => $title, 'type' => $type, 'w' => $w, 'h' => $h, 'charX' => $cx, 'ground' => $g, 'charH' => $ch, 'fx' => $fx,
                'seasons' => $seasons, 'img' => $base . '.jpg' . ($id === 'kota' ? '?v=2' : '?v=1'),
                'mp4' => $type === 'video' ? $base . '.mp4?v=1' : null, 'webm' => $type === 'video' ? $base . '.webm?v=1' : null];
        }
        return self::state() + ['scenes' => $list];
    }

    public static function set(?array $user, array $in): array
    {
        if (!Owner::is($user)) {
            return ['status' => 'error', 'message' => 'Hanya pemilik (coffelatte314) yang bisa mengganti latar dashboard.'];
        }
        $cur = self::state();
        if (isset($in['effects'])) {
            $cur['effects'] = $in['effects'] === 'on' ? 'on' : 'off';
        }
        foreach (['scene', 'season', 'daypart'] as $k) {
            if (isset($in[$k])) {
                $cur[$k] = (string)$in[$k];
            }
        }
        if ($cur['scene'] !== 'auto' && !isset(self::SCENES[$cur['scene']])) {
            return ['status' => 'error', 'message' => 'Adegan tidak dikenal.'];
        }
        if ($cur['season'] !== 'auto' && !in_array($cur['season'], self::SEASONS, true)) {
            return ['status' => 'error', 'message' => 'Musim tidak dikenal.'];
        }
        if ($cur['daypart'] !== 'auto' && !in_array($cur['daypart'], self::DAYPARTS, true)) {
            return ['status' => 'error', 'message' => 'Waktu tidak dikenal.'];
        }
        Database::get()->prepare('INSERT INTO settings (key, value, updated_at) VALUES ("dash_scene", ?, datetime("now"))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
            ->execute([json_encode($cur)]);
        AuditLog::log('dash_scene_set', $cur, (int)($user['id'] ?? 0));
        $name = $cur['scene'] === 'auto' ? 'Otomatis ikut musim' : self::SCENES[$cur['scene']][0];
        return ['status' => 'ok', 'message' => 'Latar dashboard semua pengguna: ' . $name . '.', 'state' => self::clientConfig()];
    }
}
