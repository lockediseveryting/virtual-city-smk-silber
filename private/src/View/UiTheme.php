<?php
// Tema tombol & panel GLOBAL (semua pengguna, semua halaman) — dipilih PEMILIK (coffelatte314) di /media.
// Setiap tema = nilai variabel CSS pixel-theme (--px-wood*, --px-acc* tombol utama, --px-dk* tombol sekunder,
// --px-ink* panel, --px-gold aksen). "auto" = mengikuti musim / hari raya menurut tanggal (WIB).
// Disisipkan oleh PixelTheme::headTags() sebagai <style> kecil sehingga berlaku seketika tanpa ubah CSS lain.
declare(strict_types=1);

namespace VC\View;

use VC\Security\Owner;

final class UiTheme
{
    // [nama, ikon, wood, wood-hi, wood-lo, wood-dk, wood-2, gold, acc, acc-hi, acc-lo, dk, dk-hi, dk-lo, ink, ink-2, ink-3]
    public const THEMES = [
        'kayu'      => ['Kayu Klasik', '🪵', '#c27a3c', '#f0b878', '#7a4520', '#4a2a12', '#6b4423', '#ffcf4a', '#e0a032', '#ffe08a', '#9a5f12', '#5b3a22', '#8a5a2b', '#3a2414', '#22172b', '#2d1f38', '#170f1e'],
        'sakura'    => ['Sakura (Musim Semi)', '🌸', '#c2577e', '#f4a3c0', '#7d2a4a', '#4a1830', '#8e3a5c', '#ffd1e3', '#e86f9a', '#ffc2d8', '#9c2f58', '#5e2a44', '#8e4a6a', '#3a1428', '#2b1724', '#3a1f30', '#1c0f18'],
        'panas'     => ['Musim Panas', '☀️', '#d98a1a', '#ffd166', '#8a4f05', '#4f2c02', '#a8650d', '#fff07a', '#16a3a3', '#7ef0e6', '#0b5f5f', '#7a4a10', '#a8722a', '#46290a', '#1d2430', '#26303f', '#121820'],
        'hujan'     => ['Musim Hujan', '🌧️', '#4f6d8a', '#9ab8d6', '#2a3f55', '#18263a', '#3a5570', '#bfe3ff', '#3b82c4', '#a5d2ff', '#1f4f80', '#34455a', '#55708c', '#1c2836', '#18202c', '#212c3b', '#0f151e'],
        'gugur'     => ['Musim Gugur', '🍂', '#b5522a', '#f0956a', '#6e2a10', '#401606', '#8a3c1a', '#ffc46b', '#d97a1f', '#ffc078', '#8a4308', '#5a3020', '#85503a', '#361a10', '#24171a', '#311f22', '#170d10'],
        'salju'     => ['Musim Dingin / Salju', '❄️', '#4f86b8', '#b8dcff', '#24507a', '#132d47', '#3a6a96', '#e8f6ff', '#2f78b0', '#a8d8ff', '#163e60', '#3b4f66', '#61809e', '#1f2c3a', '#162232', '#1f2d40', '#0d1622'],
        'ramadan'   => ['Ramadan', '🌙', '#1f7a5a', '#6fd3a8', '#0e4430', '#06261a', '#185f46', '#ffd76a', '#c9a227', '#ffe9a0', '#7a5f0c', '#21463a', '#3a6e5c', '#0f261e', '#10201c', '#162b25', '#091310'],
        'lebaran'   => ['Idul Fitri / Lebaran', '🕌', '#2e9e6a', '#9ff0c4', '#165c3a', '#0a3320', '#237a52', '#fff3c4', '#c99a22', '#ffe08a', '#6e5208', '#2f5a45', '#4f8a6e', '#173226', '#14241e', '#1b3129', '#0c1612'],
        'natal'     => ['Natal', '🎄', '#b4302f', '#f08a7a', '#6e1414', '#3e0808', '#8a2222', '#ffe08a', '#2f8a4a', '#8fe0a0', '#145a28', '#5a2420', '#8a443c', '#341210', '#1c1a22', '#26222c', '#110f15'],
        'imlek'     => ['Imlek', '🧧', '#c41e1e', '#ff7a6a', '#7a0c0c', '#450404', '#9a1414', '#ffd23f', '#e8b92a', '#fff09a', '#8a6408', '#6a1414', '#9a3a2a', '#3e0a0a', '#22121a', '#2e1820', '#150a0f'],
        'merdeka'   => ['Kemerdekaan (17 Agustus)', '🇮🇩', '#c8102e', '#ff7a8a', '#7a0618', '#45020c', '#9c0c24', '#ffffff', '#4a4a58', '#a8a8b8', '#22222c', '#5a1a24', '#8a3a46', '#330a12', '#1c1a22', '#27232d', '#121016'],
        'tahunbaru' => ['Tahun Baru', '🎆', '#3a3a5a', '#8a8ac0', '#1e1e36', '#0e0e1e', '#2c2c48', '#ffd700', '#d4a017', '#ffe680', '#7a5a08', '#2a2a40', '#4a4a6a', '#14141f', '#12121c', '#1b1b28', '#0a0a10'],
        'anomali'   => ['Malam Anomali', '👻', '#5b2a86', '#a873d6', '#33134f', '#1a0829', '#46206a', '#ff6a2a', '#e0552a', '#ffa070', '#8a2a0c', '#2a1a36', '#4a3260', '#140a1c', '#140d1a', '#1d1426', '#0a060e'],
        'laut'      => ['Laut & Pantai', '🌊', '#1f8aa0', '#7fe0f0', '#0e4f5e', '#062a33', '#18707f', '#ffe9a8', '#f0a84a', '#ffd79a', '#9a5a14', '#1f4a55', '#3f7380', '#0f262c', '#0f1f26', '#152a33', '#081217'],
        'hutan'     => ['Hutan Hijau', '🌳', '#4f8a3a', '#a5d88a', '#2a5220', '#142b0f', '#3e6e2e', '#e8f08a', '#8fbf3a', '#d8f08a', '#4a6e14', '#3a4a2a', '#5e7348', '#1c2614', '#151c12', '#1d2719', '#0c110a'],
        'neon'      => ['Neon Cyber', '🌃', '#7a2a9a', '#e07aff', '#43105a', '#220630', '#5e1e78', '#3ff0ff', '#e0309a', '#ff8ad0', '#8a0e56', '#1e2a4a', '#3a4c7a', '#0e1426', '#0e0a1c', '#161029', '#07050f'],
    ];

