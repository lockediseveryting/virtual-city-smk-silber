<?php
// Automated test suite for Phase 5 (UI/UX design system, PWA, i18n, and app shell).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\I18n;
use VC\Security\AuditLog;
use VC\Security\Pwa;

echo "=== MEMULAI TEST SUITE PHASE 5 (UI/UX & PWA) ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "✅ PASS: {$desc}\n";
    } else {
        $fail++;
        echo "❌ FAIL: {$desc}" . ($detail ? " ({$detail})" : '') . "\n";
    }
}

// ---------------------------------------------------------------- Design system
$cssPath = VC_PUBLIC . '/assets/css/app.css';
assertTest('Design system stylesheet exists and is non-empty', is_file($cssPath) && filesize($cssPath) > 4000);
$css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';

assertTest('Design tokens (colors, spacing, radius, shadows, motion) are defined',
    str_contains($css, '--c-primary') && str_contains($css, '--sp-4') && str_contains($css, '--r-lg')
    && str_contains($css, '--sh-2') && str_contains($css, '--m-base'));
assertTest('Light theme is defined', str_contains($css, '[data-theme="light"]'));
assertTest('High-contrast theme is defined', str_contains($css, '[data-theme="hc"]'));
assertTest('Program accent colors are defined',
    str_contains($css, '--c-prog-tkj') && str_contains($css, '--c-prog-dkv'));

$components = ['.card', '.btn', '.toast', '.sheet', '.skeleton', '.empty-state', '.xpbar', '.tabbar', '.hud', '.badge'];
$missing = array_values(array_filter($components, fn($c) => !str_contains($css, $c . ' ') && !str_contains($css, $c . '{')));
assertTest('All core UI components are styled', $missing === [], 'missing: ' . implode(', ', $missing));

assertTest('Mobile bottom tab bar + floating action button are styled',
    str_contains($css, '.tabbar__item') && str_contains($css, '.fab'));
assertTest('Safe-area insets + dynamic viewport units are used',
    str_contains($css, 'safe-area-inset-bottom') && str_contains($css, '100dvh'));
assertTest('Minimum 44px touch target token is defined', str_contains($css, '--tap: 44px'));
assertTest('Accessibility: focus-visible + reduced-motion + WCAG-friendly overrides',
    str_contains($css, ':focus-visible') && str_contains($css, 'prefers-reduced-motion') && str_contains($css, 'data-motion="reduced"'));
assertTest('Text scaling (accessibility) is supported', str_contains($css, '[data-textsize='));
assertTest('No external CDN references in the stylesheet', !preg_match('#https?://#', $css));

// ---------------------------------------------------------------- Runtime JS
foreach ([
    'UI runtime' => ['/assets/js/ui.js', ['toast(', 'openSheet(', 'confetti(', 'levelUp(', 'cycleTheme(', 'queueAction(']],
    'Audio manager' => ['/assets/js/audio.js', ['attachGestureUnlock', 'setMaster', 'setSfx', 'setMusic', 'playAreaMusic']],
    'PWA manager' => ['/assets/js/pwa.js', ['beforeinstallprompt', 'serviceWorker.register', 'SKIP_WAITING']],
] as $label => [$rel, $needles]) {
    $path = VC_PUBLIC . $rel;
    $src = is_file($path) ? (string)file_get_contents($path) : '';
    assertTest("{$label} exists and is non-empty", $src !== '');
    $miss = array_values(array_filter($needles, fn($n) => !str_contains($src, $n)));
    assertTest("{$label} exposes the required helpers", $miss === [], 'missing: ' . implode(', ', $miss));
}
assertTest('Autoplay policy is respected (audio unlocks on first gesture)',
    str_contains((string)@file_get_contents(VC_PUBLIC . '/assets/js/audio.js'), 'pointerdown'));

// ---------------------------------------------------------------- i18n
$langPath = VC_PRIVATE . '/lang/id.json';
$langRaw = is_file($langPath) ? (string)file_get_contents($langPath) : '';
$lang = json_decode($langRaw, true);
assertTest('Central Indonesian i18n file exists and is valid JSON', is_array($lang));
assertTest('i18n covers app/nav/hud/auth/pwa/settings sections',
    is_array($lang) && !array_diff(['app', 'nav', 'hud', 'auth', 'pwa', 'settings'], array_keys($lang)));
