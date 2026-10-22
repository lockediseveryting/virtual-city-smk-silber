<?php
// Front controller for public/ web root. All requests route through here.
declare(strict_types=1);

require_once dirname(__DIR__) . '/private/src/bootstrap.php';

use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Honeypot;
use VC\Security\Headers;
use VC\Security\Ip;
use VC\Security\RateLimiter;
use VC\Security\SecurityLog;

// ---- Layer 0 security gate (runs before sessions / DB / app bootstrap) ----
if (Honeypot::isBlocked()) {
    http_response_code(429);
    header('Retry-After: 900');
    exit('Terlalu banyak permintaan. Coba lagi nanti.');
}

// Alat pemindai / crawler (katana, nuclei, sqlmap, ffuf, …): tolak + blokir berjenjang. Tanpa UA: tolak saja.
if (($scanTool = Honeypot::scannerUa()) !== null) {
    if ($scanTool !== 'tanpa-user-agent') {
        Honeypot::block(Ip::client(), 'scanner_ua', 'Scanner UA: ' . $scanTool);
    }
    SecurityLog::write('scanner_ua', ['tool' => $scanTool, 'path' => substr((string)($_SERVER['REQUEST_URI'] ?? '/'), 0, 200)]);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Akses ditolak: alat pemindai/crawler terdeteksi.');
}

Headers::send();
Auth::startSession();

// Global cheap limit: per akun bila login (satu IP NAT = seluruh sekolah), selain itu per IP/tanda UA.
$rlUser = Auth::user();
$wait = RateLimiter::hit('default_ip', $rlUser !== null ? 'u' . (int)$rlUser['id'] : Ip::bucket());
if ($wait > 0) {
    http_response_code(429);
    header('Retry-After: ' . (int)ceil($wait / 1000));
    header('Content-Type: application/json');
    exit(json_encode(['status' => 'error', 'message' => 'Terlalu banyak permintaan. Coba lagi nanti.']));
}

// ---- Routing ----
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') {
    $method = 'GET';
}

$routes = [
    'GET'  => [
        '/'                      => 'page_home',
        '/login'                 => 'page_login',
        '/masuk'                 => 'page_login',
        '/health'                => 'health',
        '/daftar'                => 'page_register',
        '/jadwal'                => 'page_schedule',
        '/kumpul'                => 'page_gather',            // jadwal kumpul per server (guru/ROOT)
        '/dunia'                 => 'page_world_3d',
        '/pixel'                 => 'page_world_pixel',
        '/gabung'                => 'page_join_class',   // Agent 1: murid masuk room via kode kelas
        '/peta'                  => 'page_world_2d',
        '/editor-peta'           => 'page_map_editor',
        '/event'                 => 'page_events',
        '/tugas'                 => 'page_tasks',
        '/kelas'                 => 'page_tasks',
        '/kuis'                  => 'page_quiz',
        '/chat'                  => 'page_chat',
        '/obrolan'               => 'page_chat',
        '/profil'                => 'page_profile',
        '/telegram'              => 'page_telegram',
        '/bantuan'               => 'page_help',
        '/petunjuk'              => 'page_help',
        '/tutorial'              => 'page_help',
        '/download'              => 'page_download',
        '/unduh'                 => 'page_download',
        '/team'                  => 'page_about',            // Team pembuat game (author + contributor)
        '/tentang'               => 'page_about',
        '/author'                => 'page_author',           // profil Author (backstory, sosmed, portofolio)
        '/media'                 => 'page_media',            // PEMILIK: latar login & musik semua halaman
        '/sitemap.xml'           => 'seo_sitemap',           // untuk Google Search Console
        '/robots.txt'            => 'seo_robots',
        '/api/app/download'      => 'app_download',
        '/teman'                 => 'page_friends',
        '/admin'                 => 'page_admin',
        '/admin-game'            => 'page_admin_game',   // Agent 1: chat & misi in-game
        '/admin/ai'              => 'page_admin_ai',
        '/misi'                  => 'page_missions',
        '/toko'                  => 'page_shop',
        '/karakter'              => 'page_character_selector',
        '/badge'                 => 'page_badges',
        '/peringkat'             => 'page_leaderboard',
        '/api/csrf'              => 'api_csrf',
        '/api/campus'            => 'api_campus_get',
        '/api/schedule/today'    => 'api_schedule_today',
        '/api/schedule/versions' => 'api_schedule_versions',
        '/api/schedule/template' => 'api_schedule_template',
        '/api/schedule/whereis'  => 'api_schedule_whereis',
        '/api/notifications'     => 'api_notifications',
        '/api/stats'             => 'api_stats',
        '/api/task/submissions'  => 'api_task_submissions',
        '/api/task/file'         => 'api_task_file',
        '/api/gradebook'         => 'api_gradebook',
        '/api/ws/token'          => 'api_ws_token',
        '/api/servers'           => 'api_servers',
        '/api/ai/job'            => 'api_ai_job_get',
        '/api/ai/jobs'           => 'api_ai_jobs',
        '/api/character/presets' => 'character_presets_get',
        '/api/avatar/me'         => 'api_avatar_get',        // kustomisasi karakter v2 (dashboard/pixel/3D)
        '/api/gacha'             => 'api_gacha_get',         // status gacha harian (gratis 1×/24 jam)
        '/api/daily'             => 'api_daily_get',         // misi harian (3 misi per hari)
        '/api/costumes'          => 'api_costumes_get',      // Event Kostum Anime (katalog aktif)
        '/api/inventory'         => 'api_inventory_get',     // inventaris 3D/Pixel (barang, catatan, sketsa, kartu)
        '/api/users/search'      => 'api_users_search',      // cari pengguna (nama) — baca-saja
        '/api/servers/session'   => 'api_server_session',    // sesi kumpul aktif di server (baca-saja)
        '/api/party'             => 'api_party',             // status mabar + undangan (baca-saja)
        '/api/media/config'      => 'api_media_config',      // latar login & musik aktif (publik, baca-saja)
        '/api/party/token'       => 'api_party_token',       // token WS room mabar (CSRF)
        '/api/broadcasts'        => 'api_broadcasts',        // pengumuman pop-up dashboard (?all=1 = konsol admin)
        '/api/chat/messages'     => 'chat_messages_get',
        '/api/missions'          => 'api_missions',          // Agent 1: daftar misi user (tanpa hardcode di client)
        '/api/game/chat'         => 'api_game_chat_get',     // Axios riwayat chat in-game
        '/api/chat/settings'     => 'chat_settings_get',     // read-only publik (dipakai klien + WS relay config)
        '/api/admin/missions/list' => 'api_admin_missions_list',
        '/api/player/profile'    => 'api_player_profile',
        '/api/player/nearby'     => 'api_player_nearby',
        '/api/totp/setup'        => 'api_totp_setup',
        '/api/totp/status'       => 'api_totp_status',
        '/api/push/key'          => 'api_push_key',
    ],
    'POST' => [
        '/api/login'             => 'api_login',
        '/api/login_admin'       => 'api_login_admin',
        '/api/logout'            => 'api_logout',
        '/api/totp/setup'        => 'api_totp_setup',
        '/api/totp/enable'       => 'api_totp_enable',
        '/api/totp/verify'       => 'api_totp_verify',
        '/api/totp/disable'      => 'api_totp_disable',
        '/api/push/subscribe'    => 'api_push_subscribe',
        '/api/push/unsubscribe'  => 'api_push_unsubscribe',
        '/api/register'          => 'api_register',
        '/api/approve'           => 'api_approve',
        '/api/campus/save'       => 'api_campus_save',
        '/api/client-error'      => 'api_client_error',
        '/api/schedule/preview'  => 'api_schedule_preview',
        '/api/schedule/apply'    => 'api_schedule_apply',
        '/api/schedule/rollback' => 'api_schedule_rollback',
        '/api/schedule/slot/save'   => 'api_schedule_slot_save',
        '/api/schedule/slot/delete' => 'api_schedule_slot_delete',
        '/api/quiz/start'        => 'api_quiz_start',
        '/api/quiz/submit'       => 'api_quiz_submit',
        '/api/org/join'          => 'api_org_join',
        '/api/event/attend'      => 'api_event_attend',
        '/api/profile/update'    => 'api_profile_update',
        '/api/friends/request'   => 'api_friends_request',
        '/api/friends/respond'   => 'api_friends_respond',
        '/api/chat/send'         => 'api_chat_send',
        '/api/chat/delete'       => 'api_chat_delete',
        '/api/team/contributors' => 'api_team_contributors',  // ROOT: tambah/hapus contributor halaman Team       // hapus pesan (pemilik / moderator)
        '/api/party/action'      => 'api_party_action',
        '/api/media/upload'      => 'api_media_upload',      // PEMILIK: unggah potongan berkas media
        '/api/media/action'      => 'api_media_action',      // PEMILIK: pakai/ganti/hapus media
        '/api/servers/session/action' => 'api_server_session_action', // arrive | save | delete      // mabar: create|join|leave|invite|kick|go|decline
        '/api/game/chat/send'    => 'api_game_chat_send',    // Agent 1: kirim chat in-game (server-side)
        '/api/game/chat/moderate' => 'api_game_chat_moderate',
        '/api/game/chat_relay'   => 'api_game_chat_relay',   // transport relay WS -> PHP (HMAC, bukan klien)
        '/api/admin/chat/settings'  => 'api_admin_chat_settings',
        '/api/admin/missions/save'  => 'api_admin_mission_save',
        '/api/admin/missions/toggle'=> 'api_admin_mission_toggle',
        '/api/missions/start'    => 'api_missions_start',
        '/api/missions/complete' => 'api_missions_complete',
        '/api/world/visit'         => 'api_world_visit',
        '/api/shop/buy'          => 'api_shop_buy',
        '/api/shop/equip'        => 'api_shop_equip',
        '/api/gacha/spin'        => 'api_gacha_spin',        // putar gacha: gratis / bayar koin
        '/api/inventory'         => 'api_inventory_post',    // simpan catatan buku / sketsa pensil
        '/api/daily'             => 'api_daily_post',        // misi harian: claim | report (emote/kucing/sampah/komputer)
        '/api/profile/photo'     => 'api_profile_photo',     // unggah / hapus foto profil
        '/api/character/select'  => 'api_character_select',
        '/api/character/customize' => 'api_character_customize',
        '/api/avatar/me'           => 'api_avatar_save',
        '/api/report'              => 'api_bug_report',      // aduan / lapor bug → DM ROOT
        '/api/admin/broadcast/save'   => 'api_broadcast_save',
        '/api/admin/broadcast/delete' => 'api_broadcast_delete',
        '/api/character/unlock'  => 'api_character_unlock',
        '/api/task/create'       => 'api_task_create',
        '/api/task/delete'       => 'api_task_delete',
        '/api/task/submit'       => 'api_task_submit',
        '/api/task/submission_delete' => 'api_task_submission_delete',
        '/api/task/grade'        => 'api_task_grade',
        '/api/material/create'   => 'api_material_create',
        '/api/material/delete'   => 'api_material_delete',
        '/api/notifications/read'  => 'api_notifications_read',
        '/api/admin/unban'         => 'api_admin_unban',
        '/api/admin/role_update'   => 'api_admin_role_update',
        '/api/admin/coin_adjust'   => 'api_admin_coin_adjust',
        '/api/admin/staff_create'  => 'api_admin_staff_create',
        '/api/admin/user_delete'   => 'api_admin_user_delete',
        '/api/admin/user_resetpass'=> 'api_admin_user_resetpass',
        '/api/admin/class_create'  => 'api_admin_class_create',
        '/api/admin/class_batch'   => 'api_admin_class_batch',
        '/api/admin/class_delete'  => 'api_admin_class_delete',
        '/api/multiplayer/sync'    => 'api_multiplayer_sync',
        '/api/servers/create'      => 'api_servers_create',
        '/api/servers/join'        => 'api_servers_join',
        '/api/gabung'              => 'api_guest_join',      // gabung cepat tamu: kode room + nama (publik)
        '/api/servers/update'      => 'api_servers_update',   // ROOT/Staff: update server
        '/api/servers/delete'      => 'api_servers_delete',   // ROOT/Staff: hapus server dari dashboard admin
        '/api/servers/admin_list'  => 'api_servers_admin_list', // ROOT: daftar semua server (termasuk nonaktif)
        '/api/events/create'       => 'api_events_create',    // ROOT/Staff: tambah event sekolah
        '/api/events/delete'       => 'api_events_delete',    // ROOT/Staff: hapus event sekolah
        '/api/player/interact'     => 'api_player_interact',
        '/api/ai/ask'              => 'api_ai_ask',
        '/api/ai/job'              => 'api_ai_job_create',
        '/api/ai/job/cancel'       => 'api_ai_job_cancel',
        '/api/telegram/link-code'  => 'api_telegram_link_code',
        '/api/telegram/unlink'     => 'api_telegram_unlink',
        '/tg/webhook'              => 'tg_webhook',
    ],
];

$handler = $routes[$method][$path] ?? null;

// ---- SEO: satu-satunya daftar halaman yang boleh diindeks (dipakai juga oleh /sitemap.xml). ----
// Alias diarahkan ke URL utama lewat canonical; semua route lain (wajib login, admin, API, /telegram, /health) = noindex.
$seoBase = 'https://xox.smksilber.cloud';
$seoPages = ['/team' => ['weekly', '1.0'], '/author' => ['monthly', '0.8'], '/login' => ['monthly', '0.7'], '/daftar' => ['monthly', '0.6'],
    '/gabung' => ['monthly', '0.7'], '/bantuan' => ['monthly', '0.6'], '/unduh' => ['monthly', '0.5']];
$seoAlias = ['/masuk' => '/login', '/tentang' => '/team', '/petunjuk' => '/bantuan', '/tutorial' => '/bantuan', '/download' => '/unduh'];
$seoCanon = $seoAlias[$path] ?? $path;
if ($method === 'GET' && isset($seoPages[$seoCanon])) {
    header('Link: <' . $seoBase . $seoCanon . '>; rel="canonical"');
} elseif ($handler !== 'seo_sitemap' && $handler !== 'seo_robots') {
    header('X-Robots-Tag: noindex, nofollow');
}