    public static function setting(): string
    {
        try {
            $st = \VC\Database::get()->prepare('SELECT value FROM settings WHERE key = "ui_theme"');
            $st->execute();
            $v = json_decode((string)$st->fetchColumn(), true);
            $k = is_array($v) ? (string)($v['theme'] ?? 'kayu') : 'kayu';
            return ($k === 'auto' || isset(self::THEMES[$k])) ? $k : 'kayu';
        } catch (\Throwable $e) {
            return 'kayu';
        }
    }

    /** Tema otomatis menurut tanggal sekolah (perkiraan hari raya 2026–2030, sama dengan pixel-season.js). */
    public static function autoTheme(?string $ymd = null): string
    {
        $d = $ymd ?? \VC\Domain\Schedule::nowLocal('Y-m-d');
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        $md = sprintf('%02d-%02d', $m, $day);
        $ramadan = ['2026' => ['02-18', '03-19'], '2027' => ['02-08', '03-09'], '2028' => ['01-28', '02-26'], '2029' => ['01-16', '02-14'], '2030' => ['01-06', '02-04']];
        $imlek = ['2026' => '02-17', '2027' => '02-06', '2028' => '01-26', '2029' => '02-13', '2030' => '02-03'];
        if (isset($ramadan[(string)$y])) {
            [$a, $b] = $ramadan[(string)$y];
            if ($md >= $a && $md <= $b) {
                return 'ramadan';
            }
            $after = date('m-d', strtotime($y . '-' . $b . ' +1 day'));
            $end = date('m-d', strtotime($y . '-' . $b . ' +7 day'));
            if ($md >= $after && $md <= $end) {
                return 'lebaran';
            }
        }
        if (isset($imlek[(string)$y]) && abs(strtotime($d) - strtotime($y . '-' . $imlek[(string)$y])) <= 3 * 86400) {
            return 'imlek';
        }
        if ($md <= '01-07') {
            return 'tahunbaru';
        }
        if ($md >= '12-15') {
            return 'natal';
        }
        if ($md >= '08-10' && $md <= '08-20') {
            return 'merdeka';
        }
        if ($md >= '10-25' && $md <= '10-31') {
            return 'anomali';
        }
        return match (true) {
            $m <= 2 => 'salju',
            $m <= 5 => 'sakura',
            $m <= 8 => 'panas',
            $m <= 10 => 'gugur',
            default => 'hujan',
        };
    }

    public static function active(): string
    {
        $k = self::setting();
        return $k === 'auto' ? self::autoTheme() : $k;
    }

    /** <style> variabel tema aktif (kosong untuk tema bawaan "kayu"). */
    public static function style(): string
    {
        $k = self::active();
        if ($k === 'kayu') {
            return '';
        }
        return '<style id="vcUiTheme">' . self::vars($k) . '</style>';
    }

    public static function vars(string $k, string $sel = ':root'): string
    {
        $t = self::THEMES[$k] ?? self::THEMES['kayu'];
        $n = ['--px-wood', '--px-wood-hi', '--px-wood-lo', '--px-wood-dk', '--px-wood-2', '--px-gold', '--px-acc', '--px-acc-hi', '--px-acc-lo',
            '--px-dk', '--px-dk-hi', '--px-dk-lo', '--px-ink', '--px-ink-2', '--px-ink-3'];
        $css = '';
        foreach ($n as $i => $var) {
            $css .= $var . ':' . $t[$i + 2] . ';';
        }
        return $sel . '{' . $css . '--c-primary:' . $t[2] . ';--c-primary-2:' . $t[7] . ';--c-gold:' . $t[7] . ';--c-neon:' . $t[7] . ';--c-border:' . $t[5]
            . ';--c-bg:' . $t[16] . ';--c-bg-2:' . $t[14] . ';--c-surface:' . $t[14] . ';--c-surface-2:' . $t[15] . '}';
    }

    public static function set(?array $user, string $key): array
    {
        if (!Owner::is($user)) {
            return ['status' => 'error', 'message' => 'Hanya pemilik (coffelatte314) yang bisa mengganti tema.'];
        }
        if ($key !== 'auto' && !isset(self::THEMES[$key])) {
            return ['status' => 'error', 'message' => 'Tema tidak dikenal.'];
        }
        \VC\Database::get()->prepare('INSERT INTO settings (key, value, updated_at) VALUES ("ui_theme", ?, datetime("now"))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')->execute([json_encode(['theme' => $key])]);
        \VC\Security\AuditLog::log('ui_theme_set', ['theme' => $key], (int)$user['id']);
        $name = $key === 'auto' ? 'Otomatis ikut musim (sekarang: ' . self::THEMES[self::autoTheme()][0] . ')' : self::THEMES[$key][0];
        return ['status' => 'ok', 'message' => '🎨 Tema semua pengguna: ' . $name . '.', 'theme' => $key];
    }
}
