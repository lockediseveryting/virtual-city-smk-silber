<?php
// PixelDashboard: "panggung" kota pixel-art (foto referensi siang/malam) + karakter pemain
// berdiri di depan minimarket + HUD pemain + menu papan kayu. Dipakai beranda (/) dan /admin.
declare(strict_types=1);

namespace VC\View;

use VC\Database;
use VC\Domain\Gameplay;

final class PixelDashboard
{
    public const CSS = '<link rel="stylesheet" href="/assets/css/pixel-dashboard.css?v=8.2.2">';
    // Urutan penting: musim → adegan latar (dash-scene) → panggung & karakter (pixel-dashboard).
    public const JS = '<script src="/assets/js/pixel-avatar.js?v=8.1.2" defer></script><script src="/assets/js/pixel-season.js?v=8.0.0" defer></script>'
        . '<script src="/assets/js/dash-scene.js?v=8.0.2" defer></script><script src="/assets/js/pixel-dashboard.js?v=8.1.2" defer></script><script src="/assets/js/pixel-dashboard-plus.js?v=7.7.0" defer></script><script src="/assets/js/qr.js?v=7.6.1" defer></script><script src="/assets/js/share.js?v=7.6.1" defer></script><script src="/assets/js/party.js?v=8.2.3" defer></script><script src="/assets/js/gacha.js?v=7.7.0" defer></script><script src="/assets/js/daily.js?v=7.9.5" defer></script>';

    /** Tombol cepat di bawah menu utama (2 baris × 4, sama untuk murid, guru, admin & root). */
    public const EXTRAS = [
        ['js:vcGacha.open()', '🎰', 'Gacha'],
        ['js:vcParty.open()', '🎮', 'Mabar'],
        ['js:vcDaily.open()', '📅', 'Harian'],
        ['/toko', '🛒', 'Toko'],
        ['js:vcEmote.toggle()', '😄', 'Emote'],
        ['/profil', '🪪', 'Profil'],
        ['js:vcHowToPlay()', '❓', 'Cara Main'],
        ['js:vcMoreToggle()', '⋯', 'Lainnya'],
    ];
    /** Dilipat di "⋯ Lainnya" agar papan menu tetap bersih. */
    public const MORE = [
        ['js:vcReportBug()', '🐞', 'Lapor'],
        ['js:vcOpenWaModal()', '📢', 'Saluran WA'],
        ['/unduh', '📲', 'Unduh'],
        ['/team', '👥', 'Team'],
        ['/author', '📖', 'Author'],
    ];

    private const ROLE_LABEL = [
        'root' => 'Admin ROOT', 'kepala_sekolah' => 'Kepala Sekolah', 'kepala_program' => 'Kepala Program',
        'wali_kelas' => 'Wali Kelas', 'guru' => 'Guru', 'pembina' => 'Pembina', 'pengurus' => 'Pengurus',
        'murid' => 'Siswa', 'murid_akses' => 'Siswa', 'tamu' => 'Tamu',
    ];

    /**
     * @param array $menu  daftar [href, ikon, label, keterangan, varian('', 'gold', 'dark', 'red')]
     * @param array $icons tombol ikon kanan atas: [href|js:fungsi(), ikon, label]
     */
    public static function render(array $user, array $menu, array $icons, string $title, bool $scrollHint = false): void
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $name = (string)($user['full_name'] ?: $user['username']);
        // Nama sapaan: kata pertama, lewati sapaan hormat (Pak/Bu ...) agar "Bu Sari" → "Sari".
        $words = preg_split('/\s+/', trim($name)) ?: [$name];
        while (count($words) > 1 && in_array(strtolower(rtrim($words[0], '.')), ['pak', 'bu', 'ibu', 'bapak', 'kak', 'mr', 'mrs', 'ms'], true)) {
            array_shift($words);
        }
        $first = (string)($words[0] ?: $name);
        $role = self::ROLE_LABEL[$user['role'] ?? ''] ?? 'Pemain';

