<?php
// Shell: shared page chrome (head, design system, top bar, bottom tab bar, settings).
// Every page must render through Shell so the UI stays consistent and CSP-safe.
declare(strict_types=1);

namespace VC\View;

use VC\I18n;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Footer;
use VC\Security\Headers;
use VC\Security\Pwa;

final class Shell
{
    /** Open the document, head, and app shell. Page content follows. */
    public static function start(array $o = []): void
    {
        $nonce   = Headers::nonce();
        $title   = $o['title'] ?? I18n::t('app.name');
        $user    = array_key_exists('user', $o) ? $o['user'] : Auth::user();
        $active  = (string)($o['active'] ?? '');
        $showNav = (bool)($o['nav'] ?? true);
        $topbar  = (bool)($o['topbar'] ?? true);
        $shell   = (bool)($o['shell'] ?? true);
        $splash  = (bool)($o['splash'] ?? false);
        $bodyCls = (string)($o['bodyClass'] ?? '');

        echo '<!doctype html><html lang="id">';
        echo '<head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="description" content="' . htmlspecialchars((string)($o['description'] ?? I18n::t('app.tagline')), ENT_QUOTES) . '">';
        echo '<meta name="csrf" content="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">';
        // Theme bootstrap runs before paint to avoid a flash of the wrong theme.
        echo '<script nonce="' . $nonce . '">' . self::prefsBootstrap() . '</script>';
        echo '<script nonce="' . $nonce . '">window.__I18N = ' . I18n::jsonForJs() . ';</script>';
        // Pwa::headTags() wires the design system (app.css) + runtime scripts (ui.js, audio.js, pwa.js).
        echo Pwa::headTags();
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>';
        if (!empty($o['head'])) {
            echo (string)$o['head']; // page-specific <style>/<meta>; already escaped by caller
        }
        echo '</head><body class="' . htmlspecialchars($bodyCls, ENT_QUOTES) . '">';
        echo '<a class="skip-link" href="#main">Lewati ke konten utama</a>';

        if ($user !== null) {
            echo self::sidebar($active, $user);
        }
        if ($splash) {
            echo '<div class="splash" aria-hidden="true">';
            echo '<div style="text-align:center">';
            echo '<img class="splash__logo" src="/assets/img/logo-silber.jpg" alt="Logo SMK Silber">';
            echo '<div class="splash__tip">' . htmlspecialchars(I18n::t('app.loading'), ENT_QUOTES) . '</div>';
            echo '</div></div>';
        }

        if ($shell) {
            echo '<main id="main" class="app-shell' . ($showNav && $user !== null ? ' app-shell--nav' : '') . '">';
        } else {
            echo '<main id="main">';
        }
        if ($topbar) {
            echo self::topbar($user);
        }
    }

    /** Close the page: bottom tab bar, quick/settings sheets, and scripts. */
    public static function end(array $o = []): void
    {
        $user    = array_key_exists('user', $o) ? $o['user'] : Auth::user();
        $showNav = (bool)($o['nav'] ?? true);
        $active  = (string)($o['active'] ?? '');
        $scripts = (array)($o['scripts'] ?? []);

        // Single shared footer for every Shell page (no per-page duplication).
        echo Footer::html();
        echo '</main>';
        if ($showNav && $user !== null) {
            echo self::tabbar($active, $user);
            echo self::fab();
        }
        echo self::quickSheet();
        echo self::settingsSheet($user);
        if ($user !== null) {
            echo self::notificationsSheet();
        }
        echo self::mascotCompanionSheet();

        foreach ($scripts as $src) {
            echo '<script src="' . htmlspecialchars((string)$src, ENT_QUOTES) . '"></script>';
        }
        echo '</body></html>';
    }

    /** Inline JS that resets theme to single official cyber dark theme and applies stored motion/text-size before first paint. */
    private static function prefsBootstrap(): string
    {
        return 'try{var r=document.documentElement;r.removeAttribute("data-theme");localStorage.removeItem("vc_theme");'
            . 'var m=localStorage.getItem("vc_motion");if(m)r.setAttribute("data-motion",m);'
            . 'var s=localStorage.getItem("vc_textsize");if(s)r.setAttribute("data-textsize",s);}catch(e){}';
    }