// ---- Wajib masuk: tidak ada mode tamu. Semua halaman aplikasi butuh akun terdaftar. ----
$publicPages = ['page_login', 'page_register', 'page_telegram', 'page_help', 'page_download', 'page_join_class', 'page_about', 'page_author'];
if ($method === 'GET' && is_string($handler) && str_starts_with($handler, 'page_')
    && !in_array($handler, $publicPages, true) && Auth::user() === null) {
    // Link bagikan (mis. /?mabar=KODE, /pixel?server=5) tetap utuh setelah login.
    $redirFull = $path . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    $redirParam = ($redirFull !== '/') ? '&redirect=' . urlencode($redirFull) : '';
    $adminFlag = ($path === '/admin' || str_starts_with($path, '/admin/')) ? '&admin=1' : '';
    header('Location: /login?need_login=1' . $redirParam . $adminFlag, true, 302);
    exit;
}

// ---- Pagar tamu gabung-cepat: role "tamu" hanya boleh ruang 3D + API yang dipakai /dunia. ----
if (is_string($handler) && (Auth::user()['role'] ?? '') === 'tamu') {
    $guestAllowed = [
        // page_world_pixel = fallback 2D bagi perangkat tanpa WebGL
        'page_world_3d', 'page_join_class', 'page_world_pixel', 'api_guest_join', 'api_logout', 'api_ws_token',
        'api_campus_get', 'api_stats', 'api_servers', 'api_servers_join', 'character_presets_get',
        'api_character_select', 'api_missions', 'api_game_chat_get', 'api_game_chat_send',
        'api_world_visit', 'api_multiplayer_sync', 'api_player_interact', 'api_client_error',
        'api_missions_start', 'api_missions_complete', // siswa tamu tetap bisa menyelesaikan misi guru
        'api_broadcasts', // pengumuman ROOT tetap terdengar di ruang 3D
    ];
    if (!in_array($handler, $guestAllowed, true)) {
        if (str_starts_with($handler, 'page_')) {
            header('Location: /dunia', true, 302);
        } else {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Mode tamu hanya untuk ruang kelas 3D. Login untuk fitur lain.']);
        }
        exit;
    }
}

if ($handler === null) {
    // Scanner trap check happens only when the route is unknown (cannot collide with real routes).
    if (Honeypot::inspect($_SERVER['REQUEST_URI'] ?? '/')) {
        http_response_code(403);
        header('Retry-After: 900');
        header('Content-Type: text/plain; charset=utf-8');
        exit('Akses ditolak: Scanner/Crawler bot terdeteksi.');
    }
    http_response_code(404);
    Honeypot::notFound();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>404</title></head>'
        . '<body style="font-family:sans-serif;background:#090d16;color:#e2e8f0;padding:24px">'
        . '<h1>Halaman tidak ditemukan</h1><a href="/" style="color:#22d3ee">Kembali ke beranda</a></body></html>';
    exit;
}

// Tema pixel global + layar penuh HP: disisipkan sebelum </head> setiap halaman HTML.
if (str_starts_with($handler, 'page_')) {
    ob_start([\VC\View\PixelTheme::class, 'inject']);
}

// Status online (database): dicatat untuk setiap request yang login, maks. 1×/menit per sesi.
if (($vcSeenUser = Auth::user()) !== null) {
    \VC\Domain\Presence::touch((int)$vcSeenUser['id'], $path);
}