        $level = 1; $coins = 0; $pct = 0;
        try {
            $p = Gameplay::getProgress((int)$user['id']);
            $level = max(1, (int)$p['level']);
            $coins = (int)$p['coins'];
            $into = Gameplay::xpIntoLevel((int)$p['total_xp'], $level);
            $left = Gameplay::xpToNextLevel((int)$p['total_xp'], $level);
            $pct = ($into + $left) > 0 ? (int)round(100 * $into / ($into + $left)) : 100;
        } catch (\Throwable $ex) {
            // statistik tidak boleh mematahkan dashboard
        }
        $className = '';
        if ((int)($user['class_id'] ?? 0) > 0) {
            try {
                $st = Database::get()->prepare('SELECT name FROM classes WHERE id = ?');
                $st->execute([(int)$user['class_id']]);
                $className = (string)($st->fetchColumn() ?: '');
            } catch (\Throwable $ex) {
                $className = '';
            }
        }

        // Karakter kustom (v2) dari server → satu sumber untuk dashboard, Dunia Pixel & 3D.
        $av = \VC\Domain\AvatarStyle::forUser((int)$user['id'], (string)($user['role'] ?? 'murid'));
        $avJson = json_encode(['config' => $av['config'], 'bio' => $av['bio'], 'name' => $name, 'role' => $role, 'canSave' => ($user['role'] ?? '') !== 'tamu'],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $dashJson = json_encode(\VC\Domain\DashScene::clientConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        echo '<script nonce="' . \VC\Security\Headers::nonce() . '">window.__AVATAR = ' . $avJson . ';window.__DASH = ' . $dashJson . ';</script>';
        echo '<div class="pxd" id="pxd" data-name="' . $e($first) . '" data-fullname="' . $e($name) . '" data-class="' . $e($className) . '">';

        // ---- Panggung: foto kota + efek lampu + karakter ----
        echo '<div class="pxd-stage" id="pxdStage" aria-hidden="false">';
        echo '<div class="pxd-fx pxd-fx--shop" aria-hidden="true"></div>';
        echo '<div class="pxd-fx pxd-fx--sign" aria-hidden="true"></div>';
        echo '<div class="pxd-fx pxd-fx--bakery" aria-hidden="true"></div>';
        echo '<div class="pxd-stars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>';
        echo '<div class="pxd-char" id="pxdChar">';
        echo '<span class="pxd-emo" id="pxdEmo" aria-hidden="true"></span>';
        echo '<div class="pxd-bubble" id="pxdBubble">Halo, ' . $e($first) . '! Mau ke mana hari ini?</div>';
        echo '<span class="pxd-char__tag">' . $e($first) . '</span>';
        echo '<button type="button" class="pxd-char__btn px-plain" id="pxdCharBtn" aria-label="Kustomisasi karakter">'
            . '<canvas id="pxdSprite" width="22" height="42"></canvas></button>';
        echo '<span class="pxd-char__shadow" aria-hidden="true"></span>';
        echo '</div>';
        echo '</div>';

        // ---- HUD pemain ----
        echo '<header class="pxd-hud">';
        echo '<div class="pxd-plate">';
        $photo = \VC\Domain\ProfilePhoto::url((int)$user['id']);
        if ($photo !== '') {
            // Foto profil (unggah di /profil) menggantikan wajah pixel; kanvas tetap ada untuk skrip.
            echo '<a href="/profil" class="pxd-plate__photo" title="Profil"><img src="' . $e($photo) . '" alt="" width="52" height="52"></a><canvas class="pxd-plate__face" id="pxdFace" width="22" height="14" aria-hidden="true" hidden></canvas>';
        } else {
            echo '<canvas class="pxd-plate__face" id="pxdFace" width="22" height="14" aria-hidden="true"></canvas>';
        }
        echo '<div class="pxd-plate__info"><b class="pxd-plate__name">' . $e($name) . '</b>';
        echo '<span class="pxd-plate__role">' . $e($role) . ($className !== '' ? ' · ' . $e($className) : '') . '</span>';
        echo '<div class="pxd-plate__stats"><span class="pxd-lv">LV ' . $level . '</span>'
            . '<span class="pxd-xp" role="progressbar" aria-label="XP menuju level berikutnya" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><i style="width:' . $pct . '%"></i></span>'
            . '<span class="pxd-coin" title="Koin">🪙 <span data-stat="coins">' . $coins . '</span></span></div>';
        echo '</div></div>';
        echo '<div class="pxd-icons">';
        echo '<span class="pxd-clock" id="pxdClock" aria-live="off"></span>';
        foreach ($icons as [$href, $icon, $label]) {
            if (str_starts_with($href, 'js:')) {
                echo '<button type="button" class="pxd-icon px-plain" onclick="' . $e(substr($href, 3)) . '" title="' . $e($label) . '" aria-label="' . $e($label) . '">' . $icon . '</button>';
            } else {
                echo '<a class="pxd-icon" href="' . $e($href) . '" title="' . $e($label) . '" aria-label="' . $e($label) . '">' . $icon . '</a>';
            }
        }
        echo '</div></header>';

        // ---- Menu papan kayu ----
        // Banyak tombol (dashboard admin) → papan 2 kolom simetris agar tidak melebihi tinggi layar laptop.
        echo '<nav class="pxd-menu' . (count($menu) > 6 ? ' pxd-menu--many' : '') . '" aria-label="' . $e($title) . '">';
        echo '<div class="pxd-menu__title"><span>' . $e($title) . '</span></div>';
        echo '<div class="pxd-menu__grid">';
        foreach ($menu as $m) {
            [$href, $icon, $label, $desc] = $m;
            $variant = (string)($m[4] ?? '');
            echo '<a class="px-btn pxd-btn' . ($variant !== '' ? ' px-btn--' . $variant : '') . '" href="' . $e($href) . '">'
                . '<span class="pxd-btn__icon" aria-hidden="true">' . $icon . '</span>'
                . '<span class="pxd-btn__txt"><b>' . $e($label) . '</b><small>' . $e($desc) . '</small></span></a>';
        }
        $extra = static function (array $list, string $cls = '') use ($e): void {
            foreach ($list as [$href, $icon, $label]) {
                $data = $label === 'Gacha' ? ' data-gacha-tile' : ($label === 'Lainnya' ? ' aria-expanded="false" aria-controls="pxdMore" data-more-btn' : '');
                if (str_starts_with($href, 'js:')) {
                    echo '<button type="button" class="px-plain pxd-extra' . $cls . '" onclick="' . $e(substr($href, 3)) . '" title="' . $e($label) . '" aria-label="' . $e($label) . '"' . $data . '><span aria-hidden="true">' . $icon . '</span>' . $e($label) . '</button>';
                } else {
                    echo '<a class="pxd-extra' . $cls . '" href="' . $e($href) . '" title="' . $e($label) . '" aria-label="' . $e($label) . '"><span aria-hidden="true">' . $icon . '</span>' . $e($label) . '</a>';
                }
            }
        };
        echo '</div><div class="pxd-extras">';
        $extra(self::EXTRAS);
        echo '</div><div class="pxd-more" id="pxdMore" hidden>';
        $extra(self::MORE, ' pxd-extra--sm');
        echo '</div></nav>';
        // Panel emote dashboard (18 emote; pixel-dashboard-plus.js) — karakter beranimasi + teman mabar ikut melihat.
        echo '<div class="pxd-emotes" id="pxdEmotes" hidden role="dialog" aria-label="Emote"></div>';

        if ($scrollHint) {
            echo '<a class="pxd-scroll" href="#pxdBelow">▼ Panel lengkap di bawah</a>';
        }

        // ---- Penyunting karakter (isi dibangun pixel-dashboard.js) ----
        echo '<div class="pxd-pick" id="pxdPick" hidden role="dialog" aria-modal="true" aria-labelledby="pxdPickTitle">';
        echo '<div class="pxd-pick__box px-panel pxd-cust"><div class="pxd-pick__head"><h2 id="pxdPickTitle">🎨 Kustomisasi Karakter</h2>'
            . '<button type="button" class="px-btn px-btn--dark pxd-pick__close" data-close aria-label="Tutup">✕</button></div>';
        echo '<div class="pxd-cust__body" id="pxdCustBody"></div></div></div>';

        // ---- Pop up Gabung Saluran WhatsApp Resmi ----
        echo '<div class="pxd-pick pxd-wa-modal" id="pxdWaModal" hidden role="dialog" aria-modal="true" aria-labelledby="pxdWaTitle">';
        echo '<div class="pxd-pick__box px-panel pxd-wa-box">';
        echo '<div class="pxd-pick__head">'
            . '<div class="pxd-wa-head-badge"><span class="pxd-wa-pill">📢 RESMI</span> <span class="pxd-wa-subhead">SMK SILBER</span></div>'
            . '<button type="button" class="px-btn px-btn--dark pxd-pick__close" onclick="vcCloseWaModal()" aria-label="Tutup">✕</button>'
            . '</div>';
        echo '<div class="pxd-wa-body">';
        echo '<div class="pxd-wa-hero">'
            . '<div class="pxd-wa-logos">'
            . '<img src="/assets/img/logo-silber.jpg" alt="Logo SMK Silber" class="pxd-wa-logo">'
            . '<span class="pxd-wa-join-icon" aria-hidden="true">🤝</span>'
            . '<div class="pxd-wa-icon-box" aria-hidden="true">'
            . '<svg width="30" height="30" viewBox="0 0 24 24" fill="#ffffff"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>'
            . '</div>'
            . '</div>'
            . '<h2 id="pxdWaTitle" class="pxd-wa-title">Gabung Saluran WhatsApp!</h2>'
            . '<p class="pxd-wa-desc">Dapatkan informasi penting, pengumuman agenda sekolah, jadwal pelajaran, event seru, dan pembaruan <b>Virtual City SMK Silber</b> langsung di WhatsApp kamu.</p>'
            . '</div>';
        echo '<div class="pxd-wa-perks">'
            . '<div class="pxd-wa-perk"><span aria-hidden="true">🔔</span><span>Pengumuman resmi agenda &amp; kegiatan sekolah</span></div>'
            . '<div class="pxd-wa-perk"><span aria-hidden="true">🎮</span><span>Update server kelas, misi &amp; event dunia game</span></div>'
            . '<div class="pxd-wa-perk"><span aria-hidden="true">⚡</span><span>Gratis, cepat &amp; langsung dari pihak sekolah</span></div>'
            . '</div>';
        echo '<div class="pxd-wa-actions">'
            . '<a href="https://whatsapp.com/channel/0029Vb8lWuqHFxOwf7TPHr1t" target="_blank" rel="noopener noreferrer" class="px-btn pxd-btn-wa" onclick="vcOnWaJoined()">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>'
            . '<span>Gabung Saluran WhatsApp</span>'
            . '</a>'
            . '<button type="button" class="px-btn px-btn--dark" onclick="vcCloseWaModal()">'
            . 'Nanti Saja'
            . '</button>'
            . '</div>';
        echo '<div class="pxd-wa-foot">'
            . '<label class="pxd-wa-check">'
            . '<input type="checkbox" id="pxdWaDontShow">'
            . '<span>Jangan tampilkan lagi hari ini</span>'
            . '</label>'
            . '</div>';
        echo '</div>';
        echo '</div></div>';

        echo '</div>';
    }
}