assertTest('I18n::t translates a known key', I18n::t('app.name') === 'Virtual City SMK Silber');
assertTest('I18n::t returns the key for an unknown string (never blank)', I18n::t('does.not.exist') === 'does.not.exist');
assertTest('i18n is exportable to JS as safe JSON', str_contains(I18n::jsonForJs(), 'Virtual City'));

// ---------------------------------------------------------------- PWA manifest
$manPath = VC_PUBLIC . '/manifest.json';
$man = json_decode((string)@file_get_contents($manPath), true);
assertTest('Web App Manifest is valid JSON', is_array($man));
$required = ['name', 'short_name', 'id', 'start_url', 'scope', 'display', 'orientation', 'theme_color', 'background_color', 'lang'];
$missReq = array_values(array_filter($required, fn($k) => !isset($man[$k])));
assertTest('Manifest has all required fields', $missReq === [], 'missing: ' . implode(', ', $missReq));
assertTest('Manifest display is standalone and lang is id',
    ($man['display'] ?? '') === 'standalone' && ($man['lang'] ?? '') === 'id');

$sizes = array_map(fn($i) => $i['sizes'] ?? '', $man['icons'] ?? []);
$purposes = array_map(fn($i) => $i['purpose'] ?? '', $man['icons'] ?? []);
assertTest('Manifest declares 192 and 512 icons', in_array('192x192', $sizes, true) && in_array('512x512', $sizes, true));
assertTest('Manifest declares a maskable icon', in_array('maskable', $purposes, true));
assertTest('Manifest declares app shortcuts (>= 3)', count($man['shortcuts'] ?? []) >= 3);
assertTest('Manifest declares screenshots for a rich install UI', count($man['screenshots'] ?? []) >= 1);

foreach (['/assets/icons/icon-192.png', '/assets/icons/icon-512.png', '/assets/icons/icon-maskable-512.png', '/assets/icons/icon.svg'] as $icon) {
    $p = VC_PUBLIC . $icon;
    assertTest("Icon exists: {$icon}", is_file($p) && filesize($p) > 100);
}

// ---------------------------------------------------------------- Service worker
$sw = (string)@file_get_contents(VC_PUBLIC . '/service-worker.js');
assertTest('Service worker exists', $sw !== '');
assertTest('Service worker uses a versioned cache name',
    preg_match("/CACHE_VERSION\s*=\s*'v[0-9]/", $sw) === 1);
assertTest('Service worker precaches the app shell + design system',
    str_contains($sw, "'/assets/css/app.css'") && str_contains($sw, "'/assets/js/ui.js'") && str_contains($sw, "'/'"));
assertTest('Service worker precaches core 3D assets',
    str_contains($sw, 'three.min.js') && str_contains($sw, 'world3d.js') && str_contains($sw, 'campus.json'));
assertTest('Service worker has an offline fallback page',
    str_contains($sw, 'offline.html') && is_file(VC_PUBLIC . '/offline.html'));
assertTest('Service worker never caches authenticated API responses unsafely',
    str_contains($sw, "pathname.startsWith('/api/')"));
assertTest('Service worker keeps last-known schedule offline',
    str_contains($sw, '/api/schedule/today'));
assertTest('Service worker supports background sync queue',
    str_contains($sw, "addEventListener('sync'") && str_contains($sw, 'FLUSH_QUEUE'));
assertTest('Service worker only skipWaiting after user consent',
    str_contains($sw, 'SKIP_WAITING') && !str_contains($sw, "install', (event) => {\n  self.skipWaiting"));

// ---------------------------------------------------------------- Shell / head
$head = Pwa::headTags();
assertTest('Head tags include the design system stylesheet', str_contains($head, '/assets/css/app.css'));
assertTest('Head tags include the web app manifest link', str_contains($head, 'rel="manifest"'));
assertTest('Head tags include iOS install metadata (apple-touch-icon)',
    str_contains($head, 'apple-touch-icon') && str_contains($head, 'apple-mobile-web-app-capable'));