    private static function topbar(?array $user): string
    {
        $fullName = $user !== null ? (string)($user['full_name'] ?: $user['username']) : I18n::t('home.guest');
        $name = htmlspecialchars($fullName, ENT_QUOTES);
        $shortName = htmlspecialchars(explode(' ', trim($fullName))[0], ENT_QUOTES);
        $coins = 0;
        if ($user !== null) {
            try {
                $coins = (int)\VC\Domain\Gameplay::getProgress((int)$user['id'])['coins'];
            } catch (\Throwable $e) {
                $coins = 0; // never let stats break the shell
            }
        }

        $h  = '<header class="topbar">';
        $h .= '<div class="topbar__brand">'
            . '<a href="/" class="topbar__brand-link" title="Beranda Smk Silber Game">'
            . '<img src="/assets/img/logo-silber.jpg" alt="Logo SMK Silber">'
            . '<span class="topbar__title">' . htmlspecialchars(I18n::t('app.short_name'), ENT_QUOTES) . '</span>'
            . '</a>'
            . '</div>';

        $h .= '<div class="topbar__actions">';
        $h .= '<span class="stat stat--topbar" data-coin-target data-stat="coins" title="' . htmlspecialchars(I18n::t('hud.coins'), ENT_QUOTES) . '"><span class="stat__coin">🪙</span><span id="hudCoins">' . $coins . '</span></span>';
        $h .= '<span class="badge badge--neon topbar__user" title="' . $name . '"><span class="topbar__user-name">' . $shortName . '</span></span>';
        $h .= '<button class="btn btn--sm topbar__install-btn hide-mobile-topbar" type="button" onclick="if(window.pwa){pwa.promptInstall();}else{location.href=\'/download?action=install\';}" title="Unduh &amp; Pasang Aplikasi" style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;border-radius:8px;padding:6px 10px;font-size:0.8rem;display:inline-flex;align-items:center;gap:4px">📲<span class="hide-mobile">Unduh App</span></button>';

        if ($user !== null) {
            $h .= '<a href="/telegram" class="btn btn--sm topbar__tele-btn hide-mobile-topbar" title="Tautkan Telegram">🔗<span class="hide-mobile" style="margin-left:4px">Telegram</span></a>';
            if (in_array($user['role'] ?? '', ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'], true)) {
                $h .= '<a href="/admin" class="btn btn--sm topbar__admin-btn" title="Panel Administrasi">🛡️<span class="hide-mobile" style="margin-left:4px">Admin</span></a>';
            }
            $h .= '<button class="icon-btn topbar__icon-btn" type="button" onclick="UI.openSheet(\'vcNotifSheet\');vcLoadNotifs()" aria-label="' . htmlspecialchars(I18n::t('notif.title'), ENT_QUOTES) . '" style="position:relative">🔔'
                . '<span id="notifBadge" class="badge badge--danger" style="position:absolute;top:-6px;right:-6px;display:none">0</span></button>';
        } else {
            $h .= '<a href="/telegram" class="btn btn--sm topbar__tele-btn hide-mobile-topbar" title="Tautkan Telegram (Opsional)">🔗<span class="hide-mobile" style="margin-left:4px">Telegram</span></a>';
            $h .= '<a href="/login?admin=1" class="btn btn--sm topbar__admin-btn" title="Login Admin / Staf">🛡️<span class="hide-mobile" style="margin-left:4px">Admin</span></a>';
        }
        $h .= '<button class="icon-btn topbar__icon-btn" type="button" onclick="UI.openSheet(\'vcSettingsSheet\')" aria-label="' . htmlspecialchars(I18n::t('settings.title'), ENT_QUOTES) . '">⚙️</button>';
        $h .= '</div></header>';
        return $h;
    }

    private static function sidebar(string $active, array $user): string
    {
        $role = $user['role'] ?? 'murid';
        $isStaff = in_array($role, ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'], true);

        $sections = [
            'Utama' => [
                ['/', 'home', '🏠', I18n::t('nav.home')],
                ['/dunia', 'world', '🎮', I18n::t('nav.world')],
                ['/peta', 'map', '🗺️', I18n::t('nav.map')],
            ],
            'Akademik' => [
                ['/jadwal', 'schedule', '📅', I18n::t('nav.schedule')],
                ['/tugas', 'tasks', '📝', I18n::t('nav.tasks')],
                ['/gabung', 'join', '🏫', 'Gabung Kelas'],
            ],
            'Komunitas' => [
                ['/event', 'events', '🎉', I18n::t('nav.events')],
                ['/chat', 'chat', '💬', I18n::t('nav.chat')],
                ['/profil', 'profile', '👤', I18n::t('nav.profile')],
            ],
            'Sistem' => [],
        ];

        if ($isStaff) {
            $sections['Sistem'][] = ['/admin', 'admin', '🛡️', 'Panel Admin'];
        }
        $sections['Sistem'][] = ['/telegram', 'telegram', '🔗', 'Telegram'];
        $sections['Sistem'][] = ['/download', 'download', '📲', 'Unduh Aplikasi'];
        $sections['Sistem'][] = ['/tutorial', 'help', '❓', 'Tutorial & Panduan'];

        $h = '<nav class="sidebar" aria-label="Navigasi utama">';
        $h .= '<div class="sidebar__brand">'
            . '<img src="/assets/img/logo-silber.jpg" width="30" height="30" alt="Logo SMK Silber" style="border-radius:8px;box-shadow:0 0 10px rgba(56,189,248,0.3)">'
            . '<div style="display:flex;flex-direction:column;line-height:1.2">'
            . '<strong style="font-family:var(--font-display);font-size:0.95rem;letter-spacing:0.02em;color:#fff">SMK Silber</strong>'
            . '<span style="font-size:0.68rem;color:#38bdf8;font-weight:700">Smk Silber Game</span>'
            . '</div></div>';

        foreach ($sections as $sectionName => $items) {
            if (empty($items)) continue;
            $h .= '<div class="sidebar__section-title">' . htmlspecialchars($sectionName, ENT_QUOTES) . '</div>';
            foreach ($items as [$href, $key, $icon, $label]) {
                $cls = 'sidebar__link tabbar__item' . ($active === $key ? ' is-active' : '');
                $cur = $active === $key ? ' aria-current="page"' : '';
                $h .= '<a href="' . $href . '" class="' . $cls . '" data-nav-match="' . $href . '"' . $cur . '>';
                $h .= '<span class="tabbar__icon" aria-hidden="true">' . $icon . '</span><span>' . htmlspecialchars($label, ENT_QUOTES) . '</span></a>';
            }
        }
        return $h . '</nav>';
    }

    private static function tabbar(string $active, ?array $user = null): string
    {
        $role = $user['role'] ?? 'murid';
        $isStaff = in_array($role, ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'], true);

        if ($isStaff) {
            $items = [
                ['/', 'home', '🏠', I18n::t('nav.home')],
                ['/dunia', 'world', '🎮', I18n::t('nav.world')],
                ['/admin', 'admin', '🛡️', 'Admin'],
                ['/event', 'events', '🎉', I18n::t('nav.events')],
                ['/chat', 'chat', '💬', I18n::t('nav.chat')],
                ['/profil', 'profile', '👤', I18n::t('nav.profile')],
            ];
        } else {
            $items = [
                ['/', 'home', '🏠', I18n::t('nav.home')],
                ['/dunia', 'world', '🎮', I18n::t('nav.world')],
                ['/tugas', 'tasks', '📝', I18n::t('nav.tasks')],
                ['/event', 'events', '🎉', I18n::t('nav.events')],
                ['/chat', 'chat', '💬', I18n::t('nav.chat')],
                ['/profil', 'profile', '👤', I18n::t('nav.profile')],
            ];
        }

        $h = '<nav class="tabbar" aria-label="Navigasi bawah">';
        foreach ($items as [$href, $key, $icon, $label]) {
            $cls = 'tabbar__item' . ($active === $key ? ' is-active' : '');
            $cur = $active === $key ? ' aria-current="page"' : '';
            $h .= '<a href="' . $href . '" class="' . $cls . '" data-nav-match="' . $href . '"' . $cur . '>';
            $h .= '<span class="tabbar__icon" aria-hidden="true">' . $icon . '</span><span>' . htmlspecialchars($label, ENT_QUOTES) . '</span></a>';
        }
        return $h . '</nav>';
    }

    private static function fab(): string
    {
        return '<button class="fab" type="button" onclick="UI.openSheet(\'vcQuickSheet\')" aria-label="' . htmlspecialchars(I18n::t('home.quick_actions'), ENT_QUOTES) . '">＋</button>';
    }

    private static function quickSheet(): string
    {
        $links = [
            ['/dunia', '🎮', I18n::t('nav.world'), ''],
            ['/chat', '💬', I18n::t('nav.chat'), ''],
            ['/peta', '🗺️', I18n::t('nav.map'), ''],
            ['/jadwal', '📅', I18n::t('nav.schedule'), ''],
            ['/event', '🎉', I18n::t('nav.events'), ''],
            ['/telegram', '🔗', 'Tautkan Telegram', ''],
            ['/bantuan', '❓', 'Petunjuk & Bantuan', ''],
            ['#ai', '🤖', 'Tanya Si Silber', 'data-open-sheet="vcAiMascotSheet" onclick="UI.closeSheet(\'vcQuickSheet\');UI.openSheet(\'vcAiMascotSheet\');return false;"'],
        ];
        $h = '<div class="sheet-backdrop" id="vcQuickSheet" role="dialog" aria-modal="true" aria-hidden="true">';
        $h .= '<div class="sheet"><div class="sheet__grabber"></div>';
        $h .= '<div class="sheet__head"><h3 style="margin:0">' . htmlspecialchars(I18n::t('home.quick_actions'), ENT_QUOTES) . '</h3>';
        $h .= '<button class="icon-btn" type="button" onclick="UI.closeSheet(\'vcQuickSheet\')" aria-label="' . htmlspecialchars(I18n::t('app.close'), ENT_QUOTES) . '">✕</button></div>';
        $h .= '<div class="card-grid card-grid--2">';
        foreach ($links as [$href, $icon, $label, $extra]) {
            $h .= '<a class="card card--interactive" href="' . $href . '" ' . ($extra ?? '') . ' style="text-align:center;text-decoration:none">'
                . '<div style="font-size:2rem">' . $icon . '</div><div style="font-weight:700;margin-top:4px">' . htmlspecialchars($label, ENT_QUOTES) . '</div></a>';
        }
        $h .= '</div></div></div>';
        return $h;
    }

    private static function notificationsSheet(): string
    {
        $h = '<div class="sheet-backdrop" id="vcNotifSheet" role="dialog" aria-modal="true" aria-hidden="true"><div class="sheet">';
        $h .= '<div class="sheet__grabber"></div>';
        $h .= '<div class="sheet__head"><h3 style="margin:0">🔔 ' . htmlspecialchars(I18n::t('notif.title'), ENT_QUOTES) . '</h3>';
        $h .= '<div class="row" style="gap:6px"><button class="btn btn--ghost btn--sm" type="button" onclick="vcMarkAllRead()">Tandai dibaca</button>';
        $h .= '<button class="icon-btn" type="button" onclick="UI.closeSheet(\'vcNotifSheet\')" aria-label="' . htmlspecialchars(I18n::t('app.close'), ENT_QUOTES) . '">✕</button></div></div>';
        $h .= '<div id="notifList"><div class="skeleton skeleton--line"></div><div class="skeleton skeleton--line"></div><div class="skeleton skeleton--line"></div></div>';
        $h .= '</div></div>';

        $nonce = Headers::nonce();
        $h .= '<script nonce="' . $nonce . '">'
            . 'async function vcLoadNotifs(){'
            . 'var box=document.getElementById("notifList");'
            . 'try{var r=await fetch("/api/notifications",{credentials:"same-origin"});var d=await r.json();'
            . 'var items=d.notifications||[];'
            . 'var badge=document.getElementById("notifBadge");'
            . 'if(badge){badge.style.display=d.unread>0?"inline-flex":"none";badge.textContent=d.unread;}'
            . 'if(!items.length){box.innerHTML="<div class=\'empty-state\'><div class=\'empty-state__art\'>🔔</div><p>Belum ada notifikasi.</p></div>";return;}'
            . 'box.innerHTML=items.map(function(n){return "<div class=\'card\' style=\'margin-bottom:8px;"+(n.is_read==0?"border-color:var(--c-primary-2)":"")+"\'><div style=\'font-weight:700\'>"+n.title+" <span class=\'badge\'>"+n.category+"</span></div><div class=\'muted\' style=\'font-size:0.85rem\'>"+(n.body||"")+"</div><div class=\'faint\'>"+n.created_at+"</div></div>";}).join("");'
            . '}catch(e){box.innerHTML="<p class=\'muted\'>Gagal memuat notifikasi.</p>";}}'
            . 'async function vcMarkAllRead(){'
            . 'var csrf=(document.querySelector("meta[name=csrf]")||{}).content||"";'
            . 'await fetch("/api/notifications/read",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF":csrf},body:JSON.stringify({_csrf:csrf,all:true})});'
            . 'vcLoadNotifs();UI.toast("Semua notifikasi ditandai dibaca.","success",2000);}'
            . 'document.addEventListener("DOMContentLoaded",function(){fetch("/api/notifications",{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){var b=document.getElementById("notifBadge");if(b){b.style.display=d.unread>0?"inline-flex":"none";b.textContent=d.unread;}}).catch(function(){});});'
            . '</script>';
        return $h;
    }

    private static function settingsSheet(?array $user): string
    {
        // The install button doubles as a permanent entry point in Settings.
        $h = '<div class="sheet-backdrop" id="vcSettingsSheet" role="dialog" aria-modal="true" aria-hidden="true">';
        $h .= '<div class="sheet"><div class="sheet__grabber"></div>';
        $h .= '<div class="sheet__head"><h3 style="margin:0">' . htmlspecialchars(I18n::t('settings.title'), ENT_QUOTES) . '</h3>';
        $h .= '<button class="icon-btn" type="button" onclick="UI.closeSheet(\'vcSettingsSheet\')" aria-label="' . htmlspecialchars(I18n::t('app.close'), ENT_QUOTES) . '">✕</button></div>';

        $h .= '<div class="section"><h4>' . htmlspecialchars(I18n::t('settings.theme'), ENT_QUOTES) . '</h4>';
        $h .= '<div class="card" style="padding:12px 14px;border:1px solid rgba(255,107,0,0.45);background:rgba(255,107,0,0.08);display:flex;align-items:center;gap:12px;border-radius:12px">'
            . '<span style="font-size:1.6rem">🪵</span>'
            . '<div><strong style="color:#ffcf4a;font-size:0.95rem">Pixel Kayu</strong>'
            . '<div class="faint" style="font-size:0.8rem;margin-top:2px">Tema resmi Virtual City SMK Silber — suasana siang/malam mengikuti jam.</div></div>'
            . '</div></div>';

        $h .= '<div class="section"><h4>' . htmlspecialchars(I18n::t('settings.audio'), ENT_QUOTES) . '</h4>';
        $h .= '<div class="field"><label for="volMaster">' . htmlspecialchars(I18n::t('settings.master_volume'), ENT_QUOTES) . ' <span id="volMasterVal"></span></label><input id="volMaster" type="range" min="0" max="1" step="0.05" oninput="gameAudio.setMaster(this.value);document.getElementById(\'volMasterVal\').textContent=Math.round(this.value*100)+\'%\'"></div>';
        $h .= '<div class="field"><label for="volSfx">' . htmlspecialchars(I18n::t('settings.sfx_volume'), ENT_QUOTES) . '</label><input id="volSfx" type="range" min="0" max="1" step="0.05" oninput="gameAudio.setSfx(this.value)"></div>';
        $h .= '<div class="field"><label for="volMusic">' . htmlspecialchars(I18n::t('settings.music_volume'), ENT_QUOTES) . '</label><input id="volMusic" type="range" min="0" max="1" step="0.05" oninput="gameAudio.setMusic(this.value)"></div>';
        $h .= '<button class="btn btn--secondary btn--sm" type="button" onclick="gameAudio.setMuted(!gameAudio.muted);UI.toast(gameAudio.muted?\'' . htmlspecialchars(I18n::t('settings.mute'), ENT_QUOTES) . '\':\'Audio aktif\')">' . htmlspecialchars(I18n::t('settings.mute'), ENT_QUOTES) . '</button>';
        $h .= '</div>';

        $h .= '<div class="section"><h4>' . htmlspecialchars(I18n::t('settings.accessibility'), ENT_QUOTES) . '</h4><div class="stack">';
        $h .= '<label class="row" style="gap:8px;margin:0"><input type="checkbox" style="min-height:auto;width:auto" onchange="UI.setMotion(this.checked)"> ' . htmlspecialchars(I18n::t('settings.reduce_motion'), ENT_QUOTES) . '</label>';
        $h .= '<div class="chip-row"><button class="btn btn--secondary btn--sm" type="button" onclick="UI.setTextSize(\'\')">A</button><button class="btn btn--secondary btn--sm" type="button" onclick="UI.setTextSize(\'lg\')">A+</button><button class="btn btn--secondary btn--sm" type="button" onclick="UI.setTextSize(\'xl\')">A++</button></div>';
        $h .= '</div></div>';

        $h .= '<div class="section"><h4>' . htmlspecialchars(I18n::t('settings.install_app'), ENT_QUOTES) . '</h4>';
        $h .= '<button class="btn btn--block" type="button" onclick="if(window.pwa){pwa.promptInstall();UI.closeSheet(\'vcSettingsSheet\');}">⬇️ ' . htmlspecialchars(I18n::t('pwa.install'), ENT_QUOTES) . '</button>';
        $h .= '<a href="/download" class="btn btn--secondary btn--block" style="margin-top:8px;text-decoration:none;text-align:center" onclick="UI.closeSheet(\'vcSettingsSheet\')">📲 Pusat Unduh &amp; Launcher Mandiri →</a></div>';

        $h .= '<div class="section"><h4>🔗 Bot Telegram</h4>';
        $h .= '<a href="/telegram" class="btn btn--secondary btn--block" style="text-decoration:none;text-align:center">Hubungkan Telegram (@hackertkjsmksilber_bot) →</a></div>';

        if ($user !== null) {
            $h .= '<button class="btn btn--danger btn--block" type="button" onclick="vcLogout()">' . htmlspecialchars(I18n::t('auth.logout'), ENT_QUOTES) . '</button>';
            $h .= '<script nonce="' . Headers::nonce() . '">function vcLogout(){var t=document.querySelector(\'meta[name="csrf"]\');fetch(\'/api/logout\',{method:\'POST\',headers:{\'X-CSRF\':(t?t.content:\'\')}}).then(function(){location.href=\'/\';});}</script>';
        }
        $h .= '</div></div>';
        return $h;
    }

    /** Floating companion widget & interactive AI chat modal */
    private static function mascotCompanionSheet(): string
    {
        $nonce = Headers::nonce();
        $h = '';
        // 1. Floating trigger button on all pages
        $h .= '<div class="mascot-companion-fab" data-open-sheet="vcAiMascotSheet" onclick="UI.openSheet(\'vcAiMascotSheet\')" title="Tanya Si Silber (AI Companion)">';
        $h .= '<div class="mascot-companion-fab__bubble"><span>🤖</span><span>Tanya Si Silber</span></div>';
        $h .= '<img src="/assets/mascot/silber-mascot.svg" alt="Si Silber" class="mascot-companion-fab__avatar">';
        $h .= '</div>';

        // 2. Interactive Dialog Sheet
        $h .= '<div class="sheet-backdrop" id="vcAiMascotSheet" role="dialog" aria-modal="true" aria-hidden="true">';
        $h .= '<div class="sheet" style="max-height:85vh">';
        $h .= '<div class="sheet__grabber"></div>';
        $h .= '<div class="sheet__head" style="align-items:center">';
        $h .= '<div class="row" style="gap:10px">';
        $h .= '<img src="/assets/mascot/silber-mascot.svg" alt="" style="width:36px;height:36px;border-radius:50%;background:#090d16;border:2px solid var(--c-primary-2);padding:2px">';
        $h .= '<div><h3 style="margin:0;font-size:1.05rem">Si Silber AI Companion</h3><div class="faint" style="font-size:0.75rem;color:var(--c-neon)">● Online & Siap Membantu</div></div>';
        $h .= '</div>';
        $h .= '<button class="icon-btn" type="button" onclick="UI.closeSheet(\'vcAiMascotSheet\')" aria-label="Tutup">✕</button></div>';

        $h .= '<div class="ai-companion-dialog">';
        $h .= '<div class="ai-companion-history" id="aiHistory">';
        $h .= '<div class="ai-msg ai-msg--bot"><div class="ai-msg__content">Halo! Aku <b>Si Silber</b>, maskot & asisten virtualmu di SMK Silber. Ada yang bisa kubantu seputar sekolah 3D, jadwal kelas, tugas, atau teknologi? 🚀</div></div>';
        $h .= '</div>';

        $h .= '<form class="ai-companion-form" id="formAiCompanion" onsubmit="return submitMascotAi(event)">';
        $h .= '<input type="text" id="aiInputPrompt" placeholder="Tanya Si Silber apa saja..." style="flex:1" autocomplete="off" required>';
        $h .= '<button type="submit" id="btnAiSend" class="btn btn--gold btn--sm" style="min-width:70px">Kirim</button>';
        $h .= '</form>';
        $h .= '</div>';

        $h .= '<script nonce="' . $nonce . '">
async function submitMascotAi(e) {
  if (e) e.preventDefault();
  var input = document.getElementById("aiInputPrompt");
  var btn = document.getElementById("btnAiSend");
  var history = document.getElementById("aiHistory");
  var q = (input ? input.value : "").trim();
  if (!q) return false;

  input.value = "";
  btn.disabled = true;

  var userDiv = document.createElement("div");
  userDiv.className = "ai-msg ai-msg--user";
  userDiv.innerHTML = "<div class=\"ai-msg__content\">" + q.replace(/&/g,"&amp;").replace(/</g,"&lt;") + "</div>";
  history.appendChild(userDiv);

  var botDiv = document.createElement("div");
  botDiv.className = "ai-msg ai-msg--bot";
  botDiv.innerHTML = "<div class=\"ai-msg__content\" style=\"opacity:0.7\"><em>Si Silber sedang berpikir... ⚡</em></div>";
  history.appendChild(botDiv);
  history.scrollTop = history.scrollHeight;

  try {
    var csrf = document.querySelector(\'meta[name="csrf"]\');
    var token = csrf ? csrf.content : "";
    var res = await fetch("/api/ai/ask", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": token },
      body: JSON.stringify({ question: q, _csrf: token, context: document.title })
    });
    var d = await res.json();
    var botContent = botDiv.querySelector(".ai-msg__content");
    if (d.status === "ok") {
      botContent.style.opacity = "1";
      botContent.innerHTML = (d.reply || "").replace(/\\n/g, "<br>");
    } else {
      botContent.style.opacity = "1";
      botContent.innerHTML = "⚠️ " + (d.message || "Gagal menghubungi AI.");
    }
  } catch(err) {
    var botContent = botDiv.querySelector(".ai-msg__content");
    botContent.style.opacity = "1";
    botContent.innerHTML = "⚠️ Terjadi gangguan koneksi jaringan.";
  }

  btn.disabled = false;
  history.scrollTop = history.scrollHeight;
  return false;
}
</script>';
        $h .= '</div></div>';
        return $h;
    }
}
