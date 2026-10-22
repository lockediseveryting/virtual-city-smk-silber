<?php
// AdminController: Secure Admin Panel & Root Console with strict RBAC, Security Center, Approval Queue & System Monitor.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Database;
use VC\Security\AuditLog;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Footer;
use VC\Security\Headers;
use VC\Security\Permissions;
use VC\Security\Pwa;
use VC\Security\SecurityLog;
use VC\View\PixelDashboard;
use PDO;

final class AdminController
{
    private const STAFF_ROLES = ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'];

    /** Render Admin & Root Management Console (/admin) */
    public static function render(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();

        // 1. Strict Authentication Check
        if ($user === null) {
            header('Location: /login?admin=1&redirect=/admin');
            exit;
        }

        // 2. Strict Role-Based Authorization Check (RBAC)
        if (!in_array($user['role'], self::STAFF_ROLES, true)) {
            http_response_code(403);
            echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
            echo '<title>403 Akses Ditolak — Admin SMK Silber</title>';
            echo '<style nonce="' . $nonce . '">
body { font-family: system-ui, sans-serif; background: #090d16; color: #f87171; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
.box { background: #131a29; border: 1px solid #ef4444; border-radius: 14px; padding: 32px; max-width: 480px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.8); }
h1 { margin: 0 0 12px 0; color: #fff; font-size: 1.4rem; }
p { color: #cbd5e1; font-size: 0.95rem; margin-bottom: 20px; line-height: 1.5; }
a { background: #22d3ee; color: #000; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block; }
</style></head><body>';
            echo '<div class="box"><h1>🚫 403 Akses Terlarang</h1>';
            echo '<p>Area Panel Admin ini khusus untuk Guru, Wali Kelas, Kepala Program, dan Administrator ROOT. Akun Anda (<strong>' . htmlspecialchars($user['username'], ENT_QUOTES) . '</strong>) tidak memiliki izin yang cukup.</p>';
            echo '<a href="/">← Kembali ke Beranda Siswa</a></div></body></html>';
            exit;
        }

        $pdo = Database::get();
        $secDb = Database::security();
        $csrf = Csrf::token();

        // Metrics
        $totalStudents = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "murid" AND deleted_at IS NULL')->fetchColumn();
        $totalStaff = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("guru", "wali_kelas", "kepala_program", "root") AND deleted_at IS NULL')->fetchColumn();
        $pendingRegs = $pdo->query('SELECT r.*, c.name AS class_name FROM registrations r LEFT JOIN classes c ON r.class_id = c.id WHERE r.status = "pending" ORDER BY r.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        // expires_at/created_at are unix timestamps (see Database::ensureSecuritySchema).
        $nowTs = time();
        $totalBlockedIPs = (int)$secDb->query('SELECT COUNT(*) FROM ip_blocks WHERE expires_at IS NULL OR expires_at > ' . $nowTs)->fetchColumn();
        $recentBlocks = $secDb->query('SELECT * FROM ip_blocks ORDER BY created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
        // Statistik live multiplayer via GameServers::liveCounts() (presentasi kartu ringkasan).
        $liveCounts = \VC\Domain\GameServers::liveCounts();
        $playersOnline = array_sum($liveCounts);
        $serversOnline = count(array_filter($liveCounts, fn($n) => $n > 0));
        $totalClasses = (int)$pdo->query('SELECT COUNT(*) FROM classes WHERE deleted_at IS NULL')->fetchColumn();

        // Security Audit Log verification check
        $auditIntegrityBroken = AuditLog::verify();
        $isAuditSafe = ($auditIntegrityBroken === 0);

        $isRoot = ($user['role'] === 'root');
        $allUsers = [];
        if ($isRoot) {
            $allUsers = $pdo->query('SELECT u.id, u.username, u.full_name, u.role, u.status, COALESCE(p.coins, 0) AS coins, COALESCE(p.level, 1) AS level, tl.tg_user_id
                FROM users u
                LEFT JOIN user_progress p ON u.id = p.user_id
                LEFT JOIN telegram_links tl ON u.id = tl.user_id
                WHERE u.deleted_at IS NULL
                ORDER BY CASE u.role 
                    WHEN "root" THEN 1 
                    WHEN "kepala_sekolah" THEN 2 
                    WHEN "kepala_program" THEN 3 
                    WHEN "guru" THEN 4 
                    WHEN "wali_kelas" THEN 5 
                    ELSE 6 END, u.id ASC
                LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
        }

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo Pwa::headTags();
        echo '<title>' . ($isRoot ? '👑 Konsol Supreme ROOT' : 'Panel Staf & Guru') . ' — SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
/* ====== Design System 4 Warna (Hitam #0A0E17 / Biru #1D4ED8-#2563EB / Oranye #F97316-#F59E0B / Putih #FFFFFF) ====== */
:root {
  --hitam: #0A0E17;
  --biru: #1D4ED8;
  --biru-terang: #2563EB;
  --oranye: #F97316;
  --oranye-terang: #F59E0B;
  --putih: #FFFFFF;
}
* { box-sizing: border-box; }
html, body { max-width: 100%; overflow-x: hidden; }
body {
  font-family: system-ui, -apple-system, sans-serif;
  background-color: var(--hitam);
  background-image:
    radial-gradient(ellipse 700px 420px at 100% -8%, rgba(249,115,22,0.35), transparent 65%),
    radial-gradient(ellipse 900px 560px at -10% 0%, rgba(29,78,216,0.55), transparent 70%),
    linear-gradient(160deg, #0A0E17 0%, #101b3a 55%, #14256b 100%);
  background-attachment: fixed;
  color: #E7ECF7;
  margin: 0;
  padding: 0 0 calc(76px + env(safe-area-inset-bottom));
}
.container { max-width: 1100px; margin: 0 auto; padding: 14px; }
/* Sticky topbar: avatar inisial + nama user */
.topbar-ad {
  position: sticky; top: 0; z-index: 50;
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; padding-top: calc(10px + env(safe-area-inset-top));
  background: rgba(10,14,23,0.92); backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(37,99,235,0.35);
}
.topbar-ad__avatar {
  width: 40px; height: 40px; min-width: 40px; border-radius: 12px;
  display: inline-flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, var(--oranye), var(--oranye-terang));
  color: var(--putih); font-weight: 900; font-size: 1rem;
  box-shadow: 0 4px 14px rgba(249,115,22,0.4);
}
.topbar-ad__name { color: var(--putih); font-weight: 800; font-size: 0.95rem; line-height: 1.2; }
.topbar-ad__sub { color: #F59E0B; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.4px; }
.topbar-ad__actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }
.badge-role { background: rgba(249,115,22,0.18); color: #FDBA74; padding: 5px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 800; border: 1px solid var(--oranye); }
.badge-root { background: linear-gradient(135deg, var(--oranye), #ea580c); color: var(--putih); border: 1px solid #F59E0B; }
/* Statistik hero: kartu putih, angka besar */
.grid-stats { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
@media (min-width: 900px) { .grid-stats { grid-template-columns: repeat(3, 1fr); } } /* 6 kartu = 3 × 2 (simetris) */
@media (min-width: 900px) { .grid-stats--4 { grid-template-columns: repeat(4, 1fr); } }
.card-stat { background: var(--putih); color: #0A0E17; border: none; border-radius: 16px; padding: 18px; box-shadow: 0 8px 24px rgba(0,0,0,0.35); transition: transform 0.2s; }
.card-stat:hover { transform: translateY(-2px); }
.stat-val { font-size: 2rem; font-weight: 900; color: var(--biru); margin: 6px 0; letter-spacing: -0.5px; }
.stat-lbl { font-size: 0.72rem; color: #4B5563; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; }
.stat-sub { font-size: 0.75rem; color: #6B7280; }
.section-title { font-size: 1.05rem; color: var(--putih); margin: 26px 0 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px; border-left: 4px solid var(--oranye); padding-left: 10px; }
.card { background: var(--putih); color: #0A0E17; border: none; border-radius: 16px; padding: 18px; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.3); }
.card .muted, .card p { color: #4B5563; }
/* Tabel dalam kartu putih: teks gelap + scroll horizontal di mobile */
.table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid rgba(37,99,235,0.2); }
.table-responsive::-webkit-scrollbar { height: 6px; }
.table-responsive::-webkit-scrollbar-thumb { background: rgba(37,99,235,0.4); border-radius: 3px; }
table { width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #111827; min-width: 640px; }
th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #E5E7EB; white-space: nowrap; }
th { color: #4B5563; font-weight: 700; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px; }
/* Tombol: CTA utama oranye, link/fokus biru, target sentuh >= 44px */
.btn { background: linear-gradient(135deg, var(--oranye), #ea580c); color: var(--putih); padding: 11px 16px; min-height: 44px; border-radius: 12px; font-weight: 800; border: none; cursor: pointer; font-size: 0.85rem; box-shadow: 0 4px 14px rgba(249,115,22,0.35); }
.btn:hover { transform: translateY(-1px); }
.btn-blue { background: linear-gradient(135deg, var(--biru), var(--biru-terang)); box-shadow: 0 4px 14px rgba(29,78,216,0.35); }
.btn-red { background: #dc2626; color: var(--putih); box-shadow: none; }
.btn-sec { background: var(--putih); color: var(--biru); border: 2px solid var(--biru); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; min-height: 44px; border-radius: 12px; font-weight: 700; font-size: 0.85rem; transition: all 0.2s; }
.btn-sec:hover { background: var(--biru); color: var(--putih); }
a:focus-visible, button:focus-visible { outline: 3px solid var(--biru-terang); outline-offset: 2px; }
.audit-status { padding: 12px 16px; border-radius: 12px; font-weight: 600; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; }
.audit-ok { background: #ECFDF5; border: 1px solid #10b981; color: #047857; }
.audit-fail { background: #FEF2F2; border: 1px solid #ef4444; color: #b91c1c; }
.select-dark { background: #F3F4F6; color: #0A0E17; border: 1px solid #D1D5DB; border-radius: 10px; padding: 9px 10px; font-size: 0.82rem; min-height: 44px; }
input, textarea { font-family: inherit; }
/* Netralkan input gelap inline di dalam kartu putih */
.card input, .card select, .card textarea { background: #F3F4F6 !important; color: #0A0E17 !important; border-color: #D1D5DB !important; }
/* Grid tombol aksi admin */
.action-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 24px; }
@media (min-width: 900px) { .action-grid { grid-template-columns: repeat(4, 1fr); } }
.action-grid .btn-sec { justify-content: center; }
/* Bottom navigation mobile (Beranda/Dunia/Server/Siswa/Profil) */
.bottomnav {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 60;
  display: grid; grid-template-columns: repeat(5, 1fr);
  background: rgba(10,14,23,0.96); backdrop-filter: blur(12px);
  border-top: 1px solid rgba(37,99,235,0.35);
  padding: 6px 4px calc(6px + env(safe-area-inset-bottom));
}
.bottomnav a {
  min-height: 48px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
  color: #93A3C8; text-decoration: none; font-size: 0.65rem; font-weight: 700; border-radius: 12px;
}
.bottomnav a span:first-child { font-size: 1.25rem; }
.bottomnav a.is-active { color: var(--oranye); background: rgba(249,115,22,0.12); }
@media (min-width: 900px) { .bottomnav { display: none; } body { padding-bottom: 20px; } }
@media (max-width: 480px) {
  .topbar-ad { padding: 8px 10px; gap: 8px; }
  .topbar-ad__name { max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.82rem; }
  .topbar-ad__actions { gap: 4px; }
  .topbar-ad__actions .btn { padding: 6px 10px; min-height: 36px; font-size: 0.78rem; }
  .grid-stats { grid-template-columns: 1fr 1fr; }
  .stat-val { font-size: 1.5rem; }
  .card { padding: 14px; }
  table { font-size: 0.78rem; }
}
</style></head><body class="px-admin">';
        // Hero dashboard game: kota pixel-art + karakter + menu papan kayu (panel lengkap di bawahnya).
        echo PixelDashboard::CSS;
        $adminMenu = [
            ['#ringkasan', '📊', 'Ringkasan', 'Statistik sekolah', 'gold'],
            ['#server-section', '🎮', 'Server Kelas', 'Buat & kelola room'],
            ['/kumpul', '📢', 'Jadwal Kumpul', 'Jam & ruang per server'],
            ['/kelas', '📝', 'Tugas & Soal', 'PG + essay, nilai otomatis'],
        ];
        if ($isRoot) {
            $adminMenu[] = ['#siswa', '👥', 'Pengguna', 'Staf & akun siswa'];
        }
        $adminMenu[] = ['/admin-game', '🎯', 'Misi & Chat', 'Misi in-game & moderasi'];
        if (Permissions::can('ai.view')) {
            $adminMenu[] = ['/admin/ai', '🧠', 'Tugas AI', 'Antrean job AI'];
        }
        $adminMenu[] = ['/dunia', '🌐', 'Dunia 3D', 'Masuk ke kelas 3D'];
        if (\VC\Security\Owner::is(Auth::user())) { // khusus pemilik (coffelatte314)
            $adminMenu[] = ['/media#dash', '🏞️', 'Latar Dashboard', 'Adegan, musim & waktu semua pengguna', 'gold'];
            $adminMenu[] = ['/media', '🎬', 'Media & Musik', 'Latar login & lagu semua halaman'];
            $adminMenu[] = ['/team', '👥', 'Team & Author', 'Edit anggota, tentang & author'];
        }
        $adminIcons = [
            ['/', '🏠', 'Beranda'],
            ["js:toggleAdminAi();document.getElementById('adminAiBox').scrollIntoView({behavior:'smooth'})", '🤖', 'Si Silber (Asisten)'],
            ['js:logoutAdmin()', '🚪', 'Keluar'],
        ];
        PixelDashboard::render($user, $adminMenu, $adminIcons, $isRoot ? 'KONSOL ROOT' : 'PANEL ADMIN', true);
        echo PixelDashboard::JS;
        echo '<div class="container" id="pxdBelow">';
        echo '<header style="margin:14px 0">';
        echo '<h1 style="margin:0;font-size:1.25rem">' . ($isRoot ? '👑 Konsol Supreme ROOT & Keamanan' : '🛡️ Panel Administrasi & Staf Pengajar') . '</h1>';
        echo '</header>';

        // Panel AI Si Silber untuk admin/guru (petunjuk misi, data, fitur).
        echo '<div id="adminAiBox" style="display:none;background:#131a29;border:1px solid #f59e0b;border-radius:12px;padding:14px;margin-bottom:18px">';
        echo '<div style="display:flex;gap:10px;align-items:flex-start"><img src="/assets/mascot/silber-mascot.svg" alt="" style="width:40px;height:40px;flex-shrink:0">';
        echo '<div style="flex:1">';
        echo '<strong style="color:#f59e0b">🤖 Si Silber — Asisten Dashboard Admin</strong>';
        echo '<div style="font-size:.8rem;color:#94a3b8;margin-top:3px">Tanya apa saja: data siswa, tugas, jadwal, denah, petunjuk misi, atau fitur yang tersedia.</div>';
        echo '<div id="adminAiLog" style="margin-top:8px;background:rgba(0,0,0,.35);border:1px solid #1f293d;border-radius:9px;padding:9px;max-height:200px;overflow-y:auto;font-size:.83rem;line-height:1.6"></div>';
        echo '<form onsubmit="return adminAsk(event)" style="display:flex;gap:8px;margin-top:8px">';
        echo '<input id="adminAiInput" placeholder="Contoh: apa saja yang bisa kamu bantu?" style="flex:1;background:#0d121d;color:#fff;border:1px solid #1f293d;padding:9px 12px;border-radius:8px" autocomplete="off">';
        echo '<button class="btn" type="submit" style="background:#f59e0b;color:#1c1204">Kirim</button>';
        echo '</form></div></div></div>';

        // Konsol bertab: satu bagian tampil per waktu (tombol di bawah), tidak perlu gulir panjang.
        $tabs = [['ringkasan', '📊', 'Ringkasan'], ['server', '🎮', 'Server']];
        if ($isRoot) {
            $tabs[] = ['broadcast', '📢', 'Broadcast']; // broadcast khusus ROOT
            $tabs[] = ['pengguna', '👥', 'Pengguna'];
        }
        $tabs[] = ['pendaftaran', '📝', 'Pendaftaran'];
        $tabs[] = ['keamanan', '🛡️', 'Keamanan'];
        $tabs[] = ['alat', '⚡', 'Alat Staf'];
        echo '<nav class="adm-tabs" role="tablist" aria-label="Bagian konsol">';
        foreach ($tabs as [$k, $ic, $lb]) {
            echo '<button type="button" role="tab" class="adm-tabbtn" data-tab="' . $k . '" aria-selected="false"><span aria-hidden="true">' . $ic . '</span>' . $lb . '</button>';
        }
        echo '</nav>';

        echo '<section class="adm-tab" data-tab="ringkasan">';
        // ROOT SUPREME BANNER
        if ($isRoot) {
            echo '<div style="background:linear-gradient(135deg,rgba(2,132,199,0.22),rgba(249,115,22,0.28));border:2px solid #f97316;border-radius:14px;padding:20px;margin-bottom:24px;box-shadow:0 8px 32px rgba(249,115,22,0.3);position:relative;overflow:hidden;">';
            echo '<div style="display:flex;align-items:center;gap:16px">';
            echo '<div style="font-size:2.8rem">👑</div>';
            echo '<div>';
            echo '<div style="font-size:1.25rem;font-weight:900;color:#fff;letter-spacing:0.5px">SUPREME ROOT CONSOLE — KONTROL TERTINGGI SISTEM</div>';
            echo '<div style="color:#fb923c;font-size:0.9rem;margin-top:4px">Akun Aktif: <strong>' . htmlspecialchars($user['username'], ENT_QUOTES) . '</strong> (' . htmlspecialchars($user['full_name'], ENT_QUOTES) . ') — Otoritas Superuser Penuh (*)</div>';
            echo '<div style="color:#cbd5e1;font-size:0.82rem;margin-top:4px">Sistem memverifikasi langsung dari database SQLite produksi bahwa Anda berhak mengatur seluruh pengguna, role staf, transaksi koin, filter honeypot, dan AI.</div>';
            echo '</div></div></div>';
        }

        // STATS ROW — kartu ringkasan hero (angka besar, kartu putih)
        echo '<div class="grid-stats" id="ringkasan">';
        echo '<div class="card-stat"><div class="stat-lbl">Siswa Terdaftar</div><div class="stat-val">' . $totalStudents . '</div><span class="stat-sub">Akun murid aktif di database</span></div>';
        echo '<div class="card-stat"><div class="stat-lbl">Kelas / Rombel</div><div class="stat-val" style="color:#0A0E17">' . $totalClasses . '</div><span class="stat-sub">Rombel aktif sekolah</span></div>';
        echo '<div class="card-stat"><div class="stat-lbl">Server Online</div><div class="stat-val" style="color:var(--oranye)">' . $serversOnline . '</div><span class="stat-sub">GameServers::liveCounts() • ' . $playersOnline . ' pemain aktif</span></div>';
        echo '<div class="card-stat"><div class="stat-lbl">Guru &amp; Staf</div><div class="stat-val">' . $totalStaff . '</div><span class="stat-sub">Hak akses bertingkat</span></div>';
        echo '<div class="card-stat"><div class="stat-lbl">Antrean Pendaftaran</div><div class="stat-val" style="color:var(--oranye)">' . count($pendingRegs) . '</div><span class="stat-sub">Menunggu verifikasi guru</span></div>';
        echo '<div class="card-stat"><div class="stat-lbl">IP Diblokir (Brute-Force)</div><div class="stat-val" style="color:#dc2626">' . $totalBlockedIPs . '</div><span class="stat-sub">security.sqlite shield</span></div>';
        echo '</div>';

        // AUDIT LOG STATUS
        echo '<div class="card" style="margin-bottom:24px">';
        echo '<div style="font-size:0.9rem;font-weight:bold;margin-bottom:8px;color:#fff">Integritas Audit Log (SHA-256 Hash Chained):</div>';
        if ($isAuditSafe) {
            echo '<div class="audit-status audit-ok"><span>🛡️</span><div><strong>VALID & AMAN:</strong> Tidak ditemukan catatan audit yang terhapus atau dimanipulasi (0 rantai putus). Setiap aksi terekam otentik.</div></div>';
        } else {
            echo '<div class="audit-status audit-fail"><span>⚠️</span><div><strong>PERINGATAN:</strong> Integritas rantai hash audit terdeteksi putus pada ID ' . (int)$auditIntegrityBroken . '. Hubungi administrator segera.</div></div>';
        }
        echo '</div>';

        // GAME & MAP LAUNCHPAD (LOBI SEBELUM MASUK PERMAINAN & DENAH)
        echo '<div class="section-title"><span>🎮 Peluncur Permainan & Denah Sekolah (Akses Admin)</span></div>';
        echo '<div class="grid-stats grid-stats--4" style="margin-bottom:24px">';
        echo '<a href="/dunia" class="card-stat" style="text-decoration:none;border-color:rgba(34,211,238,0.5);background:linear-gradient(135deg,rgba(15,23,42,0.9),rgba(2,132,199,0.22))">';
        echo '<div style="font-size:2rem;margin-bottom:4px">🎮</div>';
        echo '<div class="stat-lbl" style="color:var(--blue-light);font-weight:700">Masuk Permainan 3D</div>';
        echo '<div style="font-size:0.8rem;color:#cbd5e1;margin-top:6px;line-height:1.4">Jelajahi Virtual City 3D, interaksi multiplayer & misi sekolah dengan akun admin.</div>';
        echo '<div style="margin-top:12px;font-size:0.82rem;font-weight:700;color:var(--orange)">🚀 Masuk Dunia 3D →</div>';
        echo '</a>';

        echo '<a href="/peta" class="card-stat" style="text-decoration:none;border-color:rgba(16,185,129,0.5);background:linear-gradient(135deg,rgba(15,23,42,0.9),rgba(16,185,129,0.2))">';
        echo '<div style="font-size:2rem;margin-bottom:4px">🗺️</div>';
        echo '<div class="stat-lbl" style="color:#6ee7b7;font-weight:700">Buka Denah 2D Sekolah</div>';
        echo '<div style="font-size:0.8rem;color:#cbd5e1;margin-top:6px;line-height:1.4">Denah interaktif seluruh lantai 1 & 2, navigasi cepat ruang kelas & lab TIK.</div>';
        echo '<div style="margin-top:12px;font-size:0.82rem;font-weight:700;color:#6ee7b7">🗺️ Buka Denah 2D →</div>';
        echo '</a>';

        echo '<a href="/editor-peta" class="card-stat" style="text-decoration:none;border-color:rgba(249,115,22,0.5);background:linear-gradient(135deg,rgba(15,23,42,0.9),rgba(249,115,22,0.2))">';
        echo '<div style="font-size:2rem;margin-bottom:4px">📐</div>';
        echo '<div class="stat-lbl" style="color:#fb923c;font-weight:700">Editor Denah & Peta</div>';
        echo '<div style="font-size:0.8rem;color:#cbd5e1;margin-top:6px;line-height:1.4">Visual map editor admin untuk mengatur posisi gedung, lantai, dan file campus.json.</div>';
        echo '<div style="margin-top:12px;font-size:0.82rem;font-weight:700;color:#fb923c">📐 Buka Map Editor →</div>';
        echo '</a>';

        echo '<a href="/karakter" class="card-stat" style="text-decoration:none;border-color:rgba(168,85,247,0.5);background:linear-gradient(135deg,rgba(15,23,42,0.9),rgba(168,85,247,0.2))">';
        echo '<div style="font-size:2rem;margin-bottom:4px">🎭</div>';
        echo '<div class="stat-lbl" style="color:#c084fc;font-weight:700">Studio Karakter 3D</div>';
        echo '<div style="font-size:0.8rem;color:#cbd5e1;margin-top:6px;line-height:1.4">Turntable studio Three.js untuk kustomisasi seragam kejuruan dan gaya avatar.</div>';
        echo '<div style="margin-top:12px;font-size:0.82rem;font-weight:700;color:#c084fc">🎭 Buka Studio Karakter →</div>';
        echo '</a>';
        echo '</div>';

        echo '</section>';
        echo '<section class="adm-tab" data-tab="server">';
        // MULTIPLAYER & CLASS SERVER MANAGEMENT (AGENT 4)
        $isRootOrKepsek = in_array($user['role'], ['root', 'kepala_sekolah'], true);
        if ($isRootOrKepsek) {
            $classList = $pdo->query('SELECT id, name FROM classes WHERE deleted_at IS NULL ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $cStmt = $pdo->prepare('SELECT id, name FROM classes WHERE deleted_at IS NULL AND (wali_kelas_user_id = ? OR id IN (SELECT class_id FROM class_members WHERE user_id = ?)) ORDER BY name ASC');
            $cStmt->execute([(int)$user['id'], (int)$user['id']]);
            $classList = $cStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($classList)) {
                $classList = $pdo->query('SELECT id, name FROM classes WHERE deleted_at IS NULL ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $gameServers = \VC\Domain\GameServers::listFor((int)$user['id'], (string)$user['role']);

        echo '<div class="section-title" id="server-section"><span>🎮 Manajemen Server Kelas & Multiplayer (Virtual City)</span></div>';
        echo '<div class="card" style="border:1px solid rgba(34,211,238,0.4);background:linear-gradient(135deg,rgba(15,23,42,0.95),rgba(34,211,238,0.06));margin-bottom:24px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">';
        echo '<div>';
        echo '<div style="font-size:1.1rem;font-weight:800;color:#22d3ee;display:flex;align-items:center;gap:8px"><span>🌐 Server Kelas & Real-Time Multiplayer</span><span style="font-size:0.75rem;background:rgba(34,211,238,0.15);color:#22d3ee;padding:3px 8px;border-radius:6px;border:1px solid rgba(34,211,238,0.3)">Target: 150 Pemain</span></div>';
        echo '<div style="font-size:0.83rem;color:#94a3b8;margin-top:4px">Buat server pembelajaran khusus rombel kelas Anda, pantau siswa yang sedang terhubung, atau bagikan kode akses.</div>';
        echo '</div>';
        echo '<button class="btn" style="background:var(--blue-light);color:#04070f;font-weight:700" onclick="toggleCreateServerForm()">➕ Buat Server Kelas Baru</button>';
        echo '</div>';

        // Formulir Buat Server Baru (Collapsible)
        echo '<div id="formCreateServer" style="display:none;background:rgba(8,14,26,0.9);border:1px solid #1f293d;border-radius:12px;padding:16px;margin-bottom:18px">';
        echo '<h3 style="color:#f59e0b;font-size:0.95rem;margin:0 0 12px 0">Formulir Pembuatan Server Baru</h3>';
        echo '<form onsubmit="return buatServerKelas(event)" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Nama Server</label><input type="text" id="srvName" class="form-input" placeholder="Contoh: Kelas Web X-PPLG-1" required minlength="3" maxlength="60" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px"></div>';
        // Satu server = satu dunia. Dipilih saat dibuat dan tidak bisa diganti (pemain 3D & Pixel tidak tercampur).
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Dunia Permainan</label><select id="srvWorld" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<option value="3d">🚀 Dunia 3D (/dunia)</option><option value="pixel">🧱 Dunia Pixel (/pixel)</option></select></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Tipe Server</label><select id="srvType" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<option value="class">🏫 Server Kelas (Khusus Siswa Rombel)</option>';
        echo '<option value="private">🔒 Server Privat (Wajib Undangan)</option>';
        if ($isRootOrKepsek) {
            echo '<option value="public">🌐 Server Publik (Seluruh Siswa)</option>';
            echo '<option value="event">🏆 Server Event (Kompetisi / Acara)</option>';
        }
        echo '</select></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Kelas yang Ditautkan</label><select id="srvClass" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<option value="0">-- Tanpa Rombel Tertentu --</option>';
        foreach ($classList as $c) {
            echo '<option value="' . (int)$c['id'] . '">' . htmlspecialchars((string)$c['name'], ENT_QUOTES) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Kapasitas Maksimal (2–150 Siswa)</label><input type="number" id="srvCap" value="30" min="2" max="150" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px"></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Aturan Akses</label><select id="srvAccess" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<option value="invite">🔑 Kode Undangan Unik (Rekomendasi)</option>';
        echo '<option value="open">🔓 Terbuka Bebas untuk Anggota Kelas</option>';
        echo '</select></div>';
        echo '<div style="grid-column:1/-1"><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Deskripsi / Catatan Pembelajaran</label><input type="text" id="srvDesc" placeholder="Keterangan materi praktikum atau instruksi guru..." style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px"></div>';
        echo '<div style="grid-column:1/-1;display:flex;align-items:center;gap:12px;margin-top:6px">';
        echo '<button type="submit" class="btn" style="background:#10b981;color:#000;font-weight:700">Simpan & Terbitkan Server</button>';
        echo '<button type="button" class="btn-sec" onclick="toggleCreateServerForm()">Batal</button>';
        echo '<span id="srvMsg" style="font-size:0.82rem"></span>';
        echo '</div></form></div>';

        // Tabel Daftar Server Aktif
        echo '<div class="table-responsive"><table style="width:100%;border-collapse:collapse;font-size:0.83rem;color:#cbd5e1">';
        echo '<thead><tr style="border-bottom:1px solid #334155;text-align:left;color:#94a3b8"><th style="padding:10px 8px">ID</th><th style="padding:10px 8px">Nama Server</th><th style="padding:10px 8px">Dunia / Tipe</th><th style="padding:10px 8px">Kelas</th><th style="padding:10px 8px">Online / Kapasitas</th><th style="padding:10px 8px">Akses / Kode</th><th style="padding:10px 8px">Status</th><th style="padding:10px 8px">Aksi</th></tr></thead><tbody>';
        if (empty($gameServers)) {
            echo '<tr><td colspan="8" style="padding:16px;text-align:center;color:#64748b">Belum ada server yang aktif. Silakan buat server baru di atas.</td></tr>';
        } else {
            foreach ($gameServers as $gs) {
                $typeBadges = [
                    'public' => '<span style="background:rgba(34,211,238,0.15);color:#22d3ee;padding:2px 6px;border-radius:4px;font-size:0.72rem">🌐 Publik</span>',
                    'class' => '<span style="background:rgba(16,185,129,0.15);color:#10b981;padding:2px 6px;border-radius:4px;font-size:0.72rem">🏫 Kelas</span>',
                    'private' => '<span style="background:rgba(245,158,11,0.15);color:#f59e0b;padding:2px 6px;border-radius:4px;font-size:0.72rem">🔒 Privat</span>',
                    'event' => '<span style="background:rgba(168,85,247,0.15);color:#c084fc;padding:2px 6px;border-radius:4px;font-size:0.72rem">🏆 Event</span>',
                ];
                $typeLabel = $typeBadges[$gs['type']] ?? $gs['type'];
                $statusDot = ($gs['online'] > 0) ? '<span style="color:#10b981">● Live (' . (int)$gs['online'] . ' aktif)</span>' : '<span style="color:#64748b">○ Siap</span>';
                $codeDisplay = ($gs['access'] === 'invite') ? '<code style="background:#020617;padding:2px 6px;border-radius:4px;color:#f59e0b">' . htmlspecialchars((string)($gs['invite_code'] ?? ($gs['needs_code'] ? 'Kunci 🔑' : 'Tersedia')), ENT_QUOTES) . '</code>' : '<span style="color:#94a3b8">Bebas Terbuka</span>';
                echo '<tr style="border-bottom:1px solid rgba(51,65,85,0.4)">';
                echo '<td style="padding:10px 8px;color:#64748b">#' . (int)$gs['id'] . '</td>';
                echo '<td style="padding:10px 8px;font-weight:700;color:#fff">' . htmlspecialchars((string)$gs['name'], ENT_QUOTES) . '<br><span style="font-size:0.73rem;color:#94a3b8;font-weight:400">' . htmlspecialchars((string)($gs['description'] ?? ''), ENT_QUOTES) . '</span></td>';
                $isPixelSrv = ($gs['world'] ?? '3d') === 'pixel';
                $worldBadge = $isPixelSrv
                    ? '<span style="background:rgba(194,122,60,0.2);color:#ffcf4a;padding:2px 6px;border-radius:4px;font-size:0.72rem">🧱 Pixel</span>'
                    : '<span style="background:rgba(56,189,248,0.15);color:#38bdf8;padding:2px 6px;border-radius:4px;font-size:0.72rem">🚀 3D</span>';
                echo '<td style="padding:10px 8px">' . $worldBadge . ' ' . $typeLabel . '</td>';
                echo '<td style="padding:10px 8px;color:#38bdf8">' . htmlspecialchars((string)($gs['class_name'] ?? '-'), ENT_QUOTES) . '</td>';
                echo '<td style="padding:10px 8px"><strong style="color:' . ($gs['online'] > 0 ? '#10b981' : '#cbd5e1') . '">' . (int)$gs['online'] . '</strong> / ' . (int)$gs['capacity'] . ' siswa</td>';
                echo '<td style="padding:10px 8px">' . $codeDisplay . '</td>';
                echo '<td style="padding:10px 8px">' . $statusDot . '</td>';
                echo '<td style="padding:10px 8px;white-space:nowrap">';
                echo $isPixelSrv
                    ? '<a href="/pixel?server=' . (int)$gs['id'] . '" class="btn-sec" style="padding:4px 8px;font-size:0.75rem;margin-right:4px">🧱 Buka Pixel</a>'
                    : '<a href="/dunia?server=' . (int)$gs['id'] . '" class="btn-sec" style="padding:4px 8px;font-size:0.75rem;margin-right:4px">🚀 Buka 3D</a>';
                $canEditThis = $isRootOrKepsek || !empty($gs['is_owner']);
                if ($gs['access'] !== 'invite' || !empty($gs['invite_code'])) {
                    $sharePayload = htmlspecialchars(json_encode([
                        'title' => 'Bagikan server: ' . (string)$gs['name'],
                        'url' => \VC\Domain\GameServers::shareUrl($gs),
                        'code' => (string)($gs['invite_code'] ?? ''),
                        'text' => 'Ayo masuk server "' . (string)$gs['name'] . '" (' . ($isPixelSrv ? 'Dunia Pixel' : 'Dunia 3D') . ') di Virtual City SMK Silber!',
                        'note' => $gs['access'] === 'invite' ? 'Link/QR ini langsung mengisi kode undangan.' : 'Server terbuka: link langsung membuka dunianya.',
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    echo '<button type="button" class="btn-sec" style="padding:4px 8px;font-size:0.75rem;margin-right:4px;border-color:#22c55e;color:#4ade80" onclick="window.vcShare&&vcShare.open(' . $sharePayload . ')">🔗 Bagikan</button>';
                }
                if ($canEditThis) {
                    $jsonPayload = htmlspecialchars(json_encode([
                        'id' => (int)$gs['id'],
                        'name' => (string)$gs['name'],
                        'description' => (string)($gs['description'] ?? ''),
                        'type' => (string)$gs['type'],
                        'class_id' => (int)($gs['class_id'] ?? 0),
                        'capacity' => (int)$gs['capacity'],
                        'access' => (string)$gs['access'],
                        'invite_code' => (string)($gs['invite_code'] ?? ''),
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    echo '<button type="button" class="btn-sec" style="padding:4px 8px;font-size:0.75rem;margin-right:4px;border-color:#38bdf8;color:#38bdf8" onclick="openEditServerModal(' . $jsonPayload . ')">✏️ Edit</button>';
                    if ((int)$gs['id'] !== 1) {
                        echo '<button type="button" class="btn-sec" style="padding:4px 8px;font-size:0.75rem;border-color:#ef4444;color:#f87171" onclick="hapusServerKelas(' . (int)$gs['id'] . ', ' . htmlspecialchars(json_encode((string)$gs['name']), ENT_QUOTES) . ')">🗑️ Hapus</button>';
                    }
                }
                echo '</td></tr>';
            }
        }
        echo '</tbody></table></div>';

        // Modal Edit Server
        echo '<div id="modalEditServer" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.78);backdrop-filter:blur(6px);z-index:9999;align-items:center;justify-content:center;padding:16px">';
        echo '<div style="background:#0f172a;border:1px solid #38bdf8;border-radius:16px;box-shadow:0 16px 48px rgba(0,0,0,0.85);max-width:540px;width:100%;padding:22px;color:#f0f6fc;max-height:92vh;overflow-y:auto">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #1e2c44;padding-bottom:10px">';
        echo '<h3 style="margin:0;font-size:1.1rem;color:#38bdf8;display:flex;align-items:center;gap:8px">✏️ Edit Server Virtual City</h3>';
        echo '<button type="button" onclick="closeEditServerModal()" style="background:none;border:none;color:#94a3b8;font-size:1.2rem;cursor:pointer">✕</button>';
        echo '</div>';
        echo '<form onsubmit="return simpanEditServer(event)" style="display:flex;flex-direction:column;gap:12px">';
        echo '<input type="hidden" id="editSrvId">';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Nama Server</label>';
        echo '<input type="text" id="editSrvName" class="form-input" required minlength="3" maxlength="60" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px"></div>';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Tipe Server</label>';
        echo '<select id="editSrvType" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<option value="class">🏫 Server Kelas</option>';
        echo '<option value="private">🔒 Server Privat</option>';
        if ($isRootOrKepsek) {
            echo '<option value="public">🌐 Server Publik</option>';
            echo '<option value="event">🏆 Server Event</option>';
        }
        echo '</select></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Rombel Kelas</label>';
        echo '<select id="editSrvClass" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<option value="0">-- Tanpa Rombel --</option>';
        foreach ($classList as $c) {
            echo '<option value="' . (int)$c['id'] . '">' . htmlspecialchars((string)$c['name'], ENT_QUOTES) . '</option>';
        }
        echo '</select></div></div>';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Kapasitas (2–150)</label>';
        echo '<input type="number" id="editSrvCap" min="2" max="150" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px"></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Aturan Akses</label>';
        echo '<select id="editSrvAccess" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<option value="invite">🔑 Kode Undangan</option>';
        echo '<option value="open">🔓 Terbuka Bebas</option>';
        echo '</select></div></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Kode Undangan Saat Ini</label>';
        echo '<div style="display:flex;gap:8px;align-items:center">';
        echo '<input type="text" id="editSrvCode" readonly style="flex:1;background:#020617;color:#f59e0b;font-family:monospace;font-weight:bold;border:1px solid #334155;border-radius:8px;padding:8px">';
        echo '<label style="font-size:0.78rem;color:#38bdf8;display:inline-flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" id="editSrvRegen"> Acak Kode Baru</label>';
        echo '</div></div>';
        echo '<div><label style="font-size:0.78rem;color:#cbd5e1;display:block;margin-bottom:4px">Deskripsi / Catatan Pembelajaran</label>';
        echo '<input type="text" id="editSrvDesc" style="width:100%;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px"></div>';
        echo '<div id="editSrvMsg" style="font-size:0.83rem"></div>';
        echo '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:10px;border-top:1px solid #1e2c44;padding-top:12px">';
        echo '<button type="button" class="btn-sec" onclick="closeEditServerModal()">Batal</button>';
        echo '<button type="submit" class="btn" style="background:#0284c7;color:#fff;font-weight:bold">Simpan Perubahan</button>';
        echo '</div></form></div></div>';

        echo '</div>';

        echo '</section>';
        if ($isRoot) {
            echo '<section class="adm-tab" data-tab="broadcast">';
            self::renderBroadcastPanel();
            echo '</section>';
        }
        echo '<section class="adm-tab" data-tab="alat">';
        // QUICK ACTION TOOLS — grid tombol aksi admin
        echo '<div class="section-title"><span>⚡ Akses Cepat Alat Manajemen Staf</span></div>';
        echo '<div class="action-grid">';
        echo '<a href="/dunia" class="btn-sec">🎮 Masuk Game 3D</a>';
        echo '<a href="/peta" class="btn-sec">🗺️ Denah Sekolah 2D</a>';
        echo '<a href="/editor-peta" class="btn-sec">📐 Map Editor 3D Sekolah</a>';
        echo '<a href="/jadwal" class="btn-sec">📅 Import / Rollback Jadwal</a>';
        echo '<a href="/kelas" class="btn-sec">📚 Ruang Kelas & Kuis Online</a>';
        echo '<a href="/event" class="btn-sec">🏆 Agenda Event & Presensi</a>';
        echo '<a href="/chat" class="btn-sec">💬 Moderasi Obrolan</a>';
        if (Permissions::can('chat.moderate') || Permissions::can('mission.manage')) {
            echo '<a href="/admin-game" class="btn-sec">🎮 Chat & Misi In-Game</a>';
        }
        echo '<a href="/health" class="btn-sec" target="_blank">📊 System Health Status ↗</a>';
        if (Permissions::can('ai.view')) {
            echo '<a href="/admin/ai" class="btn-sec">🤖 Orkestrasi 3 AI</a>';
        }
        echo '</div>';

        echo '</section>';
        echo '<section class="adm-tab" data-tab="pengguna">';
        // USER DIRECTORY & ROLE MANAGEMENT (ROOT ONLY)
        if ($isRoot && !empty($allUsers)) {
            echo '<div class="section-title" id="siswa"><span>👥 Manajemen Staf & Pengguna (Database Live)</span></div>';

            // Keamanan Akun ROOT: 2FA (TOTP)
            $totpActive = !empty($user['totp_enabled']);
            echo '<div class="card" style="border:1px solid rgba(34,211,238,0.4);background:linear-gradient(135deg,rgba(15,23,42,0.95),rgba(34,211,238,0.06));margin-bottom:20px">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">';
            echo '<div>';
            echo '<div style="font-size:1.02rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px">';
            echo '<span>🛡️ Autentikasi Dua Faktor (2FA / TOTP) ROOT</span>';
            echo $totpActive ? '<span style="background:rgba(16,185,129,0.2);color:#10b981;padding:2px 8px;border-radius:6px;font-size:0.75rem;border:1px solid #10b981">AKTIF & DILINDUNGI</span>'
                             : '<span style="background:rgba(239,68,68,0.2);color:#ef4444;padding:2px 8px;border-radius:6px;font-size:0.75rem;border:1px solid #ef4444">BELUM AKTIF</span>';
            echo '</div>';
            echo '<div style="font-size:0.82rem;color:#94a3b8;margin-top:4px">Wajibkan verifikasi kode 6 digit dari Google Authenticator / Aegis saat login akun ROOT.</div>';
            echo '</div>';
            echo '<div>';
            if ($totpActive) {
                echo '<button onclick="disableTotpPrompt()" class="btn btn-red" style="padding:8px 14px;font-size:0.82rem">Nonaktifkan 2FA</button>';
            } else {
                echo '<button onclick="startTotpSetup()" class="btn btn-blue" style="padding:8px 14px;font-size:0.82rem">⚡ Siapkan &amp; Aktifkan 2FA</button>';
            }
            echo '</div>';
            echo '</div>';
            echo '<div id="totpSetupArea" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.1)"></div>';
            echo '</div>';

            // Form Tambah Staf Baru & Hubungkan Telegram
            echo '<div class="card" style="border:1px solid var(--orange);background:linear-gradient(135deg,rgba(15,23,42,0.95),rgba(249,115,22,0.08));margin-bottom:20px">';
            echo '<div style="font-size:1.02rem;font-weight:800;color:#fff;margin-bottom:12px;display:flex;align-items:center;gap:8px">';
            echo '<span>➕ Tambah Akun Staf / Guru Baru & Tautkan Telegram</span>';
            echo '</div>';
            echo '<form id="formAddStaff" onsubmit="tambahStaffBaru(event)" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;align-items:end">';
            echo '<div><label style="font-size:0.75rem;color:#94a3b8;font-weight:700">Username Web *</label>';
            echo '<input id="staffUsername" class="select-dark" style="width:100%;box-sizing:border-box;margin-top:4px" placeholder="misal: guru_ahmad" required></div>';
            echo '<div><label style="font-size:0.75rem;color:#94a3b8;font-weight:700">Password Web *</label>';
            echo '<div style="display:flex;gap:4px;margin-top:4px">';
            echo '<input id="staffPassword" class="select-dark" style="flex:1;box-sizing:border-box" placeholder="Min. 8 karakter" required>';
            echo '<button type="button" class="btn btn-blue" onclick="generateStaffPass()" style="padding:4px 8px;font-size:0.75rem" title="Buat sandi acak">🎲 Acak</button></div></div>';
            echo '<div><label style="font-size:0.75rem;color:#94a3b8;font-weight:700">Nama Lengkap & Gelar</label>';
            echo '<input id="staffFullName" class="select-dark" style="width:100%;box-sizing:border-box;margin-top:4px" placeholder="misal: Pak Ahmad, S.Kom"></div>';
            echo '<div><label style="font-size:0.75rem;color:#94a3b8;font-weight:700">Role Pengguna *</label>';
            echo '<select id="staffRole" class="select-dark" style="width:100%;box-sizing:border-box;margin-top:4px">';
            echo '<option value="guru">GURU (Pengajar)</option>';
            echo '<option value="wali_kelas">WALI KELAS</option>';
            echo '<option value="kepala_program">KEPALA PROGRAM</option>';
            echo '<option value="kepala_sekolah">KEPALA SEKOLAH</option>';
            echo '<option value="pembina">PEMBINA EKSKUL</option>';
            echo '<option value="pengurus">PENGURUS</option>';
            echo '<option value="root">ROOT (Super Admin)</option>';
            echo '</select></div>';
            echo '<div><label style="font-size:0.75rem;color:#94a3b8;font-weight:700">ID Telegram (Opsional)</label>';
            echo '<input id="staffTgId" type="number" class="select-dark" style="width:100%;box-sizing:border-box;margin-top:4px" placeholder="Angka ID Telegram"></div>';
            echo '<div><button type="submit" class="btn" style="width:100%;padding:9px 14px">💾 Buat & Hubungkan</button></div>';
            echo '</form></div>';

            echo '<div class="card">';
            echo '<p class="muted" style="font-size:0.85rem;margin-top:0">Daftar pengguna aktual di tabel <code>users</code>. Sebagai ROOT, Anda dapat mengubah role, mengelola koin, mereset kata sandi web, atau menonaktifkan pengguna.</p>';
            echo '<div class="table-responsive"><table>';
            echo '<thead><tr><th>ID</th><th>Username & Nama</th><th>Role</th><th>Telegram Bot</th><th>Koin</th><th>Ubah Role</th><th>Aksi Akun</th></tr></thead><tbody>';
            $availableRoles = ['root', 'kepala_sekolah', 'kepala_program', 'guru', 'wali_kelas', 'pembina', 'pengurus', 'murid'];
            foreach ($allUsers as $uRow) {
                $roleColor = match ($uRow['role']) {
                    'root' => '#f97316',
                    'kepala_sekolah', 'kepala_program' => '#38bdf8',
                    'guru', 'wali_kelas' => '#60a5fa',
                    'pembina', 'pengurus' => '#c084fc',
                    default => '#10b981'
                };
                $tgBadge = !empty($uRow['tg_user_id'])
                    ? '<span style="color:#38bdf8;font-weight:700;font-size:0.78rem">🟢 TG: ' . (int)$uRow['tg_user_id'] . '</span>'
                    : '<span class="muted faint" style="font-size:0.78rem">⚪ Belum</span>';
                echo '<tr>';
                echo '<td><code>#' . (int)$uRow['id'] . '</code></td>';
                echo '<td><strong>' . htmlspecialchars($uRow['username'], ENT_QUOTES) . '</strong><div class="muted faint" style="font-size:0.75rem">' . htmlspecialchars($uRow['full_name'], ENT_QUOTES) . '</div></td>';
                echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:0.75rem;font-weight:bold;background:rgba(255,255,255,0.08);color:' . $roleColor . ';border:1px solid ' . $roleColor . '">' . strtoupper($uRow['role']) . '</span></td>';
                echo '<td>' . $tgBadge . '</td>';
                echo '<td><strong style="color:var(--orange)">🪙 ' . (int)$uRow['coins'] . '</strong> <span class="muted" style="font-size:0.75rem">(Lvl ' . (int)$uRow['level'] . ')</span></td>';
                echo '<td><div style="display:flex;gap:4px;align-items:center">';
                echo '<select id="roleSel_' . (int)$uRow['id'] . '" class="select-dark">';
                foreach ($availableRoles as $rOpt) {
                    $sel = ($rOpt === $uRow['role']) ? ' selected' : '';
                    echo '<option value="' . $rOpt . '"' . $sel . '>' . strtoupper($rOpt) . '</option>';
                }
                echo '</select>';
                echo '<button class="btn btn-blue" style="padding:4px 8px;font-size:0.75rem" onclick="ubahRole(' . (int)$uRow['id'] . ')">Simpan</button>';
                echo '</div></td>';
                echo '<td><div style="display:flex;gap:4px;flex-wrap:wrap">';
                echo '<button class="btn" style="padding:4px 6px;font-size:0.75rem" onclick="tambahKoin(' . (int)$uRow['id'] . ', 100)" title="Tambah 100 koin">+100</button>';
                echo '<button class="btn btn-blue" style="padding:4px 6px;font-size:0.75rem" onclick="resetPasswordUser(' . (int)$uRow['id'] . ', \'' . htmlspecialchars($uRow['username'], ENT_QUOTES) . '\')" title="Reset Password Web">🔑 Sandi</button>';
                if ((int)$uRow['id'] !== (int)$user['id']) {
                    echo '<button class="btn btn-red" style="padding:4px 6px;font-size:0.75rem" onclick="hapusUser(' . (int)$uRow['id'] . ', \'' . htmlspecialchars($uRow['username'], ENT_QUOTES) . '\')" title="Nonaktifkan Pengguna">🗑️</button>';
                }
                echo '</div></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        }

        echo '</section>';
        echo '<section class="adm-tab" data-tab="pendaftaran">';
        // REGISTRATION APPROVAL QUEUE
        echo '<div class="section-title"><span>📝 Antrean Persetujuan Siswa Baru (' . count($pendingRegs) . ')</span></div>';
        echo '<div class="card">';
        if (empty($pendingRegs)) {
            echo '<div style="text-align:center;color:#94a3b8;padding:12px">Tidak ada pendaftaran baru yang menunggu persetujuan. Semua akun telah terverifikasi.</div>';
        } else {
            echo '<div class="table-responsive"><table>';
            echo '<thead><tr><th>Nama Lengkap</th><th>NIS / NISN</th><th>Kelas Pilihan</th><th>Username</th><th>Waktu Daftar</th><th>Aksi</th></tr></thead><tbody>';
            foreach ($pendingRegs as $r) {
                echo '<tr>';
                echo '<td><strong>' . htmlspecialchars($r['full_name'], ENT_QUOTES) . '</strong></td>';
                echo '<td>' . htmlspecialchars($r['nis'] ?? '-', ENT_QUOTES) . '</td>';
                echo '<td>' . htmlspecialchars($r['class_name'] ?? 'Umum', ENT_QUOTES) . '</td>';
                echo '<td><code>' . htmlspecialchars($r['username'], ENT_QUOTES) . '</code></td>';
                echo '<td style="color:#94a3b8">' . htmlspecialchars(substr($r['created_at'], 0, 16), ENT_QUOTES) . '</td>';
                echo '<td style="display:flex;gap:6px">';
                echo '<button class="btn" onclick="prosesDaftar(' . (int)$r['id'] . ', \'approve\')">Setujui</button>';
                echo '<button class="btn btn-red" onclick="prosesDaftar(' . (int)$r['id'] . ', \'reject\')">Tolak</button>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        echo '</section>';
        echo '<section class="adm-tab" data-tab="keamanan">';
        // SECURITY BLOCKS LOG
        echo '<div class="section-title"><span>🛑 Log Blokir Keamanan Terkini (Honeypot & Rate Limit)</span></div>';
        echo '<div class="card">';
        if (empty($recentBlocks)) {
            echo '<div style="text-align:center;color:#94a3b8;padding:12px">Tidak ada IP yang sedang diblokir saat ini. Sistem berjalan aman.</div>';
        } else {
            echo '<div class="table-responsive"><table>';
            echo '<thead><tr><th>IP Address</th><th>Alasan Blokir</th><th>Waktu Blokir</th><th>Kedaluwarsa</th><th>Aksi</th></tr></thead><tbody>';
            foreach ($recentBlocks as $b) {
                echo '<tr>';
                $createdTs = (int)($b['created_at'] ?? 0);
                $expTs = (int)($b['expires_at'] ?? 0);
                echo '<td><code>' . htmlspecialchars((string)($b['ip'] ?? '-'), ENT_QUOTES) . '</code></td>';
                echo '<td style="color:#f87171">' . htmlspecialchars((string)($b['reason'] ?? $b['kind'] ?? 'Pelanggaran keamanan'), ENT_QUOTES) . '</td>';
                echo '<td style="color:#94a3b8">' . htmlspecialchars($createdTs ? date('Y-m-d H:i', $createdTs) : '-', ENT_QUOTES) . '</td>';
                echo '<td style="color:#94a3b8">' . htmlspecialchars($expTs ? date('Y-m-d H:i', $expTs) : '-', ENT_QUOTES) . '</td>';
                echo '<td><button class="btn btn-sec" style="padding:4px 8px;font-size:0.75rem" onclick="unbanIP(\'' . htmlspecialchars((string)($b['ip'] ?? ''), ENT_QUOTES) . '\')">Buka Blokir</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
        echo '</section>';

        echo Footer::html();
        echo '</div>';
        echo '<script src="/assets/js/admin-console.js?v=7.4.0" defer></script>';
        echo '<script src="/assets/js/qr.js?v=7.6.1" defer></script><script src="/assets/js/share.js?v=7.6.1" defer></script>';

        // Bottom navigation mobile admin
        echo '<nav class="bottomnav" aria-label="Navigasi bawah admin">';
        echo '<a href="/"><span>🏠</span><span>Beranda</span></a>';
        echo '<a href="/dunia"><span>🎮</span><span>Dunia 3D</span></a>';
        echo '<a href="/admin#server-section"><span>🖥️</span><span>Server</span></a>';
        echo $isRoot ? '<a href="/admin#broadcast"><span>📢</span><span>Broadcast</span></a>' : '<a href="/jadwal"><span>📅</span><span>Jadwal</span></a>';
        echo '<a href="/profil"><span>👤</span><span>Profil</span></a>';
        echo '</nav>';

        echo '<script nonce="' . $nonce . '">
async function prosesDaftar(id, aksi) {
  const f = new FormData();
  f.append("reg_id", id);
  f.append("action", aksi);
  f.append("_csrf", "' . $csrf . '");

  try {
    const r = await fetch("/api/approve", {method:"POST", body:f});
    const d = await r.json();
    alert(d.message || "Selesai.");
    location.reload();
  } catch(e) { alert("Gagal memproses pendaftaran."); }
}

async function unbanIP(ip) {
  if (!confirm("Buka blokir untuk IP " + ip + "?")) return;
  const r = await fetch("/api/admin/unban", {
    method: "POST",
    headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
    body: JSON.stringify({ip: ip})
  });
  const d = await r.json();
  alert(d.message || "Selesai.");
  location.reload();
}

async function ubahRole(userId) {
  const sel = document.getElementById("roleSel_" + userId);
  if (!sel) return;
  const newRole = sel.value;
  if (!confirm("Ubah role pengguna #" + userId + " menjadi " + newRole.toUpperCase() + "?")) return;

  try {
    const r = await fetch("/api/admin/role_update", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({user_id: userId, role: newRole, _csrf: "' . $csrf . '"})
    });
    const d = await r.json();
    alert(d.message || "Role berhasil diubah.");
    location.reload();
  } catch(e) { alert("Gagal mengubah role."); }
}

async function tambahKoin(userId, amount) {
  if (!confirm("Tambahkan +" + amount + " koin untuk pengguna #" + userId + "?")) return;
  try {
    const r = await fetch("/api/admin/coin_adjust", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({user_id: userId, amount: amount, _csrf: "' . $csrf . '"})
    });
    const d = await r.json();
    alert(d.message || "Koin berhasil ditambahkan.");
    location.reload();
  } catch(e) { alert("Gagal menyesuaikan koin."); }
}

async function logoutAdmin() {
  await fetch("/api/logout", {method:"POST", headers:{"X-CSRF":"' . $csrf . '"}});
  location.href = "/";
}

function generateStaffPass() {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!#%*";
  let pass = "Silber#";
  for (let i = 0; i < 6; i++) pass += chars.charAt(Math.floor(Math.random() * chars.length));
  document.getElementById("staffPassword").value = pass;
}

async function tambahStaffBaru(e) {
  e.preventDefault();
  const u = document.getElementById("staffUsername").value.trim();
  const p = document.getElementById("staffPassword").value.trim();
  const n = document.getElementById("staffFullName").value.trim();
  const r = document.getElementById("staffRole").value;
  const tg = document.getElementById("staffTgId").value.trim();

  try {
    const res = await fetch("/api/admin/staff_create", {
      method: "POST",
      headers: {"Content-Type": "application/json", "X-CSRF": "' . $csrf . '"},
      body: JSON.stringify({username: u, password: p, full_name: n, role: r, tg_user_id: tg, _csrf: "' . $csrf . '"})
    });
    const d = await res.json();
    alert(d.message || "Selesai.");
    if (res.ok) location.reload();
  } catch(err) {
    alert("Gagal menambahkan akun staf.");
  }
}

async function resetPasswordUser(id, username) {
  const newPass = prompt("Masukkan password baru untuk " + username + " (kosongkan untuk buat acak otomatis):");
  if (newPass === null) return;
  try {
    const res = await fetch("/api/admin/user_resetpass", {
      method: "POST",
      headers: {"Content-Type": "application/json", "X-CSRF": "' . $csrf . '"},
      body: JSON.stringify({user_id: id, new_password: newPass, _csrf: "' . $csrf . '"})
    });
    const d = await res.json();
    alert((d.message || "Password direset.") + (d.new_password ? "\nPassword: " + d.new_password : ""));
    location.reload();
  } catch(err) {
    alert("Gagal mereset password.");
  }
}

async function hapusUser(id, username) {
  if (!confirm("Nonaktifkan akun pengguna " + username + " (#" + id + ")? Sesi login dan tautan bot akan dicabut.")) return;
  try {
    const res = await fetch("/api/admin/user_delete", {
      method: "POST",
      headers: {"Content-Type": "application/json", "X-CSRF": "' . $csrf . '"},
      body: JSON.stringify({user_id: id, _csrf: "' . $csrf . '"})
    });
    const d = await res.json();
    alert(d.message || "Pengguna dinonaktifkan.");
    location.reload();
  } catch(err) {
    alert("Gagal menonaktifkan pengguna.");
  }
}

async function startTotpSetup() {
  const area = document.getElementById("totpSetupArea");
  if (!area) return;
  area.style.display = "block";
  area.innerHTML = "<div style=\"color:#94a3b8\">Menyiapkan kunci rahasia 2FA...</div>";

  try {
    const csrf = document.querySelector(\'meta[name="csrf"]\')?.content || \'\';
    const res = await fetch("/api/totp/setup", { method: "POST", headers: { \'X-CSRF\': csrf }, body: new URLSearchParams({ _csrf: csrf }) });
    const data = await res.json();
    if (!res.ok || !data.secret) {
      area.innerHTML = "<div style=\"color:#ef4444\">Gagal menyiapkan 2FA: " + (data.message || "") + "</div>";
      return;
    }

    window.__totpSecret = data.secret;
    area.innerHTML = "<div style=\"background:#0d121d;border:1px solid #1f293d;border-radius:10px;padding:16px\">"
      + "<h4 style=\"margin:0 0 10px;color:#fff;font-size:0.95rem\">Langkah 1: Tambahkan Kunci Rahasia ke Authenticator</h4>"
      + "<p style=\"font-size:0.83rem;color:#cbd5e1;margin-bottom:10px\">Buka aplikasi <strong>Google Authenticator / Aegis</strong> di HP, pilih <em>Masukkan kunci penyiapan</em>:</p>"
      + "<div style=\"display:flex;align-items:center;gap:8px;background:#1e293b;padding:10px 14px;border-radius:8px;margin-bottom:12px\">"
      + "<code style=\"font-size:1.15rem;font-weight:bold;letter-spacing:0.15em;color:#38bdf8;flex:1\">" + data.secret + "</code>"
      + "<button type=\"button\" class=\"btn btn-blue\" style=\"padding:4px 10px;font-size:0.75rem\" onclick=\"copyTotpSecret()\">Salin</button>"
      + "</div>"
      + "<h4 style=\"margin:0 0 10px;color:#fff;font-size:0.95rem\">Langkah 2: Masukkan 6 Digit Kode Konfirmasi</h4>"
      + "<div style=\"display:flex;gap:8px;max-width:320px\">"
      + "<input id=\"inputTotpEnableCode\" type=\"text\" maxlength=\"6\" inputmode=\"numeric\" placeholder=\"000000\" class=\"select-dark\" style=\"font-size:1.2rem;letter-spacing:0.2em;text-align:center;font-weight:bold;flex:1\">"
      + "<button type=\"button\" class=\"btn\" style=\"background:#10b981;color:#000;font-weight:bold\" onclick=\"verifyAndEnableTotp()\">Aktifkan 2FA</button>"
      + "</div>"
      + "<div id=\"totpEnableFeedback\" style=\"margin-top:10px;font-size:0.82rem\"></div>"
      + "</div>";
  } catch(e) {
    area.innerHTML = "<div style=\"color:#ef4444\">Koneksi ke server gagal.</div>";
  }
}

function copyTotpSecret() {
  if (window.__totpSecret && navigator.clipboard) {
    navigator.clipboard.writeText(window.__totpSecret);
    alert("Kunci rahasia disalin!");
  }
}

async function verifyAndEnableTotp() {
  const code = document.getElementById("inputTotpEnableCode") ? document.getElementById("inputTotpEnableCode").value : "";
  const fb = document.getElementById("totpEnableFeedback");
  if (!code) { alert("Masukkan 6 digit kode dari aplikasi authenticator."); return; }

  try {
    const res = await fetch("/api/totp/enable", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ code: code, _csrf: "' . $csrf . '" })
    });
    const data = await res.json();
    if (res.ok) {
      alert("✅ " + (data.message || "2FA berhasil diaktifkan!"));
      location.reload();
    } else {
      if (fb) { fb.innerHTML = "<span style=\"color:#ef4444\">" + (data.message || "Kode salah.") + "</span>"; }
    }
  } catch(e) {
    if (fb) { fb.innerHTML = "<span style=\"color:#ef4444\">Gagal menghubungi server.</span>"; }
  }
}

async function disableTotpPrompt() {
  const code = prompt("Masukkan 6 digit kode Authenticator saat ini untuk menonaktifkan 2FA:");
  if (!code) return;

  try {
    const res = await fetch("/api/totp/disable", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ code: code, _csrf: "' . $csrf . '" })
    });
    const data = await res.json();
    if (res.ok) {
      alert("✅ " + (data.message || "2FA dinonaktifkan."));
      location.reload();
    } else {
      alert("❌ " + (data.message || "Kode salah."));
    }
  } catch(e) {
    alert("Gagal menonaktifkan 2FA.");
  }
}

function toggleAdminAi() {
  var box = document.getElementById("adminAiBox");
  if (!box) return;
  var open = box.style.display === "none";
  box.style.display = open ? "block" : "none";
  if (open) { var i = document.getElementById("adminAiInput"); if (i) i.focus(); }
}
async function adminAsk(e) {
  if (e) e.preventDefault();
  var input = document.getElementById("adminAiInput");
  var log = document.getElementById("adminAiLog");
  if (!input || !log) return false;
  var q = (input.value || "").trim();
  if (!q) return false;
  input.value = "";
  log.innerHTML += "<div style=\"text-align:right;color:#22d3ee\"><b>Kamu:</b> " + q.replace(/</g,"&lt;") + "</div><div style=\"color:#94a3b8\">🤖 Si Silber berpikir…</div>";
  log.scrollTop = log.scrollHeight;
  try {
    var r = await fetch("/api/ai/ask", {
      method: "POST",
      headers: {"Content-Type": "application/json", "X-CSRF": "' . $csrf . '"},
      body: JSON.stringify({ question: q, context: "Dashboard Admin/Guru SMK Silber", _csrf: "' . $csrf . '" })
    });
    var d = await r.json();
    var reply = (d && (d.reply || d.message)) || "Maaf, coba lagi ya.";
    if (log.lastElementChild) log.lastElementChild.remove();
    log.innerHTML += "<div style=\"background:rgba(30,41,59,.8);padding:8px 10px;border-radius:8px\">🤖 <b>Si Silber:</b> " + String(reply).replace(/</g,"&lt;") + "</div>";
  } catch (err) {
    if (log.lastElementChild) log.lastElementChild.remove();
    log.innerHTML += "<div style=\"color:#fca5a5\">🤖 Gagal terhubung. Coba lagi.</div>";
  }
  log.scrollTop = log.scrollHeight;
  return false;
}
function toggleCreateServerForm() {
  var f = document.getElementById("formCreateServer");
  if (!f) return;
  f.style.display = (f.style.display === "none" || f.style.display === "") ? "block" : "none";
}
async function buatServerKelas(e) {
  if (e) e.preventDefault();
  var name = (document.getElementById("srvName").value || "").trim();
  var type = document.getElementById("srvType").value;
  var world = (document.getElementById("srvWorld") || {}).value || "3d";
  var classId = document.getElementById("srvClass").value;
  var capacity = document.getElementById("srvCap").value;
  var access = document.getElementById("srvAccess").value;
  var desc = (document.getElementById("srvDesc").value || "").trim();
  var errEl = document.getElementById("srvMsg");
  errEl.textContent = "Sedang mendaftarkan server...";
  errEl.style.color = "#94a3b8";
  try {
    var res = await fetch("/api/servers/create", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ name: name, type: type, world: world, class_id: parseInt(classId, 10), capacity: parseInt(capacity, 10), access: access, description: desc, _csrf: "' . $csrf . '" })
    });
    var d = await res.json();
    if (d.status === "ok") {
      errEl.textContent = "✅ Server berhasil dibuat! Kode: " + (d.invite_code || "-");
      errEl.style.color = "#10b981";
      if (window.vcShare) {
        var shUrl = access === "invite" && d.invite_code ? "/gabung?kode=" + encodeURIComponent(d.invite_code) : (d.world === "pixel" ? "/pixel" : "/dunia") + "?server=" + d.server_id;
        vcShare.open({ title: "Server dibuat: " + (d.name || name), url: shUrl, code: d.invite_code || "", text: "Ayo masuk server \"" + (d.name || name) + "\" di Virtual City SMK Silber!", onClose: function(){ location.reload(); } });
      } else {
        setTimeout(function(){ location.reload(); }, 1200);
      }
    } else {
      errEl.textContent = "❌ " + (d.message || "Gagal membuat server.");
      errEl.style.color = "#ef4444";
    }
  } catch(err) {
    errEl.textContent = "❌ Gagal menghubungi layanan server.";
    errEl.style.color = "#ef4444";
  }
  return false;
}
function openEditServerModal(srv) {
  var m = document.getElementById("modalEditServer");
  if (!m) return;
  document.getElementById("editSrvId").value = srv.id || 0;
  document.getElementById("editSrvName").value = srv.name || "";
  document.getElementById("editSrvType").value = srv.type || "class";
  document.getElementById("editSrvClass").value = srv.class_id || 0;
  document.getElementById("editSrvCap").value = srv.capacity || 30;
  document.getElementById("editSrvAccess").value = srv.access || "invite";
  document.getElementById("editSrvDesc").value = srv.description || "";
  document.getElementById("editSrvCode").value = srv.invite_code || "";
  document.getElementById("editSrvRegen").checked = false;
  document.getElementById("editSrvMsg").textContent = "";
  m.style.display = "flex";
}
function closeEditServerModal() {
  var m = document.getElementById("modalEditServer");
  if (m) m.style.display = "none";
}
async function simpanEditServer(e) {
  if (e) e.preventDefault();
  var id = parseInt(document.getElementById("editSrvId").value, 10);
  var name = (document.getElementById("editSrvName").value || "").trim();
  var type = document.getElementById("editSrvType").value;
  var classId = document.getElementById("editSrvClass").value;
  var capacity = document.getElementById("editSrvCap").value;
  var access = document.getElementById("editSrvAccess").value;
  var desc = (document.getElementById("editSrvDesc").value || "").trim();
  var regen = document.getElementById("editSrvRegen").checked ? 1 : 0;
  var errEl = document.getElementById("editSrvMsg");
  errEl.textContent = "Menyimpan perubahan server...";
  errEl.style.color = "#94a3b8";
  try {
    var res = await fetch("/api/servers/update", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({
        server_id: id,
        name: name,
        type: type,
        class_id: parseInt(classId, 10),
        capacity: parseInt(capacity, 10),
        access: access,
        description: desc,
        regen_code: regen,
        _csrf: "' . $csrf . '"
      })
    });
    var d = await res.json();
    if (d.status === "ok") {
      errEl.textContent = "✅ Server berhasil diperbarui!";
      errEl.style.color = "#10b981";
      setTimeout(function(){ location.reload(); }, 900);
    } else {
      errEl.textContent = "❌ " + (d.message || "Gagal mengedit server.");
      errEl.style.color = "#ef4444";
    }
  } catch(err) {
    errEl.textContent = "❌ Gagal menghubungi layanan server.";
    errEl.style.color = "#ef4444";
  }
  return false;
}
async function hapusServerKelas(id, name) {
  if (!confirm("Hapus server \"" + name + "\" (#" + id + ")? Server akan dinonaktifkan dan pemain di dalamnya akan dikeluarkan.")) return;
  try {
    var res = await fetch("/api/servers/delete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ server_id: id, _csrf: "' . $csrf . '" })
    });
    var d = await res.json();
    if (d.status === "ok") {
      alert("✅ Server \"" + name + "\" berhasil dihapus.");
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal menghapus server."));
    }
  } catch(e) {
    alert("❌ Gagal menghubungi server.");
  }
}
</script></body></html>';
    }

    /** Handle unban IP request */
    /** Panel Broadcast + pratinjau musim dashboard (diisi admin-console.js). */
    private static function renderBroadcastPanel(): void
    {
        echo '<div class="section-title" id="broadcast"><span>📢 Broadcast Pengumuman ke Dashboard</span></div>';
        echo '<div class="card adm-bc">';
        echo '<p class="adm-note">Pesan tampil sebagai <b>pop-up</b> di dashboard (sekali per perangkat) <b>dan masuk ke lonceng notifikasi 🔔</b> murid, guru &amp; admin sesuai sasaran. Hanya akun ROOT yang bisa mengirim. Tekan <b>Tes</b> untuk melihat tampilannya di layar Anda sendiri sebelum dikirim.</p>';
        echo '<form id="bcForm" class="adm-bc__form" novalidate>';
        echo '<label>Judul<input name="title" maxlength="80" required placeholder="mis. Libur Maulid Nabi"></label>';
        echo '<label>Isi pesan<textarea name="message" rows="4" maxlength="600" required placeholder="Tulis pengumuman…"></textarea></label>';
        echo '<div class="adm-bc__row">';
        echo '<label>Tautan (opsional)<input name="link_url" maxlength="300" placeholder="https://… atau /jadwal"></label>';
        echo '<label>Teks tombol<input name="link_label" maxlength="40" placeholder="Buka"></label>';
        echo '</div><div class="adm-bc__row">';
        echo '<label>Untuk<select name="audience"><option value="all">Semua pengguna</option><option value="murid">Murid saja</option><option value="staf">Guru &amp; staf saja</option></select></label>';
        echo '<label>Tampil selama<select name="days"><option value="1">1 hari</option><option value="3">3 hari</option><option value="7" selected>7 hari</option><option value="30">30 hari</option><option value="0">Sampai dihapus</option></select></label>';
        echo '</div><div class="adm-bc__actions"><span id="bcMsg" aria-live="polite"></span>';
        echo '<button type="button" class="btn btn-sec" id="bcTest">👁 Tes (lihat sendiri)</button>';
        echo '<button type="submit" class="btn btn-blue">📢 Kirim Broadcast</button></div>';
        echo '</form>';
        echo '<h3 class="adm-sub">Riwayat broadcast</h3><div class="tblwrap"><table><thead><tr><th>Judul</th><th>Untuk</th><th>Status</th><th>Oleh</th><th></th></tr></thead><tbody id="bcList"><tr><td colspan="5">Memuat…</td></tr></tbody></table></div>';
        echo '</div>';

        echo '<div class="section-title"><span>🎨 Tampilan Musim &amp; Hari Raya</span></div>';
        echo '<div class="card"><p class="adm-note">Dashboard otomatis mengikuti tanggal: 8 musim + hari raya semua agama (Idul Fitri, Idul Adha, Natal, Paskah, Nyepi, Waisak, Imlek). Pilih di bawah untuk <b>pratinjau di perangkat ini saja</b>.</p><div class="adm-seasons" id="seasonPick"></div></div>';
    }

    public static function handleUnban(): void
    {
        $u = Auth::user();
        if ($u === null || !in_array($u['role'], ['root', 'kepala_sekolah', 'kepala_program'], true)) {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT yang dapat membuka blokir IP.']));
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $ip = trim((string)($body['ip'] ?? ''));
        if ($ip !== '') {
            $secDb = Database::security();
            $stmt = $secDb->prepare('DELETE FROM ip_blocks WHERE ip = ?');
            $stmt->execute([$ip]);
            AuditLog::log('ip_unban', ['ip' => $ip], (int)$u['id']);
            SecurityLog::write('ip_unban', ['ip' => $ip]);
        }

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Blokir IP berhasil dibuka.']);
    }

    /** Handle user role update (ROOT only) */
    public static function handleRoleUpdate(): void
    {
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT yang dapat mengubah hak akses pengguna.']));
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $targetId = (int)($body['user_id'] ?? 0);
        $newRole = trim((string)($body['role'] ?? ''));

        $allowedRoles = ['root', 'kepala_sekolah', 'kepala_program', 'guru', 'wali_kelas', 'pembina', 'pengurus', 'murid', 'murid_akses'];
        if ($targetId <= 0 || !in_array($newRole, $allowedRoles, true)) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Parameter tidak valid.']));
        }

        $pdo = Database::get();
        $targetUser = $pdo->query('SELECT role, username FROM users WHERE id = ' . $targetId)->fetch();
        if (!$targetUser) {
            http_response_code(404);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Pengguna tidak ditemukan.']));
        }

        if ($targetUser['role'] === 'root' && $newRole !== 'root') {
            $rootCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "root" AND deleted_at IS NULL')->fetchColumn();
            if ($rootCount <= 1) {
                http_response_code(400);
                header('Content-Type: application/json');
                exit(json_encode(['status' => 'error', 'message' => 'Tidak dapat menurunkan akun ROOT terakhir.']));
            }
        }

        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$newRole, $targetId]);

        Permissions::flush();
        AuditLog::log('user_role_change', [
            'target_id' => $targetId,
            'target_user' => $targetUser['username'],
            'old_role' => $targetUser['role'],
            'new_role' => $newRole
        ], (int)$u['id']);
        SecurityLog::write('role_assigned', ['target_id' => $targetId, 'new_role' => $newRole]);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Role pengguna berhasil diperbarui menjadi ' . strtoupper($newRole) . '.']);
    }

    /** Handle coin adjustments (ROOT only) */
    public static function handleCoinAdjust(): void
    {
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT yang dapat mengelola saldo koin secara langsung.']));
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $targetId = (int)($body['user_id'] ?? 0);
        $amount = (int)($body['amount'] ?? 0);
        $reason = trim((string)($body['reason'] ?? 'Penyesuaian oleh Administrator ROOT'));

        if ($targetId <= 0 || $amount === 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Jumlah koin tidak boleh 0 dan ID pengguna wajib diisi.']));
        }

        try {
            if ($amount > 0) {
                \VC\Domain\Gameplay::grantCoins($targetId, $amount, 'admin_grant', (string)$u['id'], $reason);
            } else {
                $pdo = Database::get();
                $pdo->beginTransaction();
                $curCoins = (int)$pdo->query('SELECT coins FROM user_progress WHERE user_id = ' . $targetId)->fetchColumn();
                $newCoins = max(0, $curCoins + $amount);
                $pdo->prepare('UPDATE user_progress SET coins = ? WHERE user_id = ?')->execute([$newCoins, $targetId]);
                $pdo->prepare('INSERT INTO transactions (user_id, type, amount, source_type, source_id, description, created_at)
                    VALUES (?, "coins_deduct", ?, "admin_deduct", ?, ?, datetime("now"))')
                    ->execute([$targetId, abs($amount), (string)$u['id'], $reason]);
                $pdo->commit();
            }

            AuditLog::log('coin_admin_adjust', ['target_id' => $targetId, 'amount' => $amount, 'reason' => $reason], (int)$u['id']);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Saldo koin pengguna berhasil disesuaikan (' . ($amount > 0 ? "+$amount" : "$amount") . ').']);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Gagal mengubah saldo: ' . $e->getMessage()]);
        }
    }

    /** Handle create staff / user (ROOT only) */
    public static function handleStaffCreate(): void
    {
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT yang dapat membuat akun staf.']));
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $username = strtolower(trim((string)($body['username'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $fullName = trim((string)($body['full_name'] ?? ''));
        $role = strtolower(trim((string)($body['role'] ?? 'guru')));
        $tgId = (int)($body['tg_user_id'] ?? 0);

        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Username minimal 3 karakter (huruf, angka, ._-).']));
        }

        $allowedRoles = ['root', 'kepala_sekolah', 'kepala_program', 'guru', 'wali_kelas', 'pembina', 'pengurus'];
        if (!in_array($role, $allowedRoles, true)) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Role tidak valid.']));
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Password minimal 8 karakter.']));
        }

        $pdo = Database::get();
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Username sudah digunakan.']));
        }

        $uid = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, status, must_change_password, created_at)
            VALUES (?, ?, ?, ?, ?, "active", 0, datetime("now"))')
            ->execute([$uid, $username, Auth::hash($password), $role, $fullName ?: ("Staf " . strtoupper($role))]);
        $newId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT OR IGNORE INTO user_progress (user_id, coins, level, total_xp) VALUES (?, 1000, 5, 500)')->execute([$newId]);

        if ($tgId > 0) {
            $pdo->prepare('INSERT OR REPLACE INTO telegram_links (tg_user_id, user_id, linked_at, last_seen_at)
                VALUES (?, ?, datetime("now"), datetime("now"))')
                ->execute([$tgId, $newId]);
        }

        Permissions::flush();
        AuditLog::log('admin_staff_created', [
            'new_user_id' => $newId,
            'username' => $username,
            'role' => $role,
            'tg_user_id' => $tgId > 0 ? $tgId : null
        ], (int)$u['id']);

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Akun staf ' . $username . ' (' . strtoupper($role) . ') berhasil dibuat' . ($tgId > 0 ? ' dan ditautkan ke Telegram ID ' . $tgId : '') . '!'
        ]);
    }

    /** Handle user delete / suspend (ROOT only) */
    public static function handleUserDelete(): void
    {
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT yang dapat menonaktifkan pengguna.']));
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $targetId = (int)($body['user_id'] ?? 0);
        if ($targetId <= 0 || $targetId === (int)$u['id']) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'ID pengguna tidak valid atau tidak dapat menonaktifkan akun sendiri.']));
        }

        $pdo = Database::get();
        $targetUser = $pdo->query('SELECT role, username FROM users WHERE id = ' . $targetId)->fetch();
        if (!$targetUser) {
            http_response_code(404);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Pengguna tidak ditemukan.']));
        }

        if ($targetUser['role'] === 'root') {
            $rootCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "root" AND status = "active" AND deleted_at IS NULL')->fetchColumn();
            if ($rootCount <= 1) {
                http_response_code(400);
                header('Content-Type: application/json');
                exit(json_encode(['status' => 'error', 'message' => 'Tidak dapat menonaktifkan akun ROOT terakhir.']));
            }
        }

        $pdo->prepare('UPDATE users SET status = "suspended", deleted_at = datetime("now") WHERE id = ?')->execute([$targetId]);
        $pdo->prepare('DELETE FROM telegram_links WHERE user_id = ?')->execute([$targetId]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$targetId]);

        Permissions::flush();
        AuditLog::log('user_suspended', [
            'target_id' => $targetId,
            'target_user' => $targetUser['username'],
            'target_role' => $targetUser['role']
        ], (int)$u['id']);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Pengguna ' . $targetUser['username'] . ' berhasil dinonaktifkan dan sesi dicabut.']);
    }

    /** Handle user password reset (ROOT only) */
    public static function handleUserResetPassword(): void
    {
        $u = Auth::user();
        if ($u === null || $u['role'] !== 'root') {
            http_response_code(403);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Hanya ROOT yang dapat mereset sandi pengguna.']));
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        Csrf::check($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null));

        $targetId = (int)($body['user_id'] ?? 0);
        $newPass = trim((string)($body['new_password'] ?? ''));

        if ($targetId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'ID pengguna tidak valid.']));
        }

        if ($newPass === '') {
            $newPass = 'SilberPass#' . bin2hex(random_bytes(3)) . '!';
        } elseif (strlen($newPass) < 8) {
            http_response_code(400);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Password baru minimal 8 karakter.']));
        }

        $pdo = Database::get();
        $targetUser = $pdo->query('SELECT username FROM users WHERE id = ' . $targetId)->fetch();
        if (!$targetUser) {
            http_response_code(404);
            header('Content-Type: application/json');
            exit(json_encode(['status' => 'error', 'message' => 'Pengguna tidak ditemukan.']));
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([Auth::hash($newPass), $targetId]);
        AuditLog::log('user_password_reset', ['target_id' => $targetId, 'username' => $targetUser['username']], (int)$u['id']);

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Password pengguna ' . $targetUser['username'] . ' berhasil direset.',
            'new_password' => $newPass
        ]);
    }

    /** AI Orchestrator dashboard — role-gated status of the three agents and the job queue. */
    public static function renderAiDashboard(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        if ($user === null) {
            header('Location: /');
            exit;
        }
        if (!Permissions::can('ai.view')) {
            http_response_code(403);
            exit('403 — Anda tidak berwenang melihat dashboard AI.');
        }

        $stats = \VC\Ai\Orchestrator::stats();
        $jobs = \VC\Ai\Orchestrator::list(40);
        $roles = $stats['roles'];
        $canDrive = Permissions::can('ai.orchestrate');
        $csrf = Csrf::token();

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="csrf" content="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
        echo Pwa::headTags();
        echo '<title>Dashboard 3 AI — SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
body { font-family: system-ui, sans-serif; background: #090d16; color: #e2e8f0; margin: 0; padding: 20px; }
.container { max-width: 1050px; margin: 0 auto; }
h1 { font-size: 1.3rem; color: #fff; }
a.back { color: #22d3ee; text-decoration: none; font-size: 0.85rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 16px 0; }
.card { background: #131a29; border: 1px solid #1f293d; border-radius: 12px; padding: 16px; }
.val { font-size: 1.6rem; font-weight: 800; color: #fff; }
.lbl { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; }
table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #1f293d; vertical-align: top; }
th { color: #94a3b8; }
.pill { padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; }
.ok { background: rgba(16,185,129,0.2); color: #6ee7b7; }
.fail { background: rgba(239,68,68,0.2); color: #fca5a5; }
.run { background: rgba(34,211,238,0.2); color: #67e8f9; }
.wait { background: rgba(245,158,11,0.2); color: #fcd34d; }
.muted { color: #94a3b8; }
select, textarea, input { width: 100%; background: #0b1220; color: #e2e8f0; border: 1px solid #24304a; border-radius: 8px; padding: 8px; box-sizing: border-box; }
button { background: #22d3ee; color: #000; border: none; border-radius: 8px; padding: 9px 16px; font-weight: 700; cursor: pointer; }
</style></head><body><div class="container">';
        echo '<a class="back" href="/admin">← Panel Admin</a>';
        echo '<h1>🤖 Orkestrasi 3 AI — Dashboard Status</h1>';
        echo '<p class="muted">AI-1 Engineering (patch) → AI-2 Security &amp; QA (audit) → AI-3 Integration &amp; Release (integrasi). Setiap role memakai provider/model berbeda.</p>';

        // Role status
        echo '<div class="grid">';
        foreach ($roles as $role => $s) {
            $cls = $s['configured'] ? 'ok' : 'fail';
            echo '<div class="card"><div class="lbl">' . htmlspecialchars(\VC\Ai\Providers::roleLabel($role), ENT_QUOTES) . '</div>';
            echo '<div class="val"><span class="pill ' . $cls . '">' . htmlspecialchars($s['state'], ENT_QUOTES) . '</span></div>';
            echo '<div class="muted" style="font-size:0.8rem">' . htmlspecialchars(($s['provider'] ?? '-') . ' / ' . ($s['model'] ?? '-'), ENT_QUOTES) . '</div>';
            if (!$s['configured']) {
                echo '<div class="fail" style="font-size:0.78rem">' . htmlspecialchars($s['reason'], ENT_QUOTES) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Job stats
        echo '<div class="grid">';
        foreach ($stats['by_status'] as $k => $v) {
            echo '<div class="card"><div class="lbl">' . htmlspecialchars($k, ENT_QUOTES) . '</div><div class="val">' . (int)$v . '</div></div>';
        }
        echo '<div class="card"><div class="lbl">Token hari ini / batas</div><div class="val" style="font-size:1.1rem">' . (int)$stats['tokens_today'] . ' / ' . (int)$stats['daily_budget'] . '</div></div>';
        echo '</div>';

        // Enqueue form
        if ($canDrive) {
            echo '<div class="card" style="margin-bottom:18px">';
            echo '<h3 style="margin-top:0">➕ Antrekan Task</h3>';
            echo '<div style="display:grid;gap:8px;max-width:640px">';
            echo '<label class="lbl">Role</label><select id="aiRole">';
            foreach (array_keys($roles) as $role) {
                echo '<option value="' . htmlspecialchars($role, ENT_QUOTES) . '">' . htmlspecialchars(\VC\Ai\Providers::roleLabel($role), ENT_QUOTES) . '</option>';
            }
            echo '</select>';
            echo '<label class="lbl">Task</label><textarea id="aiTask" rows="3" placeholder="Jelaskan task yang sudah divalidasi..."></textarea>';
            echo '</div>';
            echo '<div style="margin-top:10px"><button onclick="vcEnqueueAi()">Kirim ke Antrean</button></div>';
            echo '<div id="aiEnqMsg" class="muted" style="margin-top:8px"></div>';
            echo '</div>';
        }

        // Recent jobs
        echo '<h3>Riwayat Job (40 terbaru)</h3>';
        echo '<div class="card"><div class="table-responsive"><table><thead><tr><th>Job ID</th><th>Role</th><th>Status</th><th>Provider/Model</th><th>Percobaan</th><th>Token</th><th>Dibuat</th></tr></thead><tbody>';
        if (empty($jobs)) {
            echo '<tr><td colspan="7" class="muted">Belum ada job.</td></tr>';
        }
        foreach ($jobs as $j) {
            $cls = $j['status'] === 'succeeded' ? 'ok' : ($j['status'] === 'failed' ? 'fail' : ($j['status'] === 'running' ? 'run' : 'wait'));
            echo '<tr>';
            echo '<td><code style="font-size:0.72rem">' . htmlspecialchars(substr((string)$j['id'], 0, 18), ENT_QUOTES) . '</code></td>';
            echo '<td>' . htmlspecialchars((string)$j['role'], ENT_QUOTES) . '</td>';
            echo '<td><span class="pill ' . $cls . '">' . htmlspecialchars((string)$j['status'], ENT_QUOTES) . '</span></td>';
            echo '<td class="muted">' . htmlspecialchars(($j['provider'] ?? '-') . '/' . substr((string)($j['model'] ?? '-'), 0, 26), ENT_QUOTES) . '</td>';
            echo '<td>' . (int)$j['attempts'] . '/' . (int)$j['max_attempts'] . '</td>';
            echo '<td>' . (int)$j['tokens_used'] . '</td>';
            echo '<td class="muted">' . htmlspecialchars(substr((string)$j['created_at'], 0, 16), ENT_QUOTES) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
        echo Footer::html();
        echo '</div>';

        if ($canDrive) {
            echo '<script nonce="' . $nonce . '">
async function vcEnqueueAi(){
  var role=document.getElementById("aiRole").value;
  var task=document.getElementById("aiTask").value.trim();
  var msg=document.getElementById("aiEnqMsg");
  if(!task){msg.textContent="Task wajib diisi.";return;}
  var csrf=document.querySelector("meta[name=csrf]").content;
  try{
    var r=await fetch("/api/ai/job",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF":csrf},
      body:JSON.stringify({role:role,task:task,_csrf:csrf})});
    var d=await r.json();
    msg.textContent=(d.status||"?")+" — "+(d.job_id||d.message||"");
    if(d.status==="queued"){setTimeout(function(){location.reload();},900);}
  }catch(e){msg.textContent="Gagal mengirim.";}
}
</script>';
        }
        echo '</body></html>';
    }

    /**
     * Agent 1 — Dashboard Game Admin (halaman /admin-game):
     * chat in-game (aktif/nonaktif, mode kanal, moderasi) + CRUD misi kategori extensible.
     * Semua aksi via endpoint resmi tervalidasi server (CSRF + permission).
     */
    public static function renderGameAdminPage(): void
    {
        $canChat = Permissions::can('chat.moderate');
        $canMission = Permissions::can('mission.manage');
        if (!$canChat && !$canMission) {
            Permissions::require('chat.moderate'); // layar 403 ramah
        }
        $nonce = Headers::nonce();
        $user = Auth::user();
        $settings = \VC\Domain\GameChat::settings(true);
        $missions = \VC\Domain\Gameplay::adminListMissions();
        foreach ($missions as &$m) {
            foreach (['id','xp_reward','coin_reward','order_num','is_active','badge_id'] as $k) {
                $m[$k] = (int)($m[$k] ?? 0);
            }
        }
        unset($m);
        $badges = \VC\Domain\Gameplay::badgeList();
        $cats = \VC\Domain\Gameplay::missionCategories();
        $locDoc = json_decode((string)@file_get_contents(\VC_PUBLIC . '/assets/map/locations.json'), true) ?: [];
        $locations = [];
        foreach ($locDoc['locations'] ?? [] as $l) {
            $internal = explode(':', (string)($l['campusRef'] ?? ':'), 2)[1] ?? '';
            if ($internal !== '') {
                $locations[] = ['id' => $internal, 'name' => (string)$l['name']];
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="csrf" content="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">';
        echo '<title>Dashboard Game — Chat & Misi | SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">';
        echo '/* Design System 4 Warna: Hitam #0A0E17 / Biru #1D4ED8-#2563EB / Oranye #F97316-#F59E0B / Putih #FFFFFF */';
        echo '*{box-sizing:border-box}html,body{max-width:100%;overflow-x:hidden}';
        echo 'body{font-family:system-ui,-apple-system,sans-serif;margin:0;padding:0 0 calc(76px + env(safe-area-inset-bottom));color:#E7ECF7;';
        echo 'background-color:#0A0E17;background-image:radial-gradient(ellipse 680px 400px at 100% -8%,rgba(249,115,22,0.35),transparent 65%),radial-gradient(ellipse 860px 520px at -10% 0%,rgba(29,78,216,0.55),transparent 70%),linear-gradient(160deg,#0A0E17 0%,#101b3a 55%,#14256b 100%);background-attachment:fixed}';
        echo '.wrap{max-width:1100px;margin:0 auto;padding:14px}';
        echo '.topbar-ag{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:12px;padding:10px 14px;padding-top:calc(10px + env(safe-area-inset-top));background:rgba(10,14,23,0.92);backdrop-filter:blur(10px);border-bottom:1px solid rgba(37,99,235,0.35)}';
        echo '.topbar-ag__avatar{width:40px;height:40px;min-width:40px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#F97316,#F59E0B);color:#fff;font-weight:900}';
        echo '.topbar-ag__name{color:#fff;font-weight:800;font-size:0.95rem;line-height:1.2}.topbar-ag__sub{color:#F59E0B;font-size:0.72rem;font-weight:700}';
        echo '.topbar-ag__actions{margin-left:auto;display:flex;gap:8px}';
        echo 'h1{font-size:1.2rem;margin:14px 0 4px;color:#fff}h2{font-size:1.05rem;margin:16px 0 8px;color:#0A0E17}';
        echo '.grid{display:grid;grid-template-columns:1fr;gap:16px;align-items:start;margin-top:12px}';
        echo '@media (min-width:900px){.grid{grid-template-columns:1fr 1.4fr}}';
        echo '.card{background:#FFFFFF;color:#0A0E17;border:none;border-radius:16px;padding:16px;box-shadow:0 8px 24px rgba(0,0,0,0.3)}';
        echo '.card h2{color:#0A0E17}';
        echo 'label{display:block;font-size:.78rem;color:#4B5563;margin:10px 0 4px;font-weight:600}';
        echo 'input,select,textarea{width:100%;background:#F3F4F6;color:#0A0E17;border:1px solid #D1D5DB;padding:11px;border-radius:10px;font-size:.9rem;box-sizing:border-box;min-height:44px}';
        echo 'input[type=checkbox]{width:auto;min-height:auto}';
        echo '.btn{background:linear-gradient(135deg,#F97316,#ea580c);color:#fff;border:none;padding:12px 16px;border-radius:12px;font-weight:800;cursor:pointer;min-height:44px;font-size:.9rem;box-shadow:0 4px 14px rgba(249,115,22,0.35)}';
        echo '.btn-sec{background:#2563EB;color:#fff;box-shadow:0 4px 14px rgba(29,78,216,0.3)}.btn-danger{background:#dc2626;color:#fff}';
        echo 'button:focus-visible,a:focus-visible{outline:3px solid #2563EB;outline-offset:2px}';
        echo '.tblwrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #E5E7EB;border-radius:12px}';
        echo 'table{width:100%;border-collapse:collapse;font-size:.78rem;color:#111827;min-width:560px}';
        echo 'th,td{padding:10px 8px;border-bottom:1px solid #E5E7EB;text-align:left;vertical-align:top;white-space:nowrap}';
        echo 'th{color:#4B5563;font-weight:700;text-transform:uppercase;font-size:.68rem;letter-spacing:.5px}';
        echo '.tag{font-size:.68rem;border-radius:99px;padding:2px 8px;border:1px solid #D1D5DB;color:#374151;background:#F9FAFB}';
        echo '.rowwrap{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}';
        echo '#msg{font-size:.8rem;color:#16a34a;min-height:18px;margin-top:8px;font-weight:600}';
        echo '.bottomnav{position:fixed;left:0;right:0;bottom:0;z-index:60;display:grid;grid-template-columns:repeat(4,1fr);background:rgba(10,14,23,0.96);backdrop-filter:blur(12px);border-top:1px solid rgba(37,99,235,0.35);padding:6px 8px calc(6px + env(safe-area-inset-bottom))}';
        echo '.bottomnav a{min-height:48px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;color:#93A3C8;text-decoration:none;font-size:.68rem;font-weight:700;border-radius:12px}';
        echo '.bottomnav a span:first-child{font-size:1.25rem}.bottomnav a.is-active{color:#F97316;background:rgba(249,115,22,0.12)}';
        echo '@media (min-width:900px){.bottomnav{display:none}body{padding-bottom:20px}}';
        echo '</style></head><body>';
        $agInitials = mb_strtoupper(mb_substr((string)$user['username'], 0, 1) . mb_substr((string)$user['username'], 1, 1));
        echo '<div class="topbar-ag">';
        echo '<div class="topbar-ag__avatar" aria-hidden="true">' . htmlspecialchars($agInitials, ENT_QUOTES) . '</div>';
        echo '<div><div class="topbar-ag__name">' . htmlspecialchars((string)$user['username'], ENT_QUOTES) . '</div>';
        echo '<div class="topbar-ag__sub">DASHBOARD GAME — CHAT & MISI</div></div>';
        echo '<div class="topbar-ag__actions">';
        echo '<a href="/admin" class="btn btn-sec" style="text-decoration:none;font-size:.8rem;padding:10px 14px">🛡️ Admin Utama</a>';
        echo '<a href="/" class="btn" style="text-decoration:none;font-size:.8rem;padding:10px 14px">🏠 Beranda</a>';
        echo '</div></div>';
        echo '<div class="wrap">';
        echo '<h1>🎮 Dashboard Game — Chat &amp; Misi</h1><div style="font-size:.78rem;color:#93A3C8;margin-bottom:4px">Halo ' . htmlspecialchars((string)$user['username'], ENT_QUOTES) . ' • perubahan berlaku realtime di Dunia 3D &amp; Pixel World</div>';
        echo '<div id="msg"></div>';

        echo '<div class="grid" style="margin-top:12px">';

        // ================= KIRI: CHAT =================
        if ($canChat) {
            echo '<div class="card"><h2>💬 Chat in-game</h2>';
            echo '<label style="display:flex;align-items:center;gap:8px;font-size:.85rem;color:#0A0E17"><input type="checkbox" id="chEnabled" style="width:auto"' . ($settings['enabled'] ? ' checked' : '') . '> Chat aktif</label>';
            echo '<label>Mode kanal</label><select id="chMode">';
            $modes = ['global' => 'Global saja', 'global_area' => 'Global + Area', 'global_area_private' => 'Global + Area + Private'];
            foreach ($modes as $mk => $mv) {
                echo '<option value="' . $mk . '"' . ($settings['mode'] === $mk ? ' selected' : '') . '>' . $mv . '</option>';
            }
            echo '</select>';
            echo '<label>Jeda antar pesan (detik, 1-30)</label><input type="number" id="chCooldown" min="1" max="30" value="' . max(1, min(30, $settings['cooldown'])) . '">';
            echo '<label style="display:flex;align-items:center;gap:8px;font-size:.85rem;color:#0A0E17"><input type="checkbox" id="chFilter" style="width:auto"' . ($settings['filter'] ? ' checked' : '') . '> Filter kata terlarang</label>';
            echo '<label>Daftar kata terlarang (dipisah koma)</label><textarea id="chBlocklist" rows="3">' . htmlspecialchars(implode(', ', $settings['blocklist']), ENT_QUOTES) . '</textarea>';
            echo '<div class="rowwrap"><button class="btn" id="chatSave">💾 Simpan Pengaturan Chat</button></div>';
            echo '<h2 style="margin-top:18px">🛡️ Antrean Moderasi (60 terbaru)</h2><div class="tblwrap" style="max-height:330px;overflow-y:auto"><table id="modTable"><thead><tr><th>Pengguna</th><th>Kanal</th><th>Pesan</th><th></th></tr></thead><tbody><tr><td colspan="4" style="color:#94a3b8">Memuat…</td></tr></tbody></table></div></div>';
        }

        // ================= KANAN: MISSIONS =================
        if ($canMission) {
            echo '<div class="card"><h2>🎯 Kelola Misi</h2><div class="tblwrap" style="max-height:300px;overflow-y:auto">';
            echo '<table id="misTable"><thead><tr><th>Misi</th><th>Kategori</th><th>Hadiah</th><th>Status</th><th></th></tr></thead><tbody></tbody></table></div>';
            echo '<h2 style="margin-top:14px">✍️ ' . ($canMission ? 'Form Misi' : '') . '</h2>';
            echo '<input type="hidden" id="mId" value="0">';
            echo '<label>Nama misi (3-80)</label><input id="mTitle" maxlength="80">';
            echo '<label>Deskripsi (maks 600)</label><textarea id="mDesc" rows="2" maxlength="600"></textarea>';
            echo '<label>Kategori (bisa baru — daftar saran dari DB)</label><input id="mCategory" list="catList" maxlength="32"><datalist id="catList">';
            foreach ($cats as $c) { echo '<option value="' . htmlspecialchars($c, ENT_QUOTES) . '">'; }
            echo '</datalist>';
            echo '<label>Target misi</label><select id="mTarget"><option value="all">Semua (langsung aktif)</option><option value="location">Lokasi (harus dikunjungi dulu)</option></select>';
            echo '<label>Lokasi target</label><select id="mLocation"><option value="">— pilih lokasi —</option>';
            foreach ($locations as $l) { echo '<option value="' . htmlspecialchars($l['id'], ENT_QUOTES) . '">' . htmlspecialchars($l['name'], ENT_QUOTES) . '</option>'; }
            echo '</select>';
            echo '<label>Hadiah XP (0-10000)</label><input type="number" id="mXp" min="0" max="10000" value="50">';
            echo '<label>Hadiah koin (0-10000)</label><input type="number" id="mCoin" min="0" max="10000" value="20">';
            echo '<label>Lencana (opsional)</label><select id="mBadge"><option value="">— tanpa lencana —</option>';
            foreach ($badges as $b) { echo '<option value="' . (int)$b['id'] . '">' . htmlspecialchars($b['icon_emoji'] . ' ' . $b['name'], ENT_QUOTES) . '</option>'; }
            echo '</select>';
            echo '<label>Urutan tampil (0-999)</label><input type="number" id="mOrder" min="0" max="999" value="0">';
            echo '<label style="display:flex;align-items:center;gap:8px;font-size:.85rem;color:#0A0E17"><input type="checkbox" id="mActive" style="width:auto" checked> Aktif sekali disimpan</label>';
            echo '<div class="rowwrap"><button class="btn" id="misSave">💾 Simpan Misi</button><button class="btn btn-sec" id="misReset">↺ Kosongkan Form</button></div></div>';
        }
        echo '</div>';

        // ============ ROOT: MANAJEMEN SERVER GAME (hapus server) ============
        $isRoot = ($user['role'] ?? '') === 'root';
        if ($isRoot) {
            $serversAll = \VC\Domain\GameServers::adminListAll();
            echo '<div class="card" style="margin-top:16px"><h2>🖥️ Server Game <span class="tag">ROOT</span></h2>';
            echo '<div style="font-size:.76rem;color:#94a3b8;margin-bottom:8px">Setiap server terisolasi (chat, misi, pemain per serverId). Hapus server akan menendang semua pemain keluar dari server tersebut.</div>';
            echo '<div class="tblwrap" style="max-height:340px;overflow-y:auto"><table id="srvTable"><thead><tr><th>ID</th><th>Nama</th><th>Tipe</th><th>Akses</th><th>Kode</th><th>Anggota</th><th>Online</th><th>Pemilik</th><th>Status</th><th></th></tr></thead><tbody>';
            if ($serversAll === []) {
                echo '<tr><td colspan="10" style="color:#94a3b8">Belum ada server game.</td></tr>';
            }
            foreach ($serversAll as $sv) {
                $badge = $sv['is_active']
                    ? '<span class="tag" style="border-color:#22c55e;color:#22c55e">aktif</span>'
                    : '<span class="tag" style="border-color:#64748b;color:#64748b">terhapus</span>';
                $delBtn = ($sv['is_active'] && $sv['type'] !== 'public')
                    ? '<button class="btn btn-danger" style="padding:4px 10px;min-height:30px" data-srvdel="' . (int)$sv['id'] . '" data-srvname="' . htmlspecialchars($sv['name'], ENT_QUOTES) . '">🗑 Hapus</button>'
                    : (($sv['type'] === 'public') ? '<span style="font-size:.7rem;color:#64748b">dilindungi</span>' : '');
                echo '<tr><td>' . (int)$sv['id'] . '</td><td>' . htmlspecialchars($sv['name'], ENT_QUOTES) . '</td>'
                    . '<td>' . htmlspecialchars($sv['type'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($sv['access'], ENT_QUOTES) . '</td>'
                    . '<td><code>' . htmlspecialchars($sv['invite_code'], ENT_QUOTES) . '</code></td>'
                    . '<td>' . (int)$sv['member_count'] . '/' . (int)$sv['capacity'] . '</td>'
                    . '<td>' . (int)$sv['online'] . '</td>'
                    . '<td>' . htmlspecialchars((string)$sv['owner_name'], ENT_QUOTES) . '</td>'
                    . '<td>' . $badge . '</td><td>' . $delBtn . '</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }

        $dataJson = json_encode([
            'missions' => $missions,
            'badges' => $badges,
            'categories' => $cats,
            'locations' => $locations,
        ], JSON_UNESCAPED_UNICODE);

        echo '<script nonce="' . $nonce . '">';
        echo 'window.__GAD = ' . $dataJson . ';';
        echo <<<'GAJS'
var csrf = document.querySelector('meta[name=csrf]').content;
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
var MSG = document.getElementById('msg');
function say(m, isErr){ MSG.textContent = m; MSG.style.color = isErr ? '#f87171' : '#22c55e'; clearTimeout(window.__sayT); window.__sayT = setTimeout(function(){ MSG.textContent=''; }, 5000); }
function post(url, body){ return fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF':csrf}, body: JSON.stringify(Object.assign({_csrf:csrf}, body)) }).then(function(r){ return r.json(); }); }

// ---------- CHAT SETTINGS ----------
var cs = document.getElementById('chatSave');
if (cs) cs.addEventListener('click', function(){
  post('/api/admin/chat/settings', {
    enabled: document.getElementById('chEnabled').checked,
    mode: document.getElementById('chMode').value,
    cooldown: parseInt(document.getElementById('chCooldown').value, 10) || 2,
    filter: document.getElementById('chFilter').checked,
    blocklist: document.getElementById('chBlocklist').value
  }).then(function(d){ say(d.status === 'ok' ? 'Pengaturan chat tersimpan — berlaku seketika.' : (d.message || 'Gagal'), d.status !== 'ok'); });
});

// ---------- MODERATION QUEUE ----------
var modBody = document.querySelector('#modTable tbody');
if (modBody) {
  fetch('/api/admin/chat/recent?limit=60', { headers: { 'X-Requested-With': 'fetch' } })
    .then(function(r){ return r.json(); })
    .then(function(d){
      modBody.innerHTML = '';
      if (d.status !== 'ok') { modBody.innerHTML = '<tr><td colspan="4" style="color:#94a3b8">🔒 ' + esc(d.message || 'Moderasi chat hanya untuk pemilik.') + '</td></tr>'; return; }
      (d.messages || []).forEach(function(m){
        var tr = document.createElement('tr');
        var gone = !!m.deleted_at;
        tr.innerHTML = '<td><b>' + esc(m.username) + '</b></td><td class="tag">' + esc(m.scope) + '</td><td' + (gone ? ' style="opacity:.75"><s>' + esc(m.text) + '</s>' : '>' + esc(m.text)) + '</td>' +
          '<td>' + (gone ? '<span style="color:#fbbf24;font-size:.72rem">🗑️ dihapus' + (m.deleted_by_name ? ' oleh ' + esc(m.deleted_by_name) : '') + '</span>'
            : '<button class="btn btn-danger" data-del="' + m.id + '" style="padding:4px 9px;font-size:.72rem">Hapus</button>') + '</td>';
        modBody.appendChild(tr);
      });
      if (!(d.messages || []).length) modBody.innerHTML = '<tr><td colspan="4" style="color:#94a3b8">Belum ada pesan.</td></tr>';
      modBody.querySelectorAll('[data-del]').forEach(function(b){
        b.addEventListener('click', function(){
          if (!confirm('Hapus pesan ini?')) return;
          post('/api/game/chat/moderate', { message_id: parseInt(b.getAttribute('data-del'), 10) })
            .then(function(d){ if (d.status === 'ok') { var td = b.parentNode; td.previousElementSibling.innerHTML = '<s>' + td.previousElementSibling.innerHTML + '</s>'; td.innerHTML = '<span style="color:#fbbf24;font-size:.72rem">🗑️ dihapus</span>'; say('Pesan dihapus — tetap tercatat untuk moderator.'); } else say(d.message || 'Gagal menghapus.', true); });
        });
      });
    });
}

// ---------- MISSIONS CRUD ----------
function missionRow(m){
  var tr = document.createElement('tr');
  tr.innerHTML = '<td><b>' + esc(m.title) + '</b>' + (m.target_type === 'location' ? '<br><span style="color:#22d3ee;font-size:.7rem">📍 ' + esc(m.target_value) + '</span>' : '') + '</td>' +
    '<td class="tag">' + esc(m.category) + '</td>' +
    '<td>' + m.xp_reward + ' XP • ' + m.coin_reward + ' koin' + (m.badge_name ? '<br>🏅 ' + esc(m.badge_name) : '') + '</td>' +
    '<td><span style="color:' + (m.is_active ? '#22c55e' : '#94a3b8') + '">' + (m.is_active ? 'aktif' : 'nonaktif') + '</span></td>' +
    '<td><button class="btn btn-sec" style="padding:4px 9px;font-size:.72rem" data-edit="' + m.id + '">Ubah</button></td>';
  tr.querySelector('[data-edit]').addEventListener('click', function(){
    ['id','title','description','category','target_type','target_value','xp_reward','coin_reward','badge_id','is_active','order_num'].forEach(function(k){
      window['__mis_' + k] = m[k] === null ? '' : m[k];
    });
    document.getElementById('mId').value = m.id;
    document.getElementById('mTitle').value = m.title || '';
    document.getElementById('mDesc').value = m.description || '';
    document.getElementById('mCategory').value = m.category || '';
    document.getElementById('mTarget').value = m.target_type || 'all';
    document.getElementById('mLocation').value = m.target_value || '';
    document.getElementById('mXp').value = m.xp_reward || 0;
    document.getElementById('mCoin').value = m.coin_reward || 0;
    document.getElementById('mBadge').value = m.badge_id || '';
    document.getElementById('mOrder').value = m.order_num || 0;
    document.getElementById('mActive').checked = !!m.is_active;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  return tr;
}
function fillMissionTable(){
  var tb = document.querySelector('#misTable tbody');
  if (!tb) return;
  tb.innerHTML = '';
  (window.__GAD.missions || []).forEach(function(m){ tb.appendChild(missionRow(m)); });
}
fillMissionTable();

var misSave = document.getElementById('misSave');
if (misSave) misSave.addEventListener('click', function(){
  var payload = {
    id: parseInt(document.getElementById('mId').value, 10) || 0,
    title: document.getElementById('mTitle').value,
    description: document.getElementById('mDesc').value,
    category: document.getElementById('mCategory').value.trim().toLowerCase(),
    target_type: document.getElementById('mTarget').value,
    target_value: document.getElementById('mTarget').value === 'location' ? document.getElementById('mLocation').value : '',
    xp_reward: parseInt(document.getElementById('mXp').value, 10) || 0,
    coin_reward: parseInt(document.getElementById('mCoin').value, 10) || 0,
    badge_id: parseInt(document.getElementById('mBadge').value, 10) || 0,
    order_num: parseInt(document.getElementById('mOrder').value, 10) || 0,
    is_active: document.getElementById('mActive').checked
  };
  post('/api/admin/missions/save', payload).then(function(d){
    if (d.status === 'ok') {
      say(d.action === 'created' ? 'Misi baru dibuat (id ' + d.id + ').' : 'Misi diperbarui (id ' + d.id + ').');
      if (window.__GAD.categories.indexOf(payload.category) < 0) window.__GAD.categories.push(payload.category);
      reloadMissions();
    } else { say(d.message || 'Gagal menyimpan.', true); }
  });
});
function reloadMissions(){
  fetch('/api/admin/missions/list').then(function(r){ return r.json(); }).then(function(d){
    if (d.status === 'ok') { window.__GAD.missions = d.missions; fillMissionTable(); }
  }).catch(function(){ say('Gagal memuat daftar misi terbaru.', true); });
}
var misReset = document.getElementById('misReset');
if (misReset) misReset.addEventListener('click', function(){
  document.getElementById('mId').value = 0;
  ['mTitle','mDesc','mCategory'].forEach(function(i){ document.getElementById(i).value = ''; });
  document.getElementById('mTarget').value = 'all';
  document.getElementById('mLocation').value = '';
  document.getElementById('mXp').value = 50;
  document.getElementById('mCoin').value = 20;
  document.getElementById('mBadge').value = '';
  document.getElementById('mOrder').value = 0;
  document.getElementById('mActive').checked = true;
});
// ROOT — hapus server game: konfirmasi → POST /api/servers/delete → refresh.
document.querySelectorAll('[data-srvdel]').forEach(function(b){
  b.addEventListener('click', function(){
    var sid = parseInt(b.getAttribute('data-srvdel'), 10);
    var nm = b.getAttribute('data-srvname') || ('ID ' + sid);
    if (!confirm('Hapus server "' + nm + '"?\\nSemua pemain di server ini akan dikeluarkan dan server tidak lagi muncul di daftar.')) return;
    b.disabled = true;
    post('/api/servers/delete', { server_id: sid }).then(function(d){
      if (d.status === 'ok') { say('Server "' + nm + '" dihapus (' + (d.ws_kicked >= 0 ? d.ws_kicked + ' pemain dikick' : 'WS tidak dihubungi') + ').'); setTimeout(function(){ location.reload(); }, 900); }
      else { say(d.message || 'Gagal menghapus server.', true); b.disabled = false; }
    }).catch(function(){ say('Gagal menghubungi server.', true); b.disabled = false; });
  });
});
GAJS;
        echo '</script>';
        echo '</div>';
        // Bottom navigation mobile admin game.
        echo '<nav class="bottomnav" aria-label="Navigasi bawah admin game">';
        echo '<a href="/"><span>🏠</span><span>Beranda</span></a>';
        echo '<a href="/dunia"><span>🎮</span><span>Dunia 3D</span></a>';
        echo '<a href="/admin"><span>🛡️</span><span>Admin</span></a>';
        echo '<a href="/admin/game" class="is-active"><span>🖥️</span><span>Server</span></a>';
        echo '<a href="/profil"><span>👤</span><span>Profil</span></a>';
        echo '</nav>';
        echo '</body></html>';
    }
}