assertTest('Head tags load the runtime + PWA scripts', str_contains($head, 'ui.js') && str_contains($head, 'audio.js') && str_contains($head, 'pwa.js'));

// ---------------------------------------------------------------- Authenticated page render
$pdo = \VC\Database::get();
$student = $pdo->query("SELECT id FROM users WHERE role = 'murid' AND status = 'active' AND deleted_at IS NULL AND class_id IS NOT NULL LIMIT 1")->fetchColumn();

if ($student) {
    $_SESSION = ['user_id' => (int)$student, 'csrf' => str_repeat('a', 64)];
    ob_start();
    \VC\Controllers\HomeController::render();
    $home = (string)ob_get_clean();

    // Beranda = dashboard game pixel-art (panggung kota + karakter + menu papan kayu), layar penuh tanpa tab bar.
    assertTest('Authenticated home renders the pixel game dashboard (stage, character, menu)',
        str_contains($home, 'id="pxd"') && str_contains($home, 'id="pxdSprite"') && str_contains($home, 'class="pxd-menu"'));
    $tabs = ['Dunia', 'Event', 'Tugas', 'Chat', 'Profil'];
    $missTabs = array_values(array_filter($tabs, fn($t) => !str_contains($home, '>' . $t . '<')));
    assertTest('Tab bar exposes the 5 required sections', $missTabs === [], 'missing: ' . implode(', ', $missTabs));
    $menu = ['Dunia 3D', 'Dunia Pixel', 'Jadwal', 'Gabung Kelas', 'Event', 'Chat'];
    $missMenu = array_values(array_filter($menu, fn($m) => !str_contains($home, '<b>' . $m . '</b>')));
    assertTest('Dashboard menu exposes Dunia 3D, Pixel, Jadwal, Gabung Kelas, Event, Chat', $missMenu === [], 'missing: ' . implode(', ', $missMenu));
    assertTest('Home renders the "Jadwal Hari Ini" card', str_contains($home, 'Jadwal Hari Ini'));
    assertTest('Home injects the i18n dictionary for the client', str_contains($home, 'window.__I18N'));
    assertTest('Home exposes a CSRF token meta tag', str_contains($home, 'name="csrf"'));
    assertTest('Home renders without PHP error output', !preg_match('/Warning:|Notice:|Fatal error|Deprecated:/', $home));
} else {
    assertTest('A student account exists for the render test', false, 'no active murid with class_id found');
}

// ---------------------------------------------------------------- Guest page render
$_SESSION = [];
ob_start();
\VC\Controllers\HomeController::render();
$guest = (string)ob_get_clean();
assertTest('Guest home hides the tab bar and shows the login card',
    !str_contains($guest, 'class="tabbar"') && str_contains($guest, 'Masuk Akun'));

// ---------------------------------------------------------------- Placeholder routes
ob_start();
\VC\Controllers\PlaceholderController::render('events');
$ph = (string)ob_get_clean();
assertTest('Unreleased sections render an honest in-progress page (no broken nav, no fake data)',
    str_contains($ph, 'Fase 6') && str_contains($ph, 'app-shell'));

// ---------------------------------------------------------------- 3D world HUD
$_SESSION = [];
ob_start();
\VC\Controllers\WorldController::render3DPage();
$world = (string)ob_get_clean();
$hudNeedles = ['hud--top', 'hudXp', 'hudCoins', 'hudFloor', 'hudLocation', 'objectiveCompass', 'data-coin-target', 'touchJoystickZone'];
$missHud = array_values(array_filter($hudNeedles, fn($n) => !str_contains($world, $n)));
assertTest('3D world HUD exposes avatar, level/XP, coins, floor, location, compass, joystick',
    $missHud === [], 'missing: ' . implode(', ', $missHud));