switch ($handler) {
    case 'api_broadcasts':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        if (!empty($_GET['all'])) {
            if (!\VC\Domain\Broadcast::canManage($u)) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Khusus staf.']));
            }
            echo json_encode(['status' => 'ok', 'items' => \VC\Domain\Broadcast::all()], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['status' => 'ok', 'items' => \VC\Domain\Broadcast::activeFor($u)], JSON_UNESCAPED_UNICODE);
        break;

    case 'api_broadcast_save':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null || !\VC\Domain\Broadcast::canManage($u)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya staf yang dapat mengelola broadcast.']));
        }
        $res = \VC\Domain\Broadcast::create($u, (array)$body);
        http_response_code($res['status'] === 'ok' ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_broadcast_delete':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null || !\VC\Domain\Broadcast::canManage($u)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya staf yang dapat mengelola broadcast.']));
        }
        $res = \VC\Domain\Broadcast::delete($u, (int)($body['id'] ?? 0));
        http_response_code($res['status'] === 'ok' ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_bug_report':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $res = \VC\Domain\BugReport::submit($u, (array)$body);
        http_response_code($res['status'] === 'ok' ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_avatar_get':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $av = \VC\Domain\AvatarStyle::forUser((int)$u['id'], (string)$u['role']);
        echo json_encode(['status' => 'ok', 'name' => (string)($u['full_name'] ?: $u['username']), 'role' => (string)$u['role']] + $av, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_avatar_save':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null || ($u['role'] ?? '') === 'tamu') {
            http_response_code($u === null ? 401 : 403);
            exit(json_encode(['status' => 'error', 'message' => 'Login akun sekolah untuk menyimpan karakter.']));
        }
        $cfgIn = is_array($body['config'] ?? null) ? $body['config'] : [];
        $bioIn = array_key_exists('bio', $body) ? (string)$body['bio'] : null;
        echo json_encode(\VC\Domain\AvatarStyle::save((int)$u['id'], (string)$u['role'], $cfgIn, $bioIn) + ['message' => 'Karakter tersimpan!'], JSON_UNESCAPED_UNICODE);
        break;

    // ---- HTML pages (rendered by controllers, all share the Phase 5 shell) ----
    case 'page_home':
        \VC\Controllers\HomeController::render();
        break;

    case 'page_login':
        \VC\Controllers\AuthController::renderLogin();
        break;

    case 'page_download':
        \VC\Controllers\DownloadController::render();
        break;

    case 'page_about':
    case 'page_author':
        $crEdit = \VC\Security\Owner::is(Auth::user());
        $crAbout = \VC\View\Credits::about();
        $crNonce = \VC\Security\Headers::nonce();
        $crIsAuthor = $handler === 'page_author';
        $crLead = \VC\View\Credits::members()[0];
        $crBase = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_ends_with((string)($_SERVER['HTTP_HOST'] ?? ''), 'smksilber.cloud') ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'xox.smksilber.cloud')));
        $crDesc = $crIsAuthor ? ($crLead['name'] . ' — ' . ($crLead['role'] ?: 'Author') . ' Virtual City SMK Silber. ' . mb_substr($crLead['backstory'] ?: $crLead['motto'], 0, 120))
            : mb_substr(preg_replace('/\s+/', ' ', $crAbout['text']) ?? '', 0, 160);
        \VC\View\Shell::start(['title' => $crIsAuthor ? $crLead['name'] . ' — Author Virtual City SMK Silber' : 'Tentang & Team — Virtual City SMK Silber', 'active' => 'help', 'user' => Auth::user(),
            'description' => $crDesc,
            'head' => '<link rel="canonical" href="' . htmlspecialchars($crBase . ($crIsAuthor ? '/author' : '/team'), ENT_QUOTES) . '">'
                . '<meta property="og:title" content="' . htmlspecialchars($crIsAuthor ? $crLead['name'] : $crAbout['title'], ENT_QUOTES) . '">'
                . '<meta property="og:description" content="' . htmlspecialchars($crDesc, ENT_QUOTES) . '"><meta property="og:type" content="website">']);
        echo '<style nonce="' . $crNonce . '">' . \VC\View\Credits::CSS . '.cr-wrap{padding:16px;min-height:calc(100dvh - 60px);display:flex;flex-direction:column;justify-content:center;gap:12px}</style>';
        if ($crIsAuthor) {
            echo \VC\View\Credits::authorPage($crEdit);
        } else {
            echo '<div class="cr-wrap">' . \VC\View\Credits::card($crEdit) . '<p style="text-align:center"><a href="/">← Kembali ke dashboard</a> · <a href="/author">Author</a> · <a href="/bantuan">Bantuan</a></p></div>';
        }
        if ($crEdit) {
            echo '<script nonce="' . $crNonce . '">' . \VC\View\Credits::EDITOR_JS . '</script>';
        }
        \VC\View\Shell::end();
        break;

    case 'api_media_config':
        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        echo json_encode(['status' => 'ok'] + \VC\Domain\SiteMedia::publicConfig(), JSON_UNESCAPED_SLASHES);
        break;

    case 'api_media_upload':
        // Badan request = potongan berkas mentah (≤ 4 MB); metadata di header.
        Csrf::check($_SERVER['HTTP_X_CSRF'] ?? null);
        header('Content-Type: application/json');
        $mu = Auth::user();
        $res = \VC\Domain\SiteMedia::uploadChunk($mu, [
            'id' => (string)($_SERVER['HTTP_X_UPLOAD_ID'] ?? ''), 'index' => (int)($_SERVER['HTTP_X_CHUNK_INDEX'] ?? -1),
            'total' => (int)($_SERVER['HTTP_X_CHUNK_TOTAL'] ?? 0), 'name' => rawurldecode((string)($_SERVER['HTTP_X_FILE_NAME'] ?? '')),
            'title' => rawurldecode((string)($_SERVER['HTTP_X_FILE_TITLE'] ?? '')),
        ], (string)file_get_contents('php://input', false, null, 0, \VC\Domain\SiteMedia::CHUNK_MAX + 1));
        if (($res['status'] ?? '') !== 'ok') {
            http_response_code(\VC\Security\Owner::is($mu) ? 400 : 403);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    case 'api_media_action':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        header('Content-Type: application/json');
        $mu = Auth::user();
        $res = ($body['action'] ?? '') === 'theme'
            ? \VC\View\UiTheme::set($mu, (string)($body['theme'] ?? '')) // tema tombol semua pengguna
            : (($body['action'] ?? '') === 'dash'
                ? \VC\Domain\DashScene::set($mu, (array)$body)           // latar dashboard semua pengguna
                : (($body['action'] ?? '') === 'costume'
                    ? \VC\Domain\CostumeEvent::set($mu, (array)$body)    // event kostum anime
                    : \VC\Domain\SiteMedia::action($mu, (array)$body)));
        if (($res['status'] ?? '') !== 'ok') {
            http_response_code(\VC\Security\Owner::is($mu) ? 400 : 403);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    case 'page_media':
        if (!\VC\Security\Owner::is(Auth::user())) {
            http_response_code(403);
            \VC\View\Shell::start(['title' => 'Media — khusus pemilik', 'user' => Auth::user()]);
            echo '<div class="px-panel" style="max-width:520px;margin:40px auto;padding:20px;text-align:center"><h1>🔒 Khusus pemilik</h1><p>Pengaturan latar login & musik hanya untuk akun coffelatte314.</p><a class="px-btn" href="/">← Dashboard</a></div>';
            \VC\View\Shell::end();
            break;
        }
        \VC\Controllers\MediaController::render();
        break;

    case 'seo_sitemap':
        // Halaman publik saja ($seoPages di atas; halaman lain wajib login → tidak diindeks).
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($seoPages as $smPath => [$smFreq, $smPri]) {
            echo '  <url><loc>' . htmlspecialchars($seoBase . $smPath, ENT_XML1) . '</loc><changefreq>' . $smFreq . '</changefreq><priority>' . $smPri . '</priority></url>' . "\n";
        }
        echo '</urlset>' . "\n";
        break;

    case 'seo_robots':
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /admin\nDisallow: /obrolan\nDisallow: /profil\nDisallow: /media\nDisallow: /health\nDisallow: /_bot_trap_sink\nDisallow: /api/v1/__crawler_trap\n\nUser-agent: katana\nDisallow: /\n\nUser-agent: ProjectDiscovery\nDisallow: /\n\nUser-agent: nuclei\nDisallow: /\n\nSitemap: " . $seoBase . "/sitemap.xml\n";
        break;

    case 'app_download':
        \VC\Controllers\DownloadController::handleDownload();
        break;

    case 'page_admin_ai':
        \VC\Controllers\AdminController::renderAiDashboard();
        break;

    case 'page_telegram':
        \VC\Controllers\TelegramController::render();
        break;

    case 'page_help':
        \VC\Controllers\HelpController::render();
        break;

    case 'tg_webhook':
        // Telegram webhook: secret-verified, rate limited and duplicate-safe (see Webhook::run).
        \VC\TgBot\Webhook::run();
        break;

    case 'page_register':
        \VC\Controllers\AuthController::renderRegister();
        break;

    case 'page_gather':
        \VC\Controllers\WorldController::renderGatherPage();
        break;

    case 'page_schedule':
        \VC\Controllers\ScheduleController::renderPage();
        break;

    case 'page_world_3d':
        \VC\Domain\DailyQuest::report((int)(Auth::user()['id'] ?? 0), 'play3d'); // misi harian
        \VC\Controllers\WorldController::render3DPage();
        break;

    case 'page_world_2d':
        \VC\Controllers\WorldController::render2DMapPage();
        break;

    case 'page_join_class':
        \VC\Controllers\WorldController::renderJoinClassPage();
        break;

    case 'page_world_pixel':
        \VC\Domain\DailyQuest::report((int)(Auth::user()['id'] ?? 0), 'playpix'); // misi harian
        \VC\Controllers\WorldController::renderPixelPage();
        break;

    case 'page_map_editor':
        \VC\Controllers\WorldController::renderEditorPage();
        break;

    case 'page_events':
        \VC\Controllers\LearningController::renderEventsPage();
        break;

    case 'page_tasks':
        \VC\Controllers\LearningController::renderClassroomPage();
        break;

    case 'page_quiz':
        $quizId = (int)($_GET['id'] ?? 1);
        \VC\Controllers\LearningController::renderQuizPlayer($quizId);
        break;

    case 'page_chat':
        \VC\Controllers\SocialController::renderChatPage();
        break;

    case 'page_profile':
        if (($pu = Auth::user()) !== null && (int)($_GET['id'] ?? 0) > 0 && (int)$_GET['id'] !== (int)$pu['id']) {
            \VC\Domain\DailyQuest::report((int)$pu['id'], 'profile', (string)(int)$_GET['id']); // misi harian
        }
        \VC\Controllers\SocialController::renderProfilePage();
        break;

    case 'page_friends':
        \VC\Controllers\SocialController::renderFriendsPage();
        break;

    case 'page_missions':
        \VC\Controllers\GameplayController::renderMissionsPage();
        break;

    case 'page_shop':
        \VC\Controllers\GameplayController::renderShopPage();
        break;

    case 'page_character_selector':
        \VC\Controllers\GameplayController::renderCharacterSelectorPage();
        break;

    case 'page_badges':
        \VC\Controllers\GameplayController::renderBadgesPage();
        break;

    case 'page_leaderboard':
        \VC\Controllers\GameplayController::renderLeaderboardPage();
        break;

    // Agent 1 — dashboard chat & misi in-game (chat.moderate / mission.manage).
    case 'page_admin_game':
        $canChat = \VC\Security\Permissions::can('chat.moderate');
        $canMiss = \VC\Security\Permissions::can('mission.manage');
        if (!$canChat && !$canMiss) {
            \VC\Security\Permissions::require('chat.moderate'); // tampilan 403 ramah
        }
        \VC\Controllers\AdminController::renderGameAdminPage();
        break;

    case 'page_admin':
        \VC\Controllers\AdminController::render();
        break;

    case 'api_admin_unban':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleUnban();
        break;

    case 'api_admin_role_update':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleRoleUpdate();
        break;

    case 'api_admin_coin_adjust':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleCoinAdjust();
        break;

    case 'api_admin_staff_create':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleStaffCreate();
        break;

    case 'api_admin_user_delete':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleUserDelete();
        break;

    case 'api_admin_user_resetpass':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleUserResetPassword();
        break;

    case 'api_admin_class_create':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleClassCreate();
        break;

    case 'api_admin_class_batch':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleClassBatch();
        break;

    case 'api_admin_class_delete':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Controllers\AdminController::handleClassDelete();
        break;

    case 'api_totp_setup':
        header('Content-Type: application/json');
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null)); // menulis sesi pending_totp_secret
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya akun ROOT yang dapat mengonfigurasi 2FA.']));
        }
        $secret = \VC\Security\Totp::generateSecret(16);
        $_SESSION['pending_totp_secret'] = $secret;
        $uri = \VC\Security\Totp::getProvisioningUri($secret, (string)$u['username']);
        echo json_encode([
            'status' => 'success',
            'secret' => $secret,
            'otpauth_uri' => $uri,
        ]);
        break;

    case 'api_totp_enable':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya akun ROOT yang dapat mengaktifkan 2FA.']));
        }
        $secret = $_SESSION['pending_totp_secret'] ?? '';
        $code = trim((string)($body['code'] ?? ''));
        if ($secret === '' || !\VC\Security\Totp::verify($secret, $code)) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Kode autentikator salah atau kedaluwarsa. Silakan coba lagi.']));
        }
        $pdo = \VC\Database::get();
        $upd = $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?');
        $upd->execute([$secret, (int)$u['id']]);
        unset($_SESSION['pending_totp_secret']);
        \VC\Security\AuditLog::log('totp_enabled', [], (int)$u['id'], 'ok');
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => '2FA berhasil diaktifkan untuk akun Anda.']);
        break;

    case 'api_totp_verify':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $pendingId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        if ($pendingId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Tidak ada sesi verifikasi 2FA yang aktif.']));
        }
        $code = trim((string)($body['code'] ?? ''));
        $pdo = \VC\Database::get();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$pendingId]);
        $u = $stmt->fetch();
        if (!$u || empty($u['totp_secret']) || !\VC\Security\Totp::verify($u['totp_secret'], $code)) {
            SecurityLog::write('totp_verify_fail', ['user_id' => $pendingId]);
            // Batas tebak kode: 5× salah → verifikasi dibatalkan, harus login ulang dengan kata sandi.
            $_SESSION['pending_2fa_fails'] = (int)($_SESSION['pending_2fa_fails'] ?? 0) + 1;
            if ($_SESSION['pending_2fa_fails'] >= 5) {
                unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_redirect'], $_SESSION['pending_2fa_fails']);
                \VC\Security\AuditLog::log('login_2fa_locked', [], $pendingId, 'fail');
                http_response_code(429);
                header('Content-Type: application/json');
                exit(json_encode(['status' => 'error', 'message' => 'Terlalu banyak kode salah. Silakan login ulang dengan kata sandi.']));
            }
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Kode autentikator salah atau kedaluwarsa.']));
        }
        Auth::regenerate();
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['username'] = $u['username'];
        $safeRedirect = $_SESSION['pending_2fa_redirect'] ?? '/admin';
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_redirect'], $_SESSION['pending_2fa_fails']);
        \VC\Security\AuditLog::log('login_admin_2fa', [], (int)$u['id'], 'ok');
        SecurityLog::write('login_ok', ['portal' => 'admin', '2fa' => true]);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Verifikasi 2FA berhasil.', 'redirect' => $safeRedirect]);
        break;

    case 'api_totp_disable':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya akun ROOT yang dapat menonaktifkan 2FA.']));
        }
        $code = trim((string)($body['code'] ?? ''));
        if (empty($u['totp_secret']) || !\VC\Security\Totp::verify($u['totp_secret'], $code)) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Kode autentikator saat ini tidak valid.']));
        }
        $pdo = \VC\Database::get();
        $upd = $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?');
        $upd->execute([(int)$u['id']]);
        \VC\Security\AuditLog::log('totp_disabled', [], (int)$u['id'], 'ok');
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => '2FA berhasil dinonaktifkan.']);
        break;

    case 'api_totp_status':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        echo json_encode([
            'status' => 'success',
            'enabled' => !empty($u['totp_enabled']),
        ]);
        break;

    case 'api_push_key':
        header('Content-Type: application/json');
        $pubKey = \VC\Security\WebPush::getPublicKey();
        echo json_encode([
            'status' => 'success',
            'public_key' => $pubKey,
        ]);
        break;

    case 'api_push_subscribe':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $sub = $body['subscription'] ?? [];
        $endpoint = (string)($sub['endpoint'] ?? '');
        $keys = $sub['keys'] ?? [];
        $p256dh = (string)($keys['p256dh'] ?? '');
        $auth = (string)($keys['auth'] ?? '');
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Data langganan push tidak lengkap.']));
        }
        \VC\Security\WebPush::saveSubscription((int)$u['id'], $endpoint, $p256dh, $auth);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Berhasil berlangganan notifikasi push.']);
        break;

    case 'api_push_unsubscribe':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $endpoint = (string)($body['endpoint'] ?? '');
        \VC\Security\WebPush::removeSubscription((int)$u['id'], $endpoint);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Berhasil berhenti berlangganan notifikasi push.']);
        break;

    case 'api_quiz_start':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $quizId = (int)($body['quiz_id'] ?? 0);
        $res = \VC\Domain\Learning::startQuizAttempt($quizId, (int)$u['id']);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_quiz_submit':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $attemptId = (int)($body['attempt_id'] ?? 0);
        $answers = (array)($body['answers'] ?? []);
        $res = \VC\Domain\Learning::submitQuizAttempt($attemptId, (int)$u['id'], $answers);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_org_join':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $orgId = (int)($body['org_id'] ?? 0);
        $res = \VC\Domain\Events::joinOrganization($orgId, (int)$u['id']);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_event_attend':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $eventId = (int)($body['event_id'] ?? 0);
        $code = (string)($body['verification_code'] ?? '');
        $res = \VC\Domain\Events::recordAttendance($eventId, (int)$u['id'], $code);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_profile_update':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $ok = \VC\Domain\Social::updateProfile((int)$u['id'], $body);
        header('Content-Type: application/json');
        echo json_encode($ok ? ['status' => 'success'] : ['status' => 'error', 'message' => 'Gagal memperbarui profil.']);
        break;

    case 'api_friends_request':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $friendId = (int)($body['friend_id'] ?? 0);
        $res = \VC\Domain\Social::sendFriendRequest((int)$u['id'], $friendId);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_friends_respond':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $senderId = (int)($body['sender_id'] ?? 0);
        $action = (string)($body['action'] ?? '');
        $ok = \VC\Domain\Social::respondFriendRequest((int)$u['id'], $senderId, $action);
        header('Content-Type: application/json');
        echo json_encode($ok ? ['status' => 'success'] : ['status' => 'error', 'message' => 'Gagal memproses permintaan.']);
        break;

    case 'api_chat_send':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $convId = (int)($body['conversation_id'] ?? 0);
        if ($convId <= 0) {
            $convId = 2; // Default to public channel 2 (Obrolan Santai)
        }
        $text = (string)($body['message_text'] ?? ($body['message'] ?? ''));
        $res = \VC\Domain\Social::sendMessage($convId, (int)$u['id'], $text);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_missions':
        // Agent 1 — daftar misi resmi milik sesi ini (dipakai dunia 3D & Pixel, tanpa hardcode).
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        echo json_encode(['status' => 'ok', 'missions' => \VC\Domain\Gameplay::getMissions((int)$u['id'])]);
        break;

    case 'api_game_chat_get':
        // Agent 1 — riwayat / polling chat in-game. Identitas dari sesi; scope tervalidasi server.
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $scope = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['scope'] ?? 'global')));
        $scopeKey = '';
        if ($scope === 'global' && (int)($_GET['server'] ?? 0) > 0) {
            $rk = \VC\Domain\GameChat::roomKeyFor((int)(Auth::user()['id'] ?? 0), (int)$_GET['server'], (string)(Auth::user()['role'] ?? ''));
            if ($rk === null) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Kamu bukan anggota room ini.']));
            }
            $scopeKey = $rk;
        }
        if ($scope === 'private') {
            $with = (int)($_GET['with'] ?? 0);
            if ($with <= 0) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Parameter "with" wajib untuk chat privat.']));
            }
            $ids = [(int)$u['id'], $with];
            sort($ids);
            $scopeKey = $ids[0] . '-' . $ids[1];
        } elseif ($scope === 'area') {
            $scopeKey = trim((string)($_GET['location_id'] ?? ''));
            if ($scopeKey === '') {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Parameter "location_id" wajib untuk chat area.']));
            }
        }
        $sinceId = (int)($_GET['since_id'] ?? 0);
        $res = $sinceId > 0
            ? \VC\Domain\GameChat::fetchFor((int)$u['id'], $scope, $scopeKey, $sinceId)
            : \VC\Domain\GameChat::fetch((int)$u['id'], $scope, $scopeKey);
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_game_chat_send':
        // Agent 1 — kirim chat in-game. Percayakan lengkapi ke GameChat (settings + moderasi + cooldown).
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $scope = preg_replace('/[^a-z_]/', '', strtolower((string)($body['scope'] ?? 'global')));
        $scopeKey = '';
        if ($scope === 'global' && (int)($body['server'] ?? 0) > 0) {
            $rk = \VC\Domain\GameChat::roomKeyFor((int)(Auth::user()['id'] ?? 0), (int)$body['server'], (string)(Auth::user()['role'] ?? ''));
            if ($rk === null) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Kamu bukan anggota room ini.']));
            }
            $scopeKey = $rk;
        }
        if ($scope === 'private') {
            $with = (int)($body['with'] ?? 0);
            if ($with <= 0) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Penerima private wajib diisi.']));
            }
            $ids = [(int)$u['id'], $with];
            sort($ids);
            $scopeKey = $ids[0] . '-' . $ids[1];
        } elseif ($scope === 'area') {
            $scopeKey = trim((string)($body['location_id'] ?? ''));
        }
        $res = \VC\Domain\GameChat::send((int)$u['id'], $scope, $scopeKey, (string)($body['text'] ?? ''));
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_game_chat_moderate':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        Permissions::require('chat.moderate');
        if (!\VC\Security\Owner::is(Auth::user())) { // moderasi chat = hanya akun pemilik (coffelatte314)
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Moderasi chat hanya untuk pemilik (coffelatte314).']));
        }
        $u = Auth::user();
        $msgId = (int)($body['message_id'] ?? 0);
        $res = \VC\Domain\GameChat::moderate((int)$u['id'], $msgId);
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'chat_settings_get':
        // Read-only publik: dibaca WS relay (polling config) dan klien. Blocklist tidak dibocorkan.
        header('Content-Type: application/json');
        $s = \VC\Domain\GameChat::settings();
        echo json_encode(['status' => 'ok', 'enabled' => $s['enabled'], 'mode' => $s['mode'],
            'scopes' => $s['scopes'], 'cooldown' => $s['cooldown'], 'filter' => $s['filter']]);
        break;

    case 'api_game_chat_relay':
        // Server-to-server dari websocket_server.js (Node). TIDAK untuk klien browser:
        // validasi tanda tangan HMAC waktu + body dengan APP_KEY yang sama dengan token WS.
        header('Content-Type: application/json');
        $ts = (string)($_SERVER['HTTP_X_VC_TIMESTAMP'] ?? '');
        $sig = (string)($_SERVER['HTTP_X_VC_SIGNATURE'] ?? '');
        $raw = (string)file_get_contents('php://input');
        if (!ctype_digit($ts) || $ts === '' || $sig === '' || abs(time() - (int)$ts) > 60) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Relay timestamp tidak valid.']));
        }
        $secret = \vc_env('APP_KEY', 'virtual-city-default-key-silber-2026');
        $expected = hash_hmac('sha256', $ts . '.' . $raw, $secret);
        if (!hash_equals($expected, $sig)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Relay signature tidak sah.']));
        }
        $body = json_decode($raw, true) ?? [];
        $res = \VC\Domain\GameChat::send(
            (int)($body['user_id'] ?? 0),
            (string)($body['scope'] ?? 'global'),
            (string)($body['scope_key'] ?? ''),
            (string)($body['text'] ?? ''),
            ($body['username'] ?? null) !== null ? (string)$body['username'] : null,
            ($body['role'] ?? null) !== null ? (string)$body['role'] : null
        );
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_admin_chat_settings':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        Permissions::require('chat.moderate');
        $u = Auth::user();
        $res = \VC\Domain\GameChat::saveSettings((int)$u['id'], $body);
        echo json_encode($res);
        break;

    case 'api_admin_mission_save':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        Permissions::require('mission.manage');
        $u = Auth::user();
        $res = \VC\Domain\Gameplay::adminSaveMission((int)$u['id'], $body);
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_admin_mission_toggle':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        Permissions::require('mission.manage');
        $u = Auth::user();
        $res = \VC\Domain\Gameplay::adminToggleMission((int)$u['id'], (int)($body['id'] ?? 0), !empty($body['is_active']));
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_admin_chat_recent':
        header('Content-Type: application/json');
        Permissions::require('chat.moderate');
        if (!\VC\Security\Owner::is(Auth::user())) { // moderasi chat = hanya akun pemilik (coffelatte314)
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Moderasi chat hanya untuk pemilik (coffelatte314).']));
        }
        echo json_encode(['status' => 'ok', 'messages' => \VC\Domain\GameChat::adminRecent((int)($_GET['limit'] ?? 60))]);
        break;

    case 'api_admin_missions_list':
        header('Content-Type: application/json');
        Permissions::require('mission.manage');
        echo json_encode(['status' => 'ok', 'missions' => \VC\Domain\Gameplay::adminListMissions()]);
        break;

    case 'chat_messages_get':
        header('Content-Type: application/json');
        $convId = (int)($_GET['conv_id'] ?? ($_GET['conversation_id'] ?? 2));
        if ($convId <= 0) $convId = 2;
        $u = Auth::user();
        $sinceId = (int)($_GET['since_id'] ?? 0);
        $limit = min(50, max(5, (int)($_GET['limit'] ?? 30)));
        if ($u === null || !\VC\Domain\Social::canAccess($convId, (int)$u['id'])) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Tidak punya akses ke obrolan ini.']));
        }
        $msgs = \VC\Domain\Social::getMessages($convId, (int)$u['id'], $limit, $sinceId);
        echo json_encode([
            'status' => 'success',
            'conversation_id' => $convId,
            'messages' => $msgs,
            'deleted_ids' => \VC\Domain\Social::deletedIds($convId),
            'moderator' => \VC\Domain\Social::isModerator((int)$u['id']),
        ]);
        break;

    case 'api_users_search':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        echo json_encode(['status' => 'ok', 'users' => \VC\Domain\Social::searchUsers((string)($_GET['q'] ?? ''), 15)], JSON_UNESCAPED_UNICODE);
        break;

    case 'api_server_session':
        header('Content-Type: application/json');
        $u = Auth::user();
        $sid = (int)($_GET['server'] ?? 0);
        if ($u === null || $sid <= 0) {
            http_response_code(401);
            exit(json_encode(['status' => 'error']));
        }
        echo json_encode(['status' => 'ok', 'session' => \VC\Domain\ServerSessions::current($sid), 'now' => \VC\Domain\Schedule::nowLocal('H:i')], JSON_UNESCAPED_UNICODE);
        break;

    case 'api_server_session_action':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $act = (string)($body['action'] ?? '');
        if ($act === 'arrive') {
            $res = \VC\Domain\ServerSessions::arrive((int)$u['id'], (int)($body['session_id'] ?? 0), (int)($body['server_id'] ?? 0));
        } elseif ($act === 'save') {
            $res = \VC\Domain\ServerSessions::create($u, (int)($body['server_id'] ?? 0), (array)$body);
        } elseif ($act === 'delete') {
            $res = \VC\Domain\ServerSessions::delete($u, (int)($body['id'] ?? 0));
        } else {
            $res = ['status' => 'error', 'message' => 'Aksi tidak dikenal.'];
        }
        if (($res['status'] ?? '') !== 'ok') {
            http_response_code(400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_party':
        // MABAR: status party milik sesi + undangan masuk + daftar tujuan (untuk kapten).
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $party = \VC\Domain\Party::current((int)$u['id']);
        $dests = [];
        if ($party !== null && $party['captain_id'] === (int)$u['id']) {
            foreach (\VC\Domain\GameServers::listFor((int)$u['id'], (string)$u['role']) as $sv) {
                if (!$sv['needs_code']) {
                    $dests[] = ['url' => \VC\Domain\GameServers::worldUrl($sv['world'], $sv['id']), 'label' => ($sv['world'] === 'pixel' ? '🧱 ' : '🚀 ') . $sv['name'], 'online' => $sv['online']];
                }
            }
        }
        $isCap = $party !== null && $party['captain_id'] === (int)$u['id'];
        echo json_encode(['status' => 'ok', 'me' => (int)$u['id'], 'party' => $party, 'invites' => \VC\Domain\Party::invitesFor((int)$u['id']), 'dests' => $dests,
            'public' => $party === null ? \VC\Domain\Party::publicList() : [],
            'quests' => \VC\Domain\Party::questList(),
            // pengguna online (dari database) untuk diundang — hanya untuk kapten / yang belum punya party
            'online' => ($party === null || $isCap) ? \VC\Domain\Presence::onlineUsers((int)$u['id'], 20) : []], JSON_UNESCAPED_UNICODE);
        break;

    case 'api_party_token':
        header('Content-Type: application/json');
        Csrf::check($_GET['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        $pid = $u !== null ? \VC\Domain\Party::idOf((int)$u['id']) : 0;
        if ($pid <= 0) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Kamu belum bergabung di mabar.']));
        }
        $token = Auth::createWsToken($u, \VC\Domain\Party::ROOM_BASE + $pid);
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        // Port dari header Host lebih dulu: di Docker Apache mendengar :80 tapi dibuka lewat localhost:8080.
        $portPart = preg_match('/:(\d+)$/', (string)($_SERVER['HTTP_HOST'] ?? ''), $hostPortMatch)
            ? (in_array((int)$hostPortMatch[1], [80, 443], true) ? '' : ':' . $hostPortMatch[1])
            : ((!empty($_SERVER['SERVER_PORT']) && !in_array((int)$_SERVER['SERVER_PORT'], [80, 443], true)) ? ':' . $_SERVER['SERVER_PORT'] : '');
        echo json_encode(['status' => 'ok', 'party_id' => $pid,
            'url' => ($isHttps ? 'wss://' : 'ws://') . $host . $portPart . '/ws/?world=party&token=' . urlencode((string)$token),
            'direct_url' => ($isHttps ? 'wss://' : 'ws://') . $host . ':8090/?world=party&token=' . urlencode((string)$token)]);
        break;

    case 'api_party_action':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $uid = (int)$u['id'];
        switch ((string)($body['action'] ?? '')) {
            case 'create':
                $res = \VC\Domain\Party::create($u, (string)($body['name'] ?? ''), (string)($body['visibility'] ?? 'code'));
                break;
            case 'visibility':
                $res = \VC\Domain\Party::setVisibility($uid, (string)($body['visibility'] ?? 'code'));
                break;
            case 'quest':
                $res = \VC\Domain\Party::setQuest($uid, (string)($body['quest'] ?? ''));
                break;
            case 'report':
                // kejadian misi mabar dari dunia game (kucing, sampah, anomali); kunjungan dicatat di /api/world/visit
                $ev = (string)($body['event'] ?? '');
                $res = in_array($ev, ['cat', 'trash', 'anomaly'], true)
                    ? \VC\Domain\Party::report($uid, $ev, (string)($body['ref'] ?? ''))
                    : ['status' => 'error', 'message' => 'Kejadian tidak dikenal.'];
                if (($res['status'] ?? '') === 'idle') {
                    $res['status'] = 'ok';
                }
                break;
            case 'join':
                $res = \VC\Domain\Party::join($u, (string)($body['code'] ?? ''), (int)($body['party_id'] ?? 0));
                break;
            case 'leave':
                \VC\Domain\Party::leave($uid);
                $res = ['status' => 'ok', 'message' => 'Kamu keluar dari mabar.'];
                break;
            case 'invite':
                $res = \VC\Domain\Party::invite($u, (int)($body['user_id'] ?? 0));
                break;
            case 'kick':
                $res = \VC\Domain\Party::kick($uid, (int)($body['user_id'] ?? 0));
                break;
            case 'go':
                $res = \VC\Domain\Party::go($uid, (string)($body['dest'] ?? ''));
                break;
            case 'decline':
                \VC\Domain\Party::decline($uid, (int)($body['party_id'] ?? 0));
                $res = ['status' => 'ok'];
                break;
            default:
                $res = ['status' => 'error', 'message' => 'Aksi tidak dikenal.'];
        }
        if (($res['status'] ?? '') !== 'ok') {
            http_response_code(400);
        } elseif (in_array((string)($body['action'] ?? ''), ['create', 'join'], true)) {
            \VC\Domain\DailyQuest::report($uid, 'mabar'); // misi harian "main bareng"
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_team_contributors':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $res = \VC\View\Credits::manage($u, (array)$body); // hanya pemilik (coffelatte314)
        if ($res['status'] !== 'ok') {
            http_response_code(400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_chat_delete':
        // Hapus pesan obrolan (soft-delete): pengirim sendiri / moderator (ROOT, Kepala Sekolah).
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $res = \VC\Domain\Social::deleteMessage((int)($body['message_id'] ?? 0), (int)$u['id']);
        if (($res['status'] ?? '') !== 'success') {
            http_response_code(403);
        }
        echo json_encode($res);
        break;

    case 'api_missions_start':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $mId = (int)($body['mission_id'] ?? 0);
        $res = \VC\Domain\Gameplay::startMission((int)$u['id'], $mId);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_missions_complete':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $mId = (int)($body['mission_id'] ?? 0);
        $res = \VC\Domain\Gameplay::completeMission((int)$u['id'], $mId);
        if (($res['status'] ?? '') === 'success') {
            \VC\Domain\DailyQuest::report((int)$u['id'], 'mission', (string)$mId); // misi harian
        }
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_world_visit':
        // Agen 1 — rekam kunjungan lokasi pemain dari dunia 3D / peta.
        // Prasyarat pengklaiman misi location-bound; ID lokasi divalidasi oleh
        // Gameplay::recordVisit terhadap campus.json (bukan input bebas klien).
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $res = \VC\Domain\Gameplay::recordVisit((int)$u['id'], trim((string)($body['location_id'] ?? '')));
        if (($res['status'] ?? '') === 'ok') {
            \VC\Domain\DailyQuest::report((int)$u['id'], 'visit', (string)$res['location']); // misi harian
            $pq = \VC\Domain\Party::report((int)$u['id'], 'visit', (string)$res['location']); // misi mabar "Tur Sekolah"
            if (isset($pq['quest'])) {
                $res['party_quest'] = $pq['quest'];
            }
        }
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_shop_buy':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $itId = (int)($body['item_id'] ?? 0);
        $res = \VC\Domain\Gameplay::purchaseItem((int)$u['id'], $itId);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'api_profile_photo':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null || ($u['role'] ?? '') === 'tamu') {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login dengan akun sekolah untuk mengganti foto.']));
        }
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        if (($_POST['action'] ?? '') === 'remove') {
            // Pemilik sistem boleh menghapus foto tidak pantas milik siapa pun.
            $target = (int)($_POST['user_id'] ?? 0);
            if ($target > 0 && $target !== (int)$u['id'] && !\VC\Security\Owner::is($u)) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Tidak diizinkan.']));
            }
            echo json_encode(\VC\Domain\ProfilePhoto::remove($target > 0 ? $target : (int)$u['id']));
            break;
        }
        $f = $_FILES['photo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Pilih foto terlebih dahulu.']));
        }
        $res = \VC\Domain\ProfilePhoto::save((int)$u['id'], (string)file_get_contents((string)$f['tmp_name']));
        if ($res['status'] !== 'ok') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_inventory_get':
    case 'api_inventory_post':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null || ($u['role'] ?? '') === 'tamu') {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login dengan akun sekolah untuk memakai inventaris.']));
        }
        if ($handler === 'api_inventory_get') {
            echo json_encode(\VC\Domain\Inventory::forUser($u), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        echo json_encode(\VC\Domain\Inventory::save($u, (array)$body), JSON_UNESCAPED_UNICODE);
        break;

    case 'api_costumes_get':
        header('Content-Type: application/json');
        if (Auth::user() === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        echo json_encode(\VC\Domain\CostumeEvent::catalog(), JSON_UNESCAPED_UNICODE);
        break;

    case 'api_daily_get':
    case 'api_daily_post':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null || ($u['role'] ?? '') === 'tamu') {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        if ($handler === 'api_daily_get') {
            echo json_encode(\VC\Domain\DailyQuest::status((int)$u['id']), JSON_UNESCAPED_UNICODE);
            break;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $res = (($body['action'] ?? '') === 'claim')
            ? \VC\Domain\DailyQuest::claim((int)$u['id'], (string)($body['quest'] ?? ''))
            : \VC\Domain\DailyQuest::clientReport((int)$u['id'], (string)($body['event'] ?? ''), (string)($body['ref'] ?? ''));
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'api_gacha_get':
    case 'api_gacha_spin':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null || ($u['role'] ?? '') === 'tamu') {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login dengan akun sekolah untuk memutar gacha.']));
        }
        if ($handler === 'api_gacha_get') {
            echo json_encode(\VC\Domain\Gacha::status((int)$u['id']));
            break;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $gres = \VC\Domain\Gacha::spin((int)$u['id'], (string)($body['mode'] ?? 'paid'));
        if (($gres['status'] ?? '') === 'ok') {
            \VC\Domain\DailyQuest::report((int)$u['id'], 'gacha'); // misi harian
        }
        echo json_encode($gres);
        break;

    case 'api_shop_equip':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $itId = (int)($body['item_id'] ?? 0);
        $res = \VC\Domain\Gameplay::equipItem((int)$u['id'], $itId);
        header('Content-Type: application/json');
        echo json_encode($res);
        break;

    case 'character_presets_get':
        header('Content-Type: application/json');
        $u = Auth::user();
        $uid = $u ? (int)$u['id'] : 0;
        $data = \VC\Domain\Gameplay::getCharacterPresets($uid);
        echo json_encode($data);
        break;

    case 'api_character_select':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $presetId = (int)($body['preset_id'] ?? 0);
        $res = \VC\Domain\Gameplay::selectCharacter((int)$u['id'], $presetId);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_character_customize':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $config = (array)($body['config'] ?? []);
        $res = \VC\Domain\Gameplay::saveAvatarConfig((int)$u['id'], $config);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_character_unlock':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $slug = trim((string)($body['slug'] ?? ''));
        $idemp = (string)($body['idempotency_key'] ?? '');
        $res = \VC\Domain\Gameplay::unlockCharacter((int)$u['id'], $slug, $idemp ?: null);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_register':
        Csrf::check($_POST['_csrf'] ?? null);
        // Bot defense: honeypot filled → silent hardening.
        if (!empty($_POST['website'])) {
            SecurityLog::write('register_bot_suspect', []);
            RateLimiter::hit('register_ip', Ip::bucket());
        }
        $wait = RateLimiter::hit('register_ip', Ip::bucket());
        if ($wait > 0) {
            http_response_code(429);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Terlalu banyak pendaftaran dari perangkat ini. Tunggu sebentar.']));
        }
        $result = \VC\Domain\Registration::create(
            (string)($_POST['full_name'] ?? ''),
            (string)($_POST['nis'] ?? ''),
            (int)($_POST['class_id'] ?? 0),
            (string)($_POST['username'] ?? ''),
            (string)($_POST['password'] ?? ''),
            Ip::client()
        );
        header('Content-Type: application/json');
        if (($result['status'] ?? '') === 'error') {
            http_response_code(400);
            echo json_encode($result);
            break;
        }
        // Auto-login langsung setelah registrasi berhasil
        $pdo = \VC\Database::get();
        $regUser = strtolower(trim((string)($_POST['username'] ?? '')));
        $uStmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND status = "active" AND deleted_at IS NULL');
        $uStmt->execute([$regUser]);
        $loggedUser = $uStmt->fetch();
        if ($loggedUser) {
            Auth::regenerate();
            $_SESSION['user_id'] = (int)$loggedUser['id'];
            $_SESSION['username'] = $loggedUser['username'];
            \VC\Security\AuditLog::log('login_register', [], (int)$loggedUser['id'], 'ok');
            $result['redirect'] = '/';
            $result['message'] = 'Pendaftaran berhasil! Akun Anda aktif, langsung masuk ke Virtual City...';
        }
        echo json_encode($result);
        break;

    case 'api_approve':
        Csrf::check($_POST['_csrf'] ?? null);
        $me = Auth::user();
        if ($me === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login dulu.']));
        }
        \VC\Security\Permissions::require('user.approve_registration');
        $regId = (int)($_POST['reg_id'] ?? 0);
        $action = ($_POST['action'] ?? '') === 'reject' ? 'reject' : 'approve';
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($regId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'reg_id tidak valid.']));
        }
        $ok = $action === 'approve'
            ? \VC\Domain\Registration::activateFromRegistration($regId, (int)$me['id'], $reason !== '' ? $reason : 'Disetujui oleh staf')
            : \VC\Domain\Registration::reject($regId, (int)$me['id'], $reason !== '' ? $reason : 'Ditolak oleh staf');
        header('Content-Type: application/json');
        echo json_encode($ok
            ? ['status' => 'success', 'message' => $action === 'approve' ? 'Pendaftar disetujui & akun aktif.' : 'Pendaftar ditolak.']
            : ['status' => 'error', 'message' => 'Data pendaftaran tidak ditemukan / sudah diproses.']);
        break;

    case 'api_csrf':
        header('Content-Type: application/json');
        echo json_encode(['csrf' => Csrf::token()]);
        break;

    case 'health':
        header('Content-Type: application/json');
        $ok = true;
        try {
            \VC\Database::get()->query('SELECT 1');
        } catch (Throwable $e) {
            $ok = false;
        }
        echo json_encode([
            'status' => $ok ? 'ok' : 'db_down',
            'time' => date('c'),
            'app' => 'virtual-city-smk-silber',
            'version' => '5.0.0-phase5',
        ]);
        break;

    case 'api_client_error':
        // Rate-limited, body-capped client error sink (never stores personal data).
        header('Content-Type: application/json');
        $waitErr = RateLimiter::hit('client_err_ip', Ip::bucket());
        if ($waitErr > 0) {
            http_response_code(429);
            exit(json_encode(['status' => 'error']));
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, 2048);
        $payload = json_decode($raw, true);
        $msg = is_array($payload) ? (string)($payload['message'] ?? '') : '';
        $page = is_array($payload) ? (string)($payload['path'] ?? '') : '';
        SecurityLog::write('client_error', [
            'message' => mb_substr(preg_replace('/[^\P{C}]/u', '', $msg) ?? '', 0, 200),
            'path' => mb_substr($page, 0, 120),
        ]);
        http_response_code(204);
        break;

    case 'api_campus_get':
        header('Content-Type: application/json');
        $file = VC_PUBLIC . '/assets/map/campus.json';
        if (is_file($file)) {
            echo file_get_contents($file);
        } else {
            echo json_encode(['buildings' => [], 'rooms' => []]);
        }
        break;

    case 'api_campus_save':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        \VC\Security\Permissions::require('world.edit_details');
        $u = Auth::user();

        if (!isset($body['campus']) || !is_array($body['campus'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Format campus json tidak valid.']);
            break;
        }

        $file = VC_PUBLIC . '/assets/map/campus.json';
        file_put_contents($file, json_encode($body['campus'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        \VC\Security\AuditLog::log('campus_map_edit', ['file' => 'campus.json'], $u ? (int)$u['id'] : null);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Peta sekolah berhasil disimpan.']);
        break;

    case 'api_schedule_today':
        header('Content-Type: application/json');
        $classId = (int)($_GET['class_id'] ?? 0);
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : null;

        if ($classId > 0) {
            echo json_encode(\VC\Domain\Schedule::getTodayScheduleForClass($classId, $date));
        } elseif ($teacherId > 0) {
            echo json_encode(\VC\Domain\Schedule::getTodayScheduleForTeacher($teacherId, $date));
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Parameter class_id atau teacher_id wajib diisi.']);
        }
        break;

    case 'api_schedule_whereis':
        header('Content-Type: application/json');
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        if ($teacherId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'teacher_id tidak valid.']);
            break;
        }
        echo json_encode(\VC\Domain\Schedule::findTeacherLocation($teacherId));
        break;

    case 'api_schedule_template':
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="template_jadwal.csv"');
        echo \VC\Domain\Schedule::getTemplateCsv();
        break;

    case 'api_schedule_versions':
        header('Content-Type: application/json');
        echo json_encode(\VC\Domain\Schedule::getVersions(15));
        break;

    case 'api_schedule_preview':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('schedule.import');

        $csvContent = '';
        if (isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $origName = strtolower((string)($_FILES['file']['name'] ?? ''));
            if (str_ends_with($origName, '.xlsx')) {
                try {
                    $csvContent = \VC\Domain\XlsxReader::toCsv($_FILES['file']['tmp_name']);
                } catch (\Throwable $xe) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    exit(json_encode(['status' => 'error', 'message' => 'Gagal membaca berkas Excel (.xlsx): ' . $xe->getMessage()]));
                }
            } else {
                $csvContent = (string)file_get_contents($_FILES['file']['tmp_name']);
            }
        } else {
            $csvContent = (string)($_POST['csv_content'] ?? '');
        }

        if ($csvContent === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'File CSV/Excel tidak ditemukan atau kosong.']);
            break;
        }

        $mode = (($_POST['mode'] ?? '') === 'override') ? 'override' : 'weekly';
        $targetDate = (string)($_POST['target_date'] ?? null);

        try {
            $rawRows = \VC\Domain\Schedule::parseCsv($csvContent);
            $validation = \VC\Domain\Schedule::validate($rawRows, $mode, $targetDate);
            header('Content-Type: application/json');
            echo json_encode(array_merge(['status' => 'success'], $validation));
        } catch (\Throwable $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'api_schedule_apply':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('schedule.import');

        $validRows = (array)($body['valid_rows'] ?? []);
        $mode = (($body['mode'] ?? '') === 'override') ? 'override' : 'weekly';
        $targetDate = (string)($body['target_date'] ?? null);
        $filename = (string)($body['filename'] ?? 'jadwal.csv');
        $fileHash = hash('sha256', json_encode($validRows));

        $res = \VC\Domain\Schedule::apply($validRows, $mode, $targetDate, $filename, $fileHash, (int)$u['id']);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_schedule_rollback':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('schedule.rollback');

        $versionId = (int)($body['version_id'] ?? 0);
        $res = \VC\Domain\Schedule::rollback($versionId, (int)$u['id']);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_schedule_slot_save':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('schedule.import');

        $res = \VC\Domain\Schedule::saveSingleSlot($body, (int)$u['id']);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error' || ($res['status'] ?? '') === 'conflict') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_schedule_slot_delete':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('schedule.import');

        $res = \VC\Domain\Schedule::deleteSingleSlot($body, (int)$u['id']);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_login':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $waitLogin = RateLimiter::hit('login_ip', Ip::bucket());
        if ($waitLogin > 0) {
            SecurityLog::write('login_rate_limited', []);
            http_response_code(429);
            header('Retry-After: ' . (int)ceil($waitLogin / 1000));
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Terlalu banyak percobaan. Tunggu sebentar.']));
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $user = Auth::verifyLogin($username, $password);
        if ($user !== null && ($user['status'] ?? 'active') !== 'active') {
            // Active gate: pending/suspended accounts must never receive a session.
            SecurityLog::write('login_not_active', ['username' => substr($username, 0, 50), 'status' => (string)$user['status']]);
            http_response_code(403);
            header('Content-Type: application/json');
            $msgLogin = ($user['status'] ?? '') === 'pending'
                ? 'Akun kamu masih menunggu persetujuan guru/admin. Daftar dulu lalu tunggu verifikasi.'
                : 'Akun kamu ditangguhkan. Hubungi guru/admin sekolah.';
            exit(json_encode(['status' => 'error', 'message' => $msgLogin]));
        }
        if ($user === null) {
            $pdo = \VC\Database::get();
            $norm = strtolower(trim($username));
            $chkPending = $pdo->prepare('SELECT id, full_name, nis, status FROM registrations WHERE (username = ? OR nis = ?) ORDER BY id DESC LIMIT 1');
            $chkPending->execute([$norm, $norm]);
            $pend = $chkPending->fetch();
            if ($pend && ($pend['status'] ?? '') === 'pending') {
                http_response_code(403);
                header('Content-Type: application/json');
                exit(json_encode([
                    'status' => 'pending',
                    'message' => 'Akun pendaftaran Anda (' . htmlspecialchars((string)$pend['full_name'], ENT_QUOTES) . ') masih menunggu persetujuan Guru/Admin di sistem sekolah. Silakan hubungi wali kelas atau tunggu verifikasi.'
                ]));
            }
            if ($pend && ($pend['status'] ?? '') === 'rejected') {
                http_response_code(403);
                header('Content-Type: application/json');
                exit(json_encode([
                    'status' => 'rejected',
                    'message' => 'Pendaftaran akun Anda sebelumnya ditolak oleh admin/guru. Silakan daftar kembali atau hubungi sekolah.'
                ]));
            }
            SecurityLog::write('login_failed', ['username' => substr($username, 0, 50)]);
            \VC\Security\AuditLog::log('login', ['username' => substr($username, 0, 50)], null, 'fail');
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Username atau password salah. Pastikan NIS atau username sudah benar.']));
        }
        $staffRoles = ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'];
        $isStaff = in_array($user['role'] ?? '', $staffRoles, true);
        $reqRedirect = trim((string)($_POST['redirect'] ?? $_GET['redirect'] ?? ''));
        $safeRedirect = null;
        if ($reqRedirect !== '' && str_starts_with($reqRedirect, '/') && !str_starts_with($reqRedirect, '//') && !str_contains($reqRedirect, '\\')) {
            if (str_starts_with($reqRedirect, '/admin') && !$isStaff) {
                $safeRedirect = '/';
            } else {
                $safeRedirect = $reqRedirect;
            }
        }
        $redirect = $safeRedirect ?: ($isStaff ? '/admin' : '/');
        // 2FA berlaku di SEMUA pintu login: akun dengan 2FA aktif (mis. ROOT) yang masuk lewat tab Siswa
        // tetap wajib kode autentikator — dulu tab Siswa membuat sesi langsung (2FA terlewati).
        if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
            $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
            $_SESSION['pending_2fa_fails'] = 0;
            $_SESSION['pending_2fa_redirect'] = $redirect;
            header('Content-Type: application/json');
            echo json_encode(['status' => '2fa_required', 'message' => 'Masukkan 6 digit kode autentikator (2FA).']);
            break;
        }
        Auth::regenerate();
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        \VC\Security\AuditLog::log('login', [], (int)$user['id'], 'ok');
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Login berhasil.', 'redirect' => $redirect]);
        break;

    /* Admin login from the home page: same hardening as /api/login, but the
       account must hold a staff/admin role or the attempt is refused. */
    case 'api_login_admin':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $waitAdmin = RateLimiter::hit('login_ip', Ip::bucket());
        if ($waitAdmin > 0) {
            SecurityLog::write('login_rate_limited', ['portal' => 'admin']);
            http_response_code(429);
            header('Retry-After: ' . (int)ceil($waitAdmin / 1000));
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Terlalu banyak percobaan. Tunggu sebentar.']));
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $user = Auth::verifyLogin($username, $password);
        $adminRoles = ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'];
        if ($user !== null && ($user['status'] ?? 'active') !== 'active') {
            SecurityLog::write('login_not_active', ['username' => substr($username, 0, 50), 'portal' => 'admin']);
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Akun staf tidak aktif (menunggu persetujuan atau ditangguhkan).']));
        }
        if ($user === null || !in_array($user['role'], $adminRoles, true)) {
            SecurityLog::write('admin_login_failed', ['username' => substr($username, 0, 50)]);
            \VC\Security\AuditLog::log('login_admin', ['username' => substr($username, 0, 50)], $user !== null ? (int)$user['id'] : null, 'fail');
            // Same generic 401 as the student portal: never reveal which half failed.
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Username atau password salah, atau akun bukan staf.']));
        }
        $reqRedirect = trim((string)($_POST['redirect'] ?? $_GET['redirect'] ?? ''));
        $safeRedirect = ($reqRedirect !== '' && str_starts_with($reqRedirect, '/') && !str_starts_with($reqRedirect, '//') && !str_contains($reqRedirect, '\\')) ? $reqRedirect : '/admin';

        if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
            $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
            $_SESSION['pending_2fa_fails'] = 0;
            $_SESSION['pending_2fa_redirect'] = $safeRedirect;
            header('Content-Type: application/json');
            echo json_encode([
                'status' => '2fa_required',
                'message' => 'Masukkan 6 digit kode autentikator (2FA).',
            ]);
            break;
        }

        Auth::regenerate();
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        \VC\Security\AuditLog::log('login_admin', [], (int)$user['id'], 'ok');
        SecurityLog::write('login_ok', ['portal' => 'admin']);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Login admin berhasil.', 'redirect' => $safeRedirect]);
        break;

    /* Player progress for the HUD (level / XP / coins). Logged-in only. */
    case 'api_stats':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $p = \VC\Domain\Gameplay::getProgress((int)$u['id']);
        $level = (int)$p['level'];
        $totalXp = (int)$p['total_xp'];
        echo json_encode([
            'status' => 'success',
            'level' => $level,
            'total_xp' => $totalXp,
            'coins' => (int)$p['coins'],
            'login_streak' => (int)$p['login_streak'],
            'titles' => (string)$p['titles'],
            'xp_into_level' => \VC\Domain\Gameplay::xpIntoLevel($totalXp, $level),
            'xp_to_next' => \VC\Domain\Gameplay::xpToNextLevel($totalXp, $level),
        ]);
        break;

    case 'api_logout':
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u !== null) {
            \VC\Security\AuditLog::log('logout', [], (int)$u['id'], 'ok');
        }
        Auth::logout();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        break;

    // ---- Phase 6: notifications ----
    case 'api_notifications':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        echo json_encode([
            'unread' => \VC\Domain\Notification::unreadCount((int)$u['id']),
            'notifications' => \VC\Domain\Notification::forUser((int)$u['id'], 30),
        ]);
        break;

    case 'api_notifications_read':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error']));
        }
        $updated = !empty($body['all'])
            ? \VC\Domain\Notification::markAllRead((int)$u['id'])
            : \VC\Domain\Notification::markRead((int)$u['id'], (array)($body['ids'] ?? []));
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'updated' => $updated]);
        break;

    case 'api_notifications_prefs':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error']));
        }
        \VC\Domain\Notification::setPref((int)$u['id'], (string)($body['category'] ?? ''), (bool)($body['enabled'] ?? false));
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Preferensi notifikasi disimpan.']);
        break;

    // ---- Phase 6: assignments ----
    case 'api_task_create':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('task.create');

        // Validate input and enforce scope: a teacher may only create tasks for classes they teach.
        $subjectId = (int)($body['subject_id'] ?? 0);
        $classId = (int)($body['class_id'] ?? 0);
        $title = trim((string)($body['title'] ?? ''));
        $instructions = trim((string)($body['instructions'] ?? ''));
        $deadline = trim((string)($body['deadline'] ?? ''));
        $deadline = $deadline !== '' ? str_replace('T', ' ', $deadline) : '';
        $deadline = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $deadline) === 1
            ? (strlen($deadline) === 16 ? $deadline . ':00' : $deadline)
            : '';
        $isStaffAll = \VC\Security\Permissions::can('user.view');

        header('Content-Type: application/json');
        // Opsional: kirim ke server/room game → semua anggota server ikut menerima tugas.
        // Kelas sasaran otomatis diambil dari server bila server terhubung ke rombel.
        $serverId = (int)($body['server_id'] ?? 0);
        $targetServer = null;
        if ($serverId > 0) {
            $targetServer = \VC\Domain\GameServers::canAssign((int)$u['id'], $serverId, $isStaffAll);
            if ($targetServer === null) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Anda tidak bisa mengirim tugas ke server ini.']));
            }
            if ((int)($targetServer['class_id'] ?? 0) > 0) {
                $classId = (int)$targetServer['class_id'];
            }
        }
        // Soal di dalam tugas (pilihan ganda + essay) — opsional.
        [$questions, $qErr] = \VC\Domain\AssignmentQuiz::sanitize($body['questions'] ?? null);
        if ($qErr !== null) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => $qErr]));
        }
        if ($instructions === '' && $questions !== []) {
            $instructions = 'Kerjakan semua soal di bawah ini dengan teliti.';
        }
        $valid = $subjectId > 0 && $classId > 0 && $title !== '' && mb_strlen($title) <= 150 && $instructions !== '' && $deadline !== '';
        if (!$valid) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Data tugas tidak lengkap atau format tenggat tidak valid.']));
        }
        if ($targetServer === null && !\VC\Domain\ClassroomAccess::teacherTeachesClass((int)$u['id'], $classId, $isStaffAll)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Anda tidak mengajar kelas ini.']));
        }

        $id = (int)\VC\Domain\Learning::createAssignment(
            $subjectId,
            (int)$u['id'],
            $classId,
            $title,
            $instructions,
            $deadline,
            max(1, min(1000, (int)($body['max_score'] ?? 100))),
            !empty($body['allow_late']),
            max(0, min(100, (int)($body['late_penalty'] ?? 10))),
            $targetServer !== null ? $serverId : null,
            $questions
        );
        if ($id <= 0) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Gagal membuat tugas.']));
        }
        $recipients = \VC\Domain\ClassroomAccess::studentsOfClass($classId);
        if ($targetServer !== null) {
            $recipients = array_values(array_diff(array_unique(array_merge($recipients, \VC\Domain\GameServers::memberIds($serverId))), [(int)$u['id']]));
        }
        \VC\Domain\Notification::pushMany(
            $recipients,
            'tugas',
            'Tugas baru: ' . $title . ($targetServer !== null ? ' (room ' . $targetServer['name'] . ')' : ''),
            'Tenggat ' . $deadline . '. Segera kerjakan sebelum batas waktu.',
            '/tugas',
            'assignment:' . $id
        );
        \VC\Security\AuditLog::log('assignment_create', ['assignment_id' => $id, 'class_id' => $classId, 'server_id' => $targetServer !== null ? $serverId : null], (int)$u['id']);
        echo json_encode(['status' => 'success', 'message' => 'Tugas dibuat & ' . count($recipients) . ' siswa dinotifikasi.', 'id' => $id, 'class_id' => $classId, 'server_id' => $targetServer !== null ? $serverId : null]);
        break;

    case 'api_task_delete':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $assignmentId = (int)($body['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'ID tugas tidak valid.']));
        }
        $isStaffAll = \VC\Security\Permissions::can('user.view');
        if (!\VC\Domain\ClassroomAccess::teacherOwnsAssignment($assignmentId, (int)$u['id'], $isStaffAll)) {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Anda tidak berhak menghapus tugas ini.']));
        }
        $ok = \VC\Domain\Learning::deleteAssignment($assignmentId, (int)$u['id'], $isStaffAll);
        header('Content-Type: application/json');
        if ($ok) {
            echo json_encode(['status' => 'success', 'message' => 'Tugas berhasil dihapus.']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus tugas atau tugas tidak ditemukan.']);
        }
        break;

    case 'api_task_submit':
        if (empty($_POST) && ($rawInput = file_get_contents('php://input'))) {
            $jsonParsed = json_decode($rawInput, true);
            if (is_array($jsonParsed)) {
                $_POST = $jsonParsed;
            }
        }
        Csrf::check($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        header('Content-Type: application/json');
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $text = trim((string)($_POST['content_text'] ?? ''));

        // The assignment must belong to the student's own class (no cross-class submissions).
        $stmt = \VC\Database::get()->prepare('SELECT class_id, server_id, teacher_id, title, questions, max_score, late_penalty FROM assignments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$assignmentId]);
        $asg = $stmt->fetch();
        if (!$asg) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Tugas tidak ditemukan.']));
        }
        if (!\VC\Domain\Learning::studentCanAccess((int)$u['id'], (array)$asg)) { // kelas sasaran ATAU anggota server
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Tugas ini bukan untuk kelas Anda.']));
        }

        // Optional attachment: validated MIME/size, stored outside the web root.
        $fileName = null;
        $filePath = null;
        $hasFile = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasFile) {
            $up = \VC\Domain\Upload::store($_FILES['file'], 'submission');
            if (!$up['ok']) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => $up['error']]));
            }
            $fileName = $up['orig'];
            $filePath = $up['path'];
        }
        $quizQs = \VC\Domain\AssignmentQuiz::decode($asg['questions'] ?? null);
        $quizAns = [];
        if ($quizQs !== []) {
            // Tugas bentuk soal: jawaban terkunci setelah dinilai.
            $prev = \VC\Domain\Learning::getStudentSubmission($assignmentId, (int)$u['id']);
            if ($prev !== null && $prev['score'] !== null) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Jawaban sudah dinilai dan tidak bisa diubah lagi.']));
            }
            $quizAns = \VC\Domain\AssignmentQuiz::cleanAnswers($quizQs, $_POST['answers'] ?? '[]');
            $empty = count(array_filter($quizAns, static fn($a) => $a === null || $a === ''));
            if ($empty === count($quizQs)) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Jawab minimal satu soal dulu.']));
            }
        } elseif ($text === '' && $filePath === null) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Tulis jawaban atau lampirkan berkas.']));
        }

        $res = \VC\Domain\Learning::submitAssignment($assignmentId, (int)$u['id'], $text, $fileName, $filePath);
        if ($quizQs !== [] && ($res['status'] ?? '') === 'success') {
            $pgPts = \VC\Domain\AssignmentQuiz::pgPoints($quizQs, $quizAns);
            $totalPts = \VC\Domain\AssignmentQuiz::totalPoints($quizQs);
            $pdoQ = \VC\Database::get();
            $pdoQ->prepare('UPDATE submissions SET answers = ?, auto_score = ? WHERE assignment_id = ? AND student_id = ?')
                ->execute([json_encode($quizAns, JSON_UNESCAPED_UNICODE), $pgPts, $assignmentId, (int)$u['id']]);
            $res['pg_points'] = $pgPts;
            $res['total_points'] = $totalPts;
            if (\VC\Domain\AssignmentQuiz::allMultipleChoice($quizQs)) {
                // Semua pilihan ganda → nilai langsung keluar (dinilai otomatis atas nama guru).
                $final = \VC\Domain\AssignmentQuiz::scale($pgPts, $totalPts, (int)$asg['max_score'], !empty($res['is_late']), (int)$asg['late_penalty']);
                $pdoQ->prepare('UPDATE submissions SET score = ?, feedback = ?, graded_at = datetime("now"), graded_by = ? WHERE assignment_id = ? AND student_id = ?')
                    ->execute([$final, 'Dinilai otomatis: ' . $pgPts . '/' . $totalPts . ' poin pilihan ganda benar' . (!empty($res['is_late']) ? ' (penalti terlambat ' . (int)$asg['late_penalty'] . '%)' : '') . '.', (int)$asg['teacher_id'], $assignmentId, (int)$u['id']]);
                $res['score'] = $final;
                $res['message'] = 'Jawaban terkirim! Nilai kamu: ' . $final . '/' . (int)$asg['max_score'] . ' (' . $pgPts . '/' . $totalPts . ' poin).';
            } else {
                $res['message'] = 'Jawaban terkirim! Pilihan ganda: ' . $pgPts . ' poin. Essay menunggu dinilai guru.';
            }
        }
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        } else {
            \VC\Domain\Notification::push(
                (int)$asg['teacher_id'],
                'tugas',
                'Pengumpulan baru: ' . (string)$asg['title'],
                'Seorang siswa mengumpulkan tugas' . (!empty($res['is_late']) ? ' (terlambat)' : '') . '.',
                '/tugas',
                'submission:' . $assignmentId . ':' . (int)$u['id']
            );
        }
        echo json_encode($res);
        break;

    case 'api_task_submission_delete':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $assignmentId = (int)($body['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'ID tugas tidak valid.']));
        }
        $ok = \VC\Domain\Learning::deleteSubmission($assignmentId, (int)$u['id']);
        header('Content-Type: application/json');
        if ($ok) {
            echo json_encode(['status' => 'success', 'message' => 'Pengumpulan tugas berhasil ditarik / dibatalkan. Anda dapat mengirim ulang jawaban.']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Gagal menarik pengumpulan tugas (mungkin sudah dinilai oleh guru atau belum pernah dikumpulkan).']);
        }
        break;

    case 'api_material_create':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('material.create');
        $subjectId = (int)($body['subject_id'] ?? 0);
        $classId = (int)($body['class_id'] ?? 0);
        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $contentType = trim((string)($body['content_type'] ?? 'text'));
        $contentUrl = trim((string)($body['content_url'] ?? '')) ?: null;
        $isStaffAll = \VC\Security\Permissions::can('user.view');

        header('Content-Type: application/json');
        if ($subjectId <= 0 || $classId <= 0 || $title === '') {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Kelas, mata pelajaran, dan judul materi wajib diisi.']));
        }
        if (!\VC\Domain\ClassroomAccess::teacherTeachesClass((int)$u['id'], $classId, $isStaffAll)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Anda tidak mengajar kelas ini.']));
        }

        $id = (int)\VC\Domain\Learning::createMaterial(
            $subjectId,
            (int)$u['id'],
            $classId,
            $title,
            $description,
            $contentType,
            $contentUrl
        );
        if ($id <= 0) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Gagal menerbitkan materi.']));
        }
        \VC\Domain\Notification::pushMany(
            \VC\Domain\ClassroomAccess::studentsOfClass($classId),
            'materi',
            'Materi baru: ' . $title,
            'Guru telah mengunggah materi baru untuk kelas Anda.',
            '/kelas?class_id=' . $classId,
            'material:' . $id
        );
        \VC\Security\AuditLog::log('material_create', ['material_id' => $id, 'class_id' => $classId], (int)$u['id']);
        echo json_encode(['status' => 'success', 'message' => 'Materi pembelajaran berhasil diterbitkan.', 'id' => $id]);
        break;

    case 'api_material_delete':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $materialId = (int)($body['material_id'] ?? 0);
        if ($materialId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'ID materi tidak valid.']));
        }
        $isStaffAll = \VC\Security\Permissions::can('user.view');
        if (!\VC\Domain\ClassroomAccess::teacherOwnsMaterial($materialId, (int)$u['id'], $isStaffAll)) {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Anda tidak berhak menghapus materi ini.']));
        }
        $ok = \VC\Domain\Learning::deleteMaterial($materialId, (int)$u['id'], $isStaffAll);
        header('Content-Type: application/json');
        if ($ok) {
            echo json_encode(['status' => 'success', 'message' => 'Materi berhasil dihapus.']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus materi atau materi tidak ditemukan.']);
        }
        break;

    case 'api_task_submissions':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('task.grade');
        $aid = (int)($_GET['assignment_id'] ?? 0);
        if ($aid <= 0) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Parameter assignment_id wajib diisi.']));
        }
        if (!\VC\Domain\ClassroomAccess::teacherOwnsAssignment($aid, (int)$u['id'], \VC\Security\Permissions::can('user.view'))) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Anda tidak berhak menilai tugas ini.']));
        }
        echo json_encode(['submissions' => \VC\Domain\Learning::getSubmissionsForAssignment($aid)]);
        break;

    case 'api_task_grade':
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('task.grade');
        $sid = (int)($body['submission_id'] ?? 0);
        $own = \VC\Database::get()->prepare('SELECT assignment_id FROM submissions WHERE id = ?');
        $own->execute([$sid]);
        $assignmentId = (int)$own->fetchColumn();
        if ($assignmentId <= 0 || !\VC\Domain\ClassroomAccess::teacherOwnsAssignment($assignmentId, (int)$u['id'], \VC\Security\Permissions::can('user.view'))) {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Anda tidak berhak menilai pengumpulan ini.']));
        }
        // Clamp to the assignment's max score so a bad client cannot inflate grades.
        $maxStmt = \VC\Database::get()->prepare('SELECT max_score, title FROM assignments WHERE id = ?');
        $maxStmt->execute([$assignmentId]);
        $asgMeta = $maxStmt->fetch();
        $maxScore = max(1, (int)($asgMeta['max_score'] ?? 100));
        $score = max(0, min($maxScore, (int)($body['score'] ?? 0)));
        $res = \VC\Domain\Learning::grade($sid, $score, (string)($body['feedback'] ?? ''), (int)$u['id']);
        header('Content-Type: application/json');
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        } else {
            $stuStmt = \VC\Database::get()->prepare('SELECT s.student_id, a.title, a.max_score FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.id = ?');
            $stuStmt->execute([$sid]);
            $info = $stuStmt->fetch();
            if ($info) {
                \VC\Domain\Notification::push(
                    (int)$info['student_id'],
                    'tugas',
                    'Nilai keluar: ' . (string)$info['title'],
                    'Nilaimu ' . $score . '/' . (int)$info['max_score'] . '.',
                    '/tugas',
                    'grade:' . $sid
                );
            }
        }
        echo json_encode($res);
        break;

    case 'api_gradebook':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $classId = (int)($_GET['class_id'] ?? 0);
        // Students may only read their own class's gradebook; staff may read any.
        if (!\VC\Domain\ClassroomAccess::canViewGradebook($classId, (int)$u['id'], (int)($u['class_id'] ?? 0), \VC\Security\Permissions::can('task.grade'))) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Anda hanya bisa melihat buku nilai kelas Anda.']));
        }
        echo json_encode(\VC\Domain\Learning::gradebook($classId));
        break;

    case 'api_task_file':
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit('Login diperlukan.');
        }
        $sid = (int)($_GET['id'] ?? 0);
        $stmt = \VC\Database::get()->prepare('SELECT student_id, assignment_id, file_path, file_name FROM submissions WHERE id = ?');
        $stmt->execute([$sid]);
        $sub = $stmt->fetch();
        if (!$sub || empty($sub['file_path'])) {
            http_response_code(404);
            exit('Berkas tidak ditemukan.');
        }
        $isOwner = (int)$sub['student_id'] === (int)$u['id'];
        $isGrader = \VC\Security\Permissions::can('task.grade')
            && \VC\Domain\ClassroomAccess::teacherOwnsAssignment((int)$sub['assignment_id'], (int)$u['id'], \VC\Security\Permissions::can('user.view'));
        if (!$isOwner && !$isGrader) {
            http_response_code(403);
            exit('Tidak berhak mengakses berkas ini.');
        }
        $path = \VC\Domain\Upload::resolve((string)$sub['file_path']);
        if ($path === null) {
            http_response_code(404);
            exit('Berkas tidak ditemukan.');
        }
        $mime = \VC\Domain\Upload::mimeOf($path);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\- ]/', '_', (string)$sub['file_name']) . '"');
        readfile($path);
        break;

    case 'api_servers':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $reqWorld = isset($_GET['world']) ? (string)$_GET['world'] : null; // 3d | pixel (opsional)
        echo json_encode(['status' => 'ok', 'servers' => \VC\Domain\GameServers::listFor((int)$u['id'], (string)$u['role'], $reqWorld)]);
        break;

    case 'api_servers_create':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $res = \VC\Domain\GameServers::create((int)$u['id'], $body);
        if (($res['status'] ?? '') === 'error') {
            http_response_code((int)($res['http'] ?? 400));
            unset($res['http']);
        }
        echo json_encode($res);
        break;

    case 'api_guest_join':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $cur = Auth::user();
        if ($cur !== null && ($cur['role'] ?? '') !== 'tamu') {
            // Sudah login akun sekolah: pakai alur kode biasa, identitas resmi tetap dipakai.
            $res = \VC\Domain\GameServers::joinByCode((int)$cur['id'], (string)($body['code'] ?? ''));
        } else {
            $wait = RateLimiter::hit('guest_ip', Ip::bucket());
            if ($wait > 0) {
                $sec = (int)ceil($wait / 1000); // hit() mengembalikan milidetik
                http_response_code(429);
                header('Retry-After: ' . $sec);
                exit(json_encode(['status' => 'error', 'message' => 'Terlalu banyak percobaan. Coba lagi ' . max(1, (int)ceil($sec / 60)) . ' menit lagi.']));
            }
            $res = \VC\Domain\GameServers::guestJoin((string)($body['code'] ?? ''), (string)($body['name'] ?? ''), $cur ? (int)$cur['id'] : null);
            if (($res['status'] ?? '') === 'ok' && $cur === null) {
                Auth::regenerate();
                $_SESSION['user_id'] = (int)$res['user_id'];
                \VC\Security\AuditLog::log('guest_join', ['server_id' => $res['server_id']], (int)$res['user_id'], 'ok');
            }
            unset($res['user_id']);
        }
        if (($res['status'] ?? '') === 'error') {
            http_response_code((int)($res['http'] ?? 400));
            unset($res['http']);
        } else {
            // Satu server = satu dunia → arahkan ke /dunia atau /pixel sesuai server.
            $res['redirect'] = \VC\Domain\GameServers::worldUrl((string)($res['world'] ?? '3d'), (int)$res['server_id']);
        }
        echo json_encode($res);
        break;

    case 'api_servers_join':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $sid = (int)($body['server_id'] ?? 0);
        $code = (string)($body['code'] ?? '');
        if ($sid <= 0 && $code !== '') {
            $res = \VC\Domain\GameServers::joinByCode((int)$u['id'], $code);
        } else {
            $res = \VC\Domain\GameServers::join((int)$u['id'], $sid, $code);
        }
        if (($res['status'] ?? '') === 'error') {
            http_response_code((int)($res['http'] ?? 400));
            unset($res['http']);
        } elseif (isset($res['server_id'])) {
            $res['redirect'] = \VC\Domain\GameServers::worldUrl((string)($res['world'] ?? '3d'), (int)$res['server_id']);
        }
        echo json_encode($res);
        break;

    case 'api_servers_update':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        if (!in_array($u['role'] ?? '', ['root', 'kepala_sekolah', 'guru', 'wali_kelas', 'kepala_program', 'pembina', 'pengurus'], true)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya staf & admin yang dapat mengedit server.']));
        }
        $res = \VC\Domain\GameServers::updateServer((int)($body['server_id'] ?? 0), $body, $u);
        if (($res['status'] ?? '') === 'error') {
            http_response_code((int)($res['http'] ?? 400));
            unset($res['http']);
        }
        echo json_encode($res);
        break;

    case 'api_servers_delete':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        if (!in_array($u['role'] ?? '', ['root', 'kepala_sekolah', 'guru', 'wali_kelas', 'kepala_program', 'pembina', 'pengurus'], true)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya staf & admin yang dapat menghapus server.']));
        }
        $res = \VC\Domain\GameServers::deleteServer((int)($body['server_id'] ?? 0), $u);
        if (($res['status'] ?? '') === 'error') {
            http_response_code((int)($res['http'] ?? 400));
            unset($res['http']);
        }
        echo json_encode($res);
        break;

    case 'api_servers_admin_list':
        header('Content-Type: application/json');
        Csrf::check($_SERVER['HTTP_X_CSRF'] ?? ($_GET['_csrf'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        if (($u['role'] ?? '') !== 'root') {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT.']));
        }
        echo json_encode(['status' => 'ok', 'servers' => \VC\Domain\GameServers::adminListAll()]);
        break;

    case 'api_events_create':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        if (!in_array($u['role'] ?? '', ['root', 'guru', 'wali_kelas', 'kepala_program', 'kepala_sekolah'], true)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT dan staf yang dapat membuat event.']));
        }
        $title = trim((string)($body['title'] ?? ''));
        $desc = trim((string)($body['description'] ?? ''));
        $type = trim((string)($body['event_type'] ?? 'activity'));
        $location = trim((string)($body['map_location'] ?? ''));
        $start = trim((string)($body['start_time'] ?? ''));
        $end = trim((string)($body['end_time'] ?? ''));
        $quota = max(1, (int)($body['quota'] ?? 100));
        $xp = max(0, (int)($body['rewards_xp'] ?? 50));
        $coins = max(0, (int)($body['rewards_coins'] ?? 25));

        if ($title === '') {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Judul event wajib diisi.']));
        }
        if ($start === '' || $end === '') {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Waktu mulai dan selesai wajib diisi.']));
        }

        // Normalisasi format datetime-local (YYYY-MM-DDTHH:MM -> YYYY-MM-DD HH:MM:SS)
        $start = str_replace('T', ' ', $start);
        if (strlen($start) === 16) $start .= ':00';
        $end = str_replace('T', ' ', $end);
        if (strlen($end) === 16) $end .= ':00';

        try {
            $eventId = \VC\Domain\Events::createEvent($title, $desc, $type, $location ?: null, $start, $end, $quota, $xp, $coins, (int)$u['id']);
            echo json_encode(['status' => 'ok', 'message' => 'Event berhasil diterbitkan!', 'event_id' => $eventId]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Gagal membuat event: ' . $e->getMessage()]);
        }
        break;

    case 'api_events_delete':
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        if (!in_array($u['role'] ?? '', ['root', 'guru', 'wali_kelas', 'kepala_program', 'kepala_sekolah'], true)) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT dan staf yang dapat menghapus event.']));
        }
        $eventId = (int)($body['event_id'] ?? 0);
        if ($eventId <= 0) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'ID event tidak valid.']));
        }
        $ok = \VC\Domain\Events::deleteEvent($eventId, (int)$u['id']);
        if ($ok) {
            echo json_encode(['status' => 'ok', 'message' => 'Event berhasil dihapus!']);
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Event tidak ditemukan atau sudah dihapus.']);
        }
        break;

    case 'api_ws_token':
        header('Content-Type: application/json');
        Csrf::check($_GET['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        // Dunia klien (3d | pixel). Satu server hanya untuk satu dunia: token ditolak (tanpa
        // reconnect) bila server milik dunia lain, beserta alamat dunia yang benar.
        $reqWorld = isset($_GET['world']) ? (((string)$_GET['world'] === 'pixel') ? 'pixel' : '3d') : null;
        $serverId = 1;
        if ($reqWorld === 'pixel') {
            $serverId = (int)(\VC\Database::get()->query('SELECT id FROM game_servers WHERE world = "pixel" AND type = "public" AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
        }
        if (isset($_GET['server'])) {
            $reqServer = (int)$_GET['server'];
            $code = (string)($_GET['code'] ?? '');
            if ($reqServer <= 0 && $code !== '') {
                $chk = \VC\Domain\GameServers::joinByCode((int)$u['id'], $code);
                if (($chk['status'] ?? '') === 'error') {
                    http_response_code((int)($chk['http'] ?? 400));
                    exit(json_encode(['status' => 'error', 'message' => $chk['message'] ?? 'Gagal bergabung server.']));
                }
                $serverId = (int)($chk['server_id'] ?? 1);
            } else {
                $chk = \VC\Domain\GameServers::join((int)$u['id'], $reqServer, $code);
                if (($chk['status'] ?? '') === 'error') {
                    http_response_code((int)($chk['http'] ?? 400));
                    exit(json_encode(['status' => 'error', 'message' => $chk['message'] ?? 'Gagal bergabung server.']));
                }
                $serverId = $reqServer;
            }
        }
        if ($reqWorld !== null) {
            $srvWorld = \VC\Domain\GameServers::worldOfId($serverId);
            if ($srvWorld !== null && $srvWorld !== $reqWorld) {
                http_response_code(409);
                exit(json_encode([
                    'status' => 'error', 'code' => 'wrong_world', 'world' => $srvWorld, 'server_id' => $serverId,
                    'redirect' => \VC\Domain\GameServers::worldUrl($srvWorld, $serverId),
                    'message' => 'Server ini khusus ' . ($srvWorld === 'pixel' ? 'Dunia Pixel' : 'Dunia 3D') . '. Buka dari halaman ' . ($srvWorld === 'pixel' ? '/pixel' : '/dunia') . '.',
                ]));
            }
        }
        $token = Auth::createWsToken($u, $serverId);
        $wsPort = 8090;
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $hostWithoutPort = explode(':', $host)[0];
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        // Port dari header Host lebih dulu: di Docker Apache mendengar :80 tapi dibuka lewat localhost:8080.
        $portPart = preg_match('/:(\d+)$/', (string)($_SERVER['HTTP_HOST'] ?? ''), $hostPortMatch)
            ? (in_array((int)$hostPortMatch[1], [80, 443], true) ? '' : ':' . $hostPortMatch[1])
            : ((!empty($_SERVER['SERVER_PORT']) && !in_array((int)$_SERVER['SERVER_PORT'], [80, 443], true)) ? ':' . $_SERVER['SERVER_PORT'] : '');
        $proxiedWsUrl = ($isHttps ? 'wss://' : 'ws://') . $hostWithoutPort . $portPart . '/ws/?token=' . urlencode($token);
        $directWsUrl = ($isHttps ? 'wss://' : 'ws://') . $hostWithoutPort . ':' . $wsPort . '/?token=' . urlencode($token);
        echo json_encode([
            'status' => 'ok',
            'server_id' => $serverId,
            'token'  => $token,
            'url'    => $proxiedWsUrl,
            'direct_url' => $directWsUrl,
            'fallback_polling' => '/api/multiplayer/sync',
        ]);
        break;

    case 'api_multiplayer_sync':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $x = (float)($body['x'] ?? 160);
        $y = (float)($body['y'] ?? 0);
        $z = (float)($body['z'] ?? 12);
        $floor = (int)($body['floor'] ?? 1);
        $now = time();

        try {
            $pdo = \VC\Database::get();
            $myAvatar = \VC\Domain\Gameplay::getActiveAvatar((int)$u['id']);
            $avatarJson = json_encode($myAvatar);

            $stmt = $pdo->prepare("INSERT INTO active_players (user_id, username, full_name, role, x, y, z, floor, avatar_config, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(user_id) DO UPDATE SET
                    x = excluded.x, y = excluded.y, z = excluded.z, floor = excluded.floor, avatar_config = excluded.avatar_config, updated_at = excluded.updated_at");
            $stmt->execute([(int)$u['id'], (string)$u['username'], (string)$u['full_name'], (string)$u['role'], $x, $y, $z, $floor, $avatarJson, $now]);

            $pdo->exec("DELETE FROM active_players WHERE updated_at < " . ($now - 15));

            $listStmt = $pdo->prepare("SELECT user_id as id, username, full_name as fullName, role, x, y, z, floor, avatar_config as avatarConfig FROM active_players WHERE user_id != ? AND floor = ? LIMIT 50");
            $listStmt->execute([(int)$u['id'], $floor]);
            $others = $listStmt->fetchAll();
            foreach ($others as &$oth) {
                if (!empty($oth['avatarConfig']) && is_string($oth['avatarConfig'])) {
                    $oth['avatarConfig'] = json_decode($oth['avatarConfig'], true);
                }
            }
            unset($oth);
        } catch (\Throwable $e) {
            $others = [];
        }

        echo json_encode([
            'status' => 'ok',
            'players' => $others,
        ]);
        break;

    case 'api_player_profile':
        header('Content-Type: application/json');
        Csrf::check($_GET['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ($_SESSION['csrf'] ?? null)));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $targetId = (int)($_GET['target_id'] ?? $_GET['id'] ?? 0);
        if ($targetId <= 0) {
            $targetId = (int)$u['id'];
        }
        try {
            $card = \VC\Domain\Social::getIdCardData($targetId, (int)$u['id']);
            $prog = \VC\Domain\Gameplay::getProgress($targetId);
            $badges = \VC\Domain\Gameplay::getAllBadges($targetId);
            $earnedBadges = array_filter($badges, fn($b) => !empty($b['is_earned']));

            echo json_encode([
                'status' => 'ok',
                'profile' => [
                    'id' => $targetId,
                    'username' => $card['username'] ?? '',
                    'full_name' => $card['full_name'] ?? $card['username'] ?? '',
                    'role' => $card['role'] ?? 'murid',
                    'nis' => $card['nis'] ?? '-',
                    'class_name' => $card['class_name'] ?? 'Siswa SMK Silber',
                    'program_name' => $card['program_name'] ?? 'Kejuruan',
                    'status_text' => $card['status_text'] ?? 'Belajar di SMK Silber',
                    'bio' => $card['bio'] ?? '',
                    'avatar_color' => $card['avatar_color'] ?? '#22d3ee',
                    'level' => (int)($prog['level'] ?? 1),
                    'total_xp' => (int)($prog['total_xp'] ?? 0),
                    'coins' => (int)($prog['coins'] ?? 0),
                    'badges_count' => count($earnedBadges),
                    'is_self' => ($targetId === (int)$u['id']),
                ]
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal memuat profil pemain: ' . $e->getMessage()]);
        }
        break;

    case 'api_player_nearby':
        header('Content-Type: application/json');
        Csrf::check($_GET['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ($_SESSION['csrf'] ?? null)));
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $floor = (int)($_GET['floor'] ?? 1);
        $curX = (float)($_GET['x'] ?? 135);
        $curZ = (float)($_GET['z'] ?? 12);
        try {
            $pdo = \VC\Database::get();
            $stmt = $pdo->prepare("SELECT user_id as id, username, full_name as fullName, role, x, y, z, floor, avatar_config as avatarConfig, updated_at
                FROM active_players
                WHERE user_id != ? AND floor = ? AND updated_at >= ?
                ORDER BY updated_at DESC LIMIT 30");
            $stmt->execute([(int)$u['id'], $floor, time() - 30]);
            $list = $stmt->fetchAll();
            $nearby = [];
            foreach ($list as $p) {
                $dx = (float)$p['x'] - $curX;
                $dz = (float)$p['z'] - $curZ;
                $dist = round(sqrt($dx * $dx + $dz * $dz), 1);
                $cfg = !empty($p['avatarConfig']) ? (is_string($p['avatarConfig']) ? json_decode($p['avatarConfig'], true) : $p['avatarConfig']) : null;
                $nearby[] = [
                    'id' => (int)$p['id'],
                    'username' => (string)$p['username'],
                    'fullName' => (string)($p['fullName'] ?: $p['username']),
                    'role' => (string)$p['role'],
                    'x' => (float)$p['x'],
                    'y' => (float)$p['y'],
                    'z' => (float)$p['z'],
                    'floor' => (int)$p['floor'],
                    'distance' => $dist,
                    'avatarConfig' => $cfg
                ];
            }
            usort($nearby, fn($a, $b) => $a['distance'] <=> $b['distance']);
            echo json_encode(['status' => 'ok', 'nearby' => $nearby]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil data sekitar.']);
        }
        break;

    case 'api_player_interact':
        header('Content-Type: application/json');
        $u = Auth::user();
        if ($u === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $action = trim((string)($body['action'] ?? 'wave'));
        $targetId = (int)($body['target_id'] ?? 0);
        $targetUsername = trim((string)($body['target_username'] ?? ''));

        try {
            $pdo = \VC\Database::get();
            if ($targetId <= 0 && $targetUsername !== '') {
                $uStmt = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR nis = ?) AND deleted_at IS NULL');
                $uStmt->execute([$targetUsername, $targetUsername]);
                $targetId = (int)$uStmt->fetchColumn();
            }

            if ($targetId <= 0) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Target pemain atau NIS tidak valid atau tidak ditemukan.']));
            }

            $tStmt = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id = ? AND deleted_at IS NULL');
            $tStmt->execute([$targetId]);
            $targetUser = $tStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$targetUser) {
                exit(json_encode(['status' => 'error', 'message' => 'Pemain target tidak ditemukan.']));
            }
            $targetName = $targetUser['full_name'] ?: $targetUser['username'];
            $myName = $u['full_name'] ?: $u['username'];

            switch ($action) {
                case 'wave':
                    \VC\Security\AuditLog::log('player_interact_wave', ['target_id' => $targetId], (int)$u['id']);
                    echo json_encode([
                        'status' => 'ok',
                        'action' => 'wave',
                        'message' => "Anda melambaikan tangan menyapa {$targetName}! 👋",
                        'target_name' => $targetName
                    ]);
                    break;

                case 'tip_coins':
                    $amount = (int)($body['amount'] ?? 5);
                    $note = trim((string)($body['note'] ?? 'Tip apresiasi di Dunia 3D SMK Silber'));
                    $res = \VC\Domain\Gameplay::transferCoins((int)$u['id'], $targetId, $amount, $note);
                    echo json_encode($res);
                    break;

                case 'friend_request':
                    $fRes = \VC\Domain\Social::sendFriendRequest((int)$u['id'], $targetId);
                    echo json_encode($fRes);
                    break;

                case 'invite_mission':
                    $missionTitle = trim((string)($body['mission_title'] ?? 'Misi Eksplorasi Bersama'));
                    $text = "🎯 [Undangan Misi] {$myName} mengajak {$targetName} untuk kerja sama menyelesaikan {$missionTitle}!";
                    \VC\Domain\Social::sendMessage(2, (int)$u['id'], $text);
                    echo json_encode([
                        'status' => 'ok',
                        'action' => 'invite_mission',
                        'message' => "Undangan misi bersama berhasil dikirim ke {$targetName}! 🎯"
                    ]);
                    break;

                default:
                    echo json_encode(['status' => 'error', 'message' => 'Aksi interaksi tidak dikenali.']);
                    break;
            }
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan interaksi: ' . $e->getMessage()]);
        }
        break;

    case 'api_ai_ask':
        header('Content-Type: application/json; charset=utf-8');
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $question = trim((string)($body['question'] ?? ''));
        $context = trim((string)($body['context'] ?? ''));

        // Konteks nyata: daftar misi user yang belum selesai, supaya Si Silber bisa
        // memberi petunjuk langkah demi langkah dan membantu menyelesaikan misi.
        $aiUser = Auth::user();
        if ($aiUser !== null) {
            try {
                $aiMissions = \VC\Domain\Gameplay::getMissions((int)$aiUser['id']);
                $aiLines = [];
                foreach ($aiMissions as $am) {
                    if (($am['user_status'] ?? '') === 'completed') {
                        continue;
                    }
                    $aiLines[] = '- ' . (string)($am['title'] ?? '')
                        . ' | tipe: ' . (string)($am['target_type'] ?? '-')
                        . ' | target: ' . (string)($am['target_value'] ?? '-')
                        . ' | XP +' . (int)($am['xp_reward'] ?? 0)
                        . ' | koin +' . (int)($am['coin_reward'] ?? 0)
                        . ' | status: ' . (string)($am['user_status'] ?? 'not_started');
                }
                if ($aiLines) {
                    $context .= "\n\nMISI USER " . (string)$aiUser['username'] . " yang belum selesai:\n"
                        . implode("\n", array_slice($aiLines, 0, 8))
                        . "\nBila ditanya soal misi/petunjuk/penyelesaian: jawab langkah konkret (lokasi, gedung, lantai, tombol) memakai data di atas, lalu arahkan menekan tombol misi di /misi atau tombol [E] di lokasi.";
                } else {
                    $context .= "\nSemua misi user sudah selesai. Tawarkan cek /misi untuk misi harian baru dan /peringkat untuk papan peringkat.";
                }
            } catch (\Throwable $e) {
                // AI tetap jalan tanpa konteks misi.
            }
        }

        if ($question === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Pertanyaan wajib diisi.']);
            break;
        }

        $res = \VC\Ai\AiClient::ask($question, $context);
        echo json_encode([
            'status' => $res['ok'] ? 'ok' : 'error',
            'reply' => $res['reply'] ?? ($res['message'] ?? 'Maaf, terjadi gangguan.'),
            'provider' => $res['provider_name'] ?? 'Si Silber AI',
            'model' => $res['model'] ?? 'v5'
        ]);
        break;

    // ---- AI Orchestrator (Agent 3): enqueue / inspect / cancel the three agent roles ----
    case 'api_ai_job_create':
        header('Content-Type: application/json; charset=utf-8');
        $me = Auth::user();
        if ($me === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('ai.orchestrate');
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $res = \VC\Ai\Orchestrator::enqueue(
            (string)($body['role'] ?? ''),
            (string)($body['task'] ?? ''),
            [
                'input' => $body['input'] ?? null,
                'dedupe_key' => $body['dedupe_key'] ?? null,
                'requested_by' => (int)$me['id'],
            ]
        );
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'api_ai_job_get':
        header('Content-Type: application/json; charset=utf-8');
        Csrf::check($_GET['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $me = Auth::user();
        if ($me === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $jobId = (string)($_GET['id'] ?? '');
        $job = $jobId !== '' ? \VC\Ai\Orchestrator::get($jobId) : null;
        if ($job === null) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Job tidak ditemukan.']));
        }
        // Staff with ai.view may read any job; a creator may read only their own.
        $isOwner = (int)($job['requested_by'] ?? 0) === (int)$me['id'];
        if (!\VC\Security\Permissions::can('ai.view') && !$isOwner) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Tidak berhak melihat job ini.']));
        }
        echo json_encode(['status' => 'ok', 'job' => $job, 'events' => \VC\Ai\Orchestrator::events($jobId, 50)]);
        break;

    case 'api_ai_jobs':
        header('Content-Type: application/json; charset=utf-8');
        Csrf::check($_GET['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $me = Auth::user();
        if ($me === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('ai.view');
        echo json_encode([
            'status' => 'ok',
            'stats' => \VC\Ai\Orchestrator::stats(),
            'jobs' => \VC\Ai\Orchestrator::list(
                (int)($_GET['limit'] ?? 50),
                isset($_GET['status']) ? (string)$_GET['status'] : null,
                isset($_GET['role']) ? (string)$_GET['role'] : null
            ),
        ]);
        break;

    case 'api_ai_job_cancel':
        header('Content-Type: application/json; charset=utf-8');
        $me = Auth::user();
        if ($me === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        \VC\Security\Permissions::require('ai.orchestrate');
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $res = \VC\Ai\Orchestrator::cancel((string)($body['id'] ?? ''), (int)$me['id']);
        if (($res['status'] ?? '') === 'error') {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    // ---- Telegram account linking (only a logged-in user may mint a code) ----
    case 'api_telegram_link_code':
        header('Content-Type: application/json; charset=utf-8');
        $me = Auth::user();
        if ($me === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $code = \VC\TgBot\AccountLink::issueCode((int)$me['id']);
        \VC\Security\SecurityLog::write('tg_link_code_issued', ['user' => (int)$me['id']]);
        echo json_encode([
            'status' => 'ok',
            'code' => $code['code'],
            'expires_in' => $code['ttl'],
            'bot_username' => (string)\vc_env('TELEGRAM_BOT_USERNAME', ''),
        ]);
        break;

    case 'api_telegram_unlink':
        header('Content-Type: application/json; charset=utf-8');
        $me = Auth::user();
        if ($me === null) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Login diperlukan.']));
        }
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));
        $ok = \VC\TgBot\AccountLink::unlinkByUser((int)$me['id']);
        echo json_encode(['status' => 'ok', 'unlinked' => $ok]);
        break;

}