$quick = ['Misi', 'Event', 'Chat', 'Peta', 'Emote'];
$missQuick = array_values(array_filter($quick, fn($q) => !str_contains($world, $q)));
assertTest('3D world HUD has the quick buttons (Misi/Event/Chat/Peta/Emote)', $missQuick === [], 'missing: ' . implode(', ', $missQuick));
assertTest('3D world page loads the shared design system + runtime',
    str_contains($world, '/assets/css/app.css') && str_contains($world, 'world3d.js') && str_contains($world, 'map2d.js'));
assertTest('3D world page renders without PHP error output',
    !preg_match('/Warning:|Notice:|Fatal error|Deprecated:/', $world));

// ---------------------------------------------------------------- Tema pixel-game global
$inj = \VC\View\PixelTheme::inject('<html><head><title>x</title></head><body></body></html>');
assertTest('Tema pixel disisipkan sebelum </head> (CSS + skrip layar penuh)',
    str_contains($inj, 'pixel-theme.css') && str_contains($inj, 'pixel-shell.js') && strpos($inj, 'pixel-shell.js') < strpos($inj, '</head>'));
$shellJs = (string)file_get_contents(VC_PUBLIC . '/assets/js/pixel-shell.js');
assertTest('Overlay layar penuh bisa ditutup & layar penuh hanya lewat ketukan',
    str_contains($shellJs, "data-act=\"close\"") && str_contains($shellJs, 'requestFullscreen') && !preg_match('/window\.stop\(/', $shellJs));
$loginHtml = (function () { $_SESSION = []; ob_start(); \VC\Controllers\AuthController::renderLogin(); return (string)ob_get_clean(); })();
// Latar login bisa diganti pemilik di /media (SiteMedia): bawaan = video + poster; pilihan pemilik = berkas /assets/media/.
$loginBg = \VC\Domain\SiteMedia::publicConfig()['login'];
assertTest('Login: latar (bawaan video + poster, atau pilihan pemilik) + form login tetap utuh',
    ($loginBg === null ? (str_contains($loginHtml, 'login-bg.mp4') && str_contains($loginHtml, 'login-poster.jpg')) : str_contains($loginHtml, $loginBg['url']))
    && str_contains($loginHtml, 'class="px-login__bg"') && str_contains($loginHtml, 'id="formLoginPage"') && str_contains($loginHtml, 'vcLoginSubmit'));

// ---------------------------------------------------------------- Kustomisasi karakter v2
$cfgIn = ['outfit' => 'hoodie', 'hairStyle' => 'curly', 'top' => '#C0392B', 'acc' => ['cap', 'glasses', 'backpack', 'tie', 'evil'], 'emotes' => ['cool', 'hack'], 'background' => 'studio', 'skin' => 'red', 'story' => '<x>'];
$san = \VC\Domain\AvatarStyle::sanitize($cfgIn, 'murid');
assertTest('Karakter: hanya nilai daftar putih disimpan (aksesoris maks. 3, warna hex, emote valid)',
    $san['outfit'] === 'hoodie' && $san['top'] === '#c0392b' && count($san['acc']) === 3 && !in_array('evil', $san['acc'], true)
    && $san['emotes'] === ['cool'] && $san['skin'] === '#e2ad84' && $san['story'] === '' && $san['v'] === 2);
assertTest('Karakter: default guru = batik + lanyard, murid = seragam TJKT + tas',
    \VC\Domain\AvatarStyle::defaults('guru')['outfit'] === 'batik' && \VC\Domain\AvatarStyle::defaults('murid')['outfit'] === 'tjkt');
$avJs = (string)file_get_contents(VC_PUBLIC . '/assets/js/pixel-avatar.js');
$w3 = (string)file_get_contents(VC_PUBLIC . '/assets/js/world3d.js');
assertTest('Karakter sama di dashboard, Pixel & 3D (PixelAvatar.normalize dipakai 3D, bentuk bulat)',
    str_contains($avJs, 'normalize: normalize') && str_contains($w3, 'PA.normalize(rawCfg)') && str_contains($w3, 'SphereGeometry(1.35'));

// ---------------------------------------------------------------- Security regression
assertTest('Audit log hash-chain integrity preserved (0 broken links)', AuditLog::verify() === 0);

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

exit($fail === 0 ? 0 : 1);
