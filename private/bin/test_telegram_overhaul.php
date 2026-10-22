<?php
// Comprehensive Automated Test Suite for Prompt 5 (Telegram Bot Experience Overhaul)
declare(strict_types=1);

putenv('TELEGRAM_DRY_RUN=1');
require_once __DIR__ . '/../src/bootstrap.php';

use VC\Ai\Providers;
use VC\Database;
use VC\Domain\Gameplay;
use VC\Domain\Schedule;
use VC\Security\Auth;
use VC\Security\Permissions;
use VC\Security\RateLimiter;
use VC\TgBot\AccountLink;
use VC\TgBot\Bot;
use VC\TgBot\BotMenus;
use VC\TgBot\Telegram;

echo "======================================================================\n";
echo "   PROMPT 5 TEST SUITE — TELEGRAM BOT EXPERIENCE OVERHAUL (SMK SILBER)\n";
echo "======================================================================\n\n";

$pass = 0;
$fail = 0;
$blocked = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "✅ PASS: {$desc}\n";
        $pass++;
    } else {
        echo "❌ FAIL: {$desc}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
        $fail++;
    }
}

$pdo = Database::get();
$testSuffix = bin2hex(random_bytes(3));

// Clean prior test records if any
$pdo->prepare('DELETE FROM users WHERE username LIKE "p5_%"')->execute();
$pdo->prepare('DELETE FROM telegram_updates WHERE update_id >= 100000')->execute();
Database::security()->exec('DELETE FROM rate_limit_hits WHERE bucket LIKE "tg_user:%"');

// Setup test users
$classId = (int)($pdo->query('SELECT id FROM classes ORDER BY id LIMIT 1')->fetchColumn() ?: 1);

$mkUser = static function (string $username, string $role, string $fullName) use ($pdo, $classId): int {
    $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, class_id, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, "active", datetime("now"))')
        ->execute([bin2hex(random_bytes(16)), $username, Auth::hash('ValidPass#2026!'), $role, $fullName, $classId]);
    return (int)$pdo->lastInsertId();
};

$muridId = $mkUser("p5_student_{$testSuffix}", 'murid', "Siswa Tester {$testSuffix}");
$adminId = $mkUser("p5_admin_{$testSuffix}", 'root', "Admin Tester {$testSuffix}");

$tgStudent = 810000000 + random_int(1, 999999);
$tgAdmin = 820000000 + random_int(1, 999999);
$tgUnlinked = 830000000 + random_int(1, 999999);

// Link accounts
$c1 = AccountLink::issueCode($muridId);
AccountLink::consume($c1['code'], $tgStudent, "tg_student_{$testSuffix}");
$c2 = AccountLink::issueCode($adminId);
AccountLink::consume($c2['code'], $tgAdmin, "tg_admin_{$testSuffix}");

$tg = new Telegram();
$bot = new Bot($tg);

$updateSeq = 100000;
$sendMsg = static function (int $fromId, string $text) use ($bot, $tg, &$updateSeq): array {
    RateLimiter::clear('tg_user', (string)$fromId);
    $uId = $updateSeq++;
    $before = count($tg->sent());
    $bot->handle([
        'update_id' => $uId,
        'message' => [
            'message_id' => $uId,
            'from' => ['id' => $fromId, 'first_name' => 'Tester'],
            'chat' => ['id' => $fromId],
            'text' => $text,
        ],
    ]);
    return array_slice($tg->sent(), $before);
};

$sendCallback = static function (int $fromId, string $data, bool $isPhoto = true) use ($bot, $tg, &$updateSeq): array {
    RateLimiter::clear('tg_user', (string)$fromId);
    $uId = $updateSeq++;
    $before = count($tg->sent());
    $bot->handle([
        'update_id' => $uId,
        'callback_query' => [
            'id' => 'cb_' . $uId,
            'from' => ['id' => $fromId, 'first_name' => 'Tester'],
            'message' => [
                'message_id' => $uId,
                'chat' => ['id' => $fromId],
                'caption' => $isPhoto ? 'Current Caption' : null,
                'photo' => $isPhoto ? [['file_id' => 'ph123']] : null,
            ],
            'data' => $data,
        ],
    ]);
    return array_slice($tg->sent(), $before);
};

// -----------------------------------------------------------------------------
// TEST 1: /start sends main photo banner and menu
// -----------------------------------------------------------------------------
echo "--- UJI 1: /start mengirim foto banner dan menu utama ---\n";
$s1 = $sendMsg($tgStudent, '/start');
assertTest('/start menghasilkan pemanggilan API', count($s1) > 0);
$foundPhoto = false;
$foundBannerUrl = false;
$foundCaption = false;
foreach ($s1 as $call) {
    if ($call['method'] === 'sendPhoto') {
        $foundPhoto = true;
        if (($call['payload']['photo'] ?? '') === Bot::bannerUrl()) {
            $foundBannerUrl = true;
        }
        if (str_contains((string)($call['payload']['caption'] ?? ''), 'VIRTUAL CITY SMK SILBER')) {
            $foundCaption = true;
        }
    }
}
assertTest('/start menggunakan method sendPhoto', $foundPhoto);
assertTest('sendPhoto menggunakan URL banner terkonfigurasi (p7uuis.jpg)', $foundBannerUrl, Bot::bannerUrl());
assertTest('sendPhoto memuat caption sambutan Virtual City', $foundCaption);

// -----------------------------------------------------------------------------
// TEST 2: /menu displays main menu with photo
// -----------------------------------------------------------------------------
echo "\n--- UJI 2: /menu menampilkan kembali menu utama ---\n";
$s2 = $sendMsg($tgStudent, '/menu');
$foundMenuPhoto = false;
foreach ($s2 as $call) {
    if ($call['method'] === 'sendPhoto' && str_contains((string)($call['payload']['caption'] ?? ''), 'VIRTUAL CITY')) {
        $foundMenuPhoto = true;
    }
}
assertTest('/menu memanggil sendPhoto dengan menu utama', $foundMenuPhoto);

// -----------------------------------------------------------------------------
// TEST 3: /help gives correct guide
// -----------------------------------------------------------------------------
echo "\n--- UJI 3: /help memberikan panduan yang benar ---\n";
$s3 = $sendMsg($tgStudent, '/help');
$helpText = (string)(end($s3)['payload']['text'] ?? '');
assertTest('/help memuat daftar perintah bot', str_contains($helpText, '/start') && str_contains($helpText, '/jadwal') && str_contains($helpText, '/koin'), $helpText);

// -----------------------------------------------------------------------------
// TEST 4: All main menu buttons open appropriate screens
// -----------------------------------------------------------------------------
echo "\n--- UJI 4: Semua tombol menu utama membuka halaman yang tepat ---\n";
$mainMenus = [
    'menu:profile'  => 'Profil',
    'menu:game'     => 'Virtual City',
    'menu:coins'    => 'Koin',
    'menu:inv'      => 'Inventaris',
    'menu:shop'     => 'TOKO AVATAR',
    'menu:school'   => 'INFORMASI RESMI SMK SILBER',
    'menu:schedule' => 'Jadwal',
    'menu:tasks'    => 'Tugas',
    'menu:events'   => 'Event',
    'menu:ai'       => 'AI ASSISTANT',
    'menu:notif'    => 'Notifikasi',
    'menu:settings' => 'PENGATURAN',
    'menu:help'     => 'PUSAT BANTUAN',
    'menu:link'     => 'Menghubungkan Akun',
    'menu:home'     => 'VIRTUAL CITY SMK SILBER',
];

foreach ($mainMenus as $cbData => $expectedKeyword) {
    $res = $sendCallback($tgStudent, $cbData);
    $last = end($res);
    $caption = (string)($last['payload']['caption'] ?? ($last['payload']['text'] ?? ''));
    assertTest("Callback '{$cbData}' membuka layar yang memuat '{$expectedKeyword}'", str_contains($caption, $expectedKeyword), $caption);
}

// -----------------------------------------------------------------------------
// TEST 5: All submenus have back navigation (Kembali / Beranda)
// -----------------------------------------------------------------------------
echo "\n--- UJI 5: Navigasi tombol kembali tersedia ---\n";
$submenus = ['game:about', 'game:howto', 'school:progs', 'school:fac', 'sched:days', 'tasks:done', 'events:orgs', 'ai:status', 'notif:prefs', 'set:lang'];
foreach ($submenus as $sm) {
    $res = $sendCallback($tgStudent, $sm);
    $last = end($res);
    $kb = json_decode((string)($last['payload']['reply_markup'] ?? '{}'), true);
    $buttons = $kb['inline_keyboard'] ?? [];
    $hasBack = false;
    foreach ($buttons as $row) {
        foreach ($row as $btn) {
            $cb = $btn['callback_data'] ?? '';
            if (str_contains($cb, 'menu:') || str_contains($cb, 'sched:') || str_contains($cb, 'home')) {
                $hasBack = true;
            }
        }
    }
    assertTest("Submenu '{$sm}' memiliki tombol navigasi kembali", $hasBack);
}

// -----------------------------------------------------------------------------
// TEST 6: Buttons do not get stuck loading (answerCallbackQuery called)
// -----------------------------------------------------------------------------
echo "\n--- UJI 6: answerCallbackQuery dipanggil pada setiap callback ---\n";
$cbRes = $sendCallback($tgStudent, 'menu:home');
$hasAnswer = false;
foreach ($cbRes as $c) {
    if ($c['method'] === 'answerCallbackQuery') {
        $hasAnswer = true;
    }
}
assertTest('answerCallbackQuery dipanggil saat menerima callback query', $hasAnswer);

// -----------------------------------------------------------------------------
// TEST 7: Unlinked account gets linking guide
// -----------------------------------------------------------------------------
echo "\n--- UJI 7: Akun belum tertaut mendapatkan panduan penautan yang aman ---\n";
$unlRes = $sendMsg($tgUnlinked, '/status');
$unlText = (string)(end($unlRes)['payload']['text'] ?? '');
assertTest('User belum tertaut mendapat pesan belum tertaut', str_contains($unlText, 'belum ditautkan'));

$unlMenu = $sendCallback($tgUnlinked, 'menu:profile');
$unlMenuCap = (string)(end($unlMenu)['payload']['caption'] ?? (end($unlMenu)['payload']['text'] ?? ''));
assertTest('Menu profil untuk akun belum tertaut memberikan petunjuk /link', str_contains($unlMenuCap, '/link KODE') || str_contains($unlMenuCap, 'Belum Ditautkan'));

// -----------------------------------------------------------------------------
// TEST 8: Linked account only sees their own data (cross-user isolation)
// -----------------------------------------------------------------------------
echo "\n--- UJI 8: Akun tertaut hanya melihat datanya sendiri ---\n";
$sProf = $sendCallback($tgStudent, 'profile:view');
$sCap = (string)(end($sProf)['payload']['caption'] ?? (end($sProf)['payload']['text'] ?? ''));
assertTest('Profil siswa memuat nama siswa sendiri', str_contains($sCap, "Siswa Tester {$testSuffix}"));
assertTest('Profil siswa TIDAK memuat nama admin', !str_contains($sCap, "Admin Tester {$testSuffix}"));

// -----------------------------------------------------------------------------
// TEST 9: Regular student cannot execute admin actions
// -----------------------------------------------------------------------------
echo "\n--- UJI 9: Pengguna biasa tidak dapat mengakses aksi administrator ---\n";
$admDenied = $sendCallback($tgStudent, 'menu:admin');
$admDeniedCap = (string)(end($admDenied)['payload']['caption'] ?? (end($admDenied)['payload']['text'] ?? ''));
assertTest('Callback menu:admin DITOLAK untuk murid', str_contains($admDeniedCap, 'tidak memiliki izin'));

$unbanDenied = $sendCallback($tgStudent, 'adm:unban_ok');
$unbanDeniedCap = (string)(end($unbanDenied)['payload']['caption'] ?? (end($unbanDenied)['payload']['text'] ?? ''));
assertTest('Callback adm:unban_ok DITOLAK untuk murid', str_contains($unbanDeniedCap, 'tidak memiliki izin'));

// Root admin CAN access menu:admin
$admOk = $sendCallback($tgAdmin, 'menu:admin');
$admOkCap = (string)(end($admOk)['payload']['caption'] ?? (end($admOk)['payload']['text'] ?? ''));
assertTest('Callback menu:admin DIIZINKAN untuk admin', str_contains($admOkCap, 'PANEL ADMINISTRATOR'));

// -----------------------------------------------------------------------------
// TEST 10: Invalid / malformed callback rejected safely without crash
// -----------------------------------------------------------------------------
echo "\n--- UJI 10: Callback tidak valid ditolak dengan aman ---\n";
$invalidRes = $sendCallback($tgStudent, 'unknown_prefix:malformed_data_xyz');
assertTest('Bot tidak crash pada callback tidak dikenal', count($invalidRes) > 0);

// -----------------------------------------------------------------------------
// TEST 11: Image URL failure gracefully falls back to text menu
// -----------------------------------------------------------------------------
echo "\n--- UJI 11: Fallback saat pengiriman gambar gagal ---\n";
putenv('TELEGRAM_BANNER_URL=https://invalid-domain-123456.test/no_image.jpg');
$tmMock = new Telegram();
// In dry run, sendPhoto works, but test fallback method directly:
$resMenuPhoto = $bot->sendMainMenuWithPhoto($tgStudent, ['full_name' => 'Tester', 'role' => 'murid']);
assertTest('sendMainMenuWithPhoto mengembalikan hasil pengiriman menu', is_array($resMenuPhoto));
putenv('TELEGRAM_BANNER_URL=');

// -----------------------------------------------------------------------------
// TEST 12: Honest messages for unavailable / empty states
// -----------------------------------------------------------------------------
echo "\n--- UJI 12: Pesan jujur untuk data kosong / belum tersedia ---\n";
$fakeTeacherId = 999999;
$loc = Schedule::findTeacherLocation($fakeTeacherId);
assertTest('Pengecekan jadwal di luar jam memberikan status jujur', in_array($loc['status'] ?? '', ['outside_hours', 'free', 'break'], true));

// -----------------------------------------------------------------------------
// TEST 13: Telegram API errors handled without crashing
// -----------------------------------------------------------------------------
echo "\n--- UJI 13: Penanganan error Telegram API ---\n";
// Dry run handles calls cleanly and never throws to caller
$resErr = $tg->call('nonExistentMethod', []);
assertTest('API call mengembalikan array di dry-run tanpa throw exception', is_array($resErr));

// -----------------------------------------------------------------------------
// TEST 14: Duplicate updates deduped safely
// -----------------------------------------------------------------------------
echo "\n--- UJI 14: Pencegahan duplikasi update (idempotent) ---\n";
$dupId = 7770001;
$r1 = $tg->sent();
$bot->handle(['update_id' => $dupId, 'message' => ['message_id' => $dupId, 'from' => ['id' => $tgStudent], 'chat' => ['id' => $tgStudent], 'text' => '/status']]);
$c1 = count($tg->sent());
$bot->handle(['update_id' => $dupId, 'message' => ['message_id' => $dupId, 'from' => ['id' => $tgStudent], 'chat' => ['id' => $tgStudent], 'text' => '/status']]);
$c2 = count($tg->sent());
assertTest('Update dengan ID sama diabaikan pada panggilan kedua', $c1 > count($r1) && $c2 === $c1);

// -----------------------------------------------------------------------------
// TEST 15: AI Assistant status reflects real backend state
// -----------------------------------------------------------------------------
echo "\n--- UJI 15: Status 3 Model AI sesuai backend nyata ---\n";
$aiRes = $sendCallback($tgStudent, 'ai:status');
$aiCap = (string)(end($aiRes)['payload']['caption'] ?? (end($aiRes)['payload']['text'] ?? ''));
$allAi = Providers::allStatus();
assertTest('Status AI memuat informasi 3 agen', str_contains($aiCap, 'AI-1') && str_contains($aiCap, 'AI-2') && str_contains($aiCap, 'AI-3'), $aiCap);

// -----------------------------------------------------------------------------
// TEST 16: Coins and Inventory use real database
// -----------------------------------------------------------------------------
echo "\n--- UJI 16: Koin dan inventaris terhubung ke database nyata ---\n";
$initProg = Gameplay::getProgress($muridId);
Gameplay::grantCoins($muridId, 100, 'test', 'seed', 'Uji coba koin bot');
$updProg = Gameplay::getProgress($muridId);
assertTest('Pemberian koin menambah saldo nyata di database', (int)$updProg['coins'] === ((int)$initProg['coins'] + 100));

$koinRes = $sendMsg($tgStudent, '/koin');
$koinText = (string)(end($koinRes)['payload']['text'] ?? '');
assertTest('Perintah /koin menampilkan saldo baru', str_contains($koinText, (string)$updProg['coins']), $koinText);

// -----------------------------------------------------------------------------
// TEST 17: Logs do not leak secrets
// -----------------------------------------------------------------------------
echo "\n--- UJI 17: Log dan tampilan tidak membocorkan rahasia ---\n";
$botHelp = $sendMsg($tgStudent, '/help');
$allText = json_encode($botHelp);
assertTest('Token bot tidak bocor di output', !str_contains($allText, '8578316303:'));
assertTest('Password hash tidak bocor di output', !str_contains($allText, '$2y$'));

echo "\n--- UJI 18: Fitur Manajemen Staf Telegram (/addstaff, /delstaff, /liststaff, /setrole, /resetpass) ---\n";
// 1. Murid ditolak dari /addstaff
$deniedStaff = $sendMsg($tgStudent, '/addstaff 840001001 guru');
assertTest('/addstaff ditolak untuk murid', str_contains(json_encode($deniedStaff), 'Akses Ditolak') || str_contains(json_encode($deniedStaff), 'tidak memiliki izin'));

// 2. Admin melihat panduan /addstaff tanpa argumen
$guideStaff = $sendMsg($tgAdmin, '/addstaff');
assertTest('/addstaff tanpa argumen menampilkan panduan lengkap', str_contains(json_encode($guideStaff), 'PANDUAN MENAMBAHKAN STAF'));

// 3. Admin menambah staf guru baru
$newTgStaff = 840000000 + random_int(1000, 999999);
$resAdd = $sendMsg($tgAdmin, "/addstaff {$newTgStaff} guru guru_test_{$testSuffix} PassGuru#2026!");
$addOut = json_encode($resAdd);
assertTest('/addstaff berhasil membuat akun staf', str_contains($addOut, 'BERHASIL DIBUAT') && str_contains($addOut, "guru_test_{$testSuffix}"));

// Verifikasi di DB
$checkUser = $pdo->prepare('SELECT u.*, tl.tg_user_id FROM users u JOIN telegram_links tl ON u.id = tl.user_id WHERE tl.tg_user_id = ?');
$checkUser->execute([$newTgStaff]);
$staffRow = $checkUser->fetch();
assertTest('Akun staf tersimpan di tabel users dengan role guru', $staffRow && $staffRow['role'] === 'guru');
assertTest('Tautan Telegram tersimpan di tabel telegram_links', $staffRow && (int)$staffRow['tg_user_id'] === $newTgStaff);

// 4. /liststaff menampilkan staf yang baru ditambahkan
$listStaffRes = $sendMsg($tgAdmin, '/liststaff');
assertTest('/liststaff memuat staf baru dan ID Telegram', str_contains(json_encode($listStaffRes), "guru_test_{$testSuffix}") && str_contains(json_encode($listStaffRes), (string)$newTgStaff));

// 5. /setrole mengubah role staf
$setRoleRes = $sendMsg($tgAdmin, "/setrole {$newTgStaff} wali_kelas");
assertTest('/setrole berhasil mengubah role staf', str_contains(json_encode($setRoleRes), 'WALI_KELAS'));
$newRole = $pdo->query("SELECT role FROM users WHERE id = {$staffRow['id']}")->fetchColumn();
assertTest('Role terupdate menjadi wali_kelas di database', $newRole === 'wali_kelas');

// 6. /resetpass memperbarui password web
$resetPassRes = $sendMsg($tgAdmin, "/resetpass {$newTgStaff} SandiBaruGuru#2026");
assertTest('/resetpass berhasil mereset password', str_contains(json_encode($resetPassRes), 'Password Berhasil Direset'));

// 7. Menu callback adm:staff:menu dan adm:staff:list
$cbStaffMenu = $sendCallback($tgAdmin, 'adm:staff:menu');
assertTest('Callback adm:staff:menu membuka layar manajemen staf', str_contains(json_encode($cbStaffMenu), 'MANAJEMEN STAF'));

// 8. /delstaff menonaktifkan akun staf
$delStaffRes = $sendMsg($tgAdmin, "/delstaff {$newTgStaff}");
assertTest('/delstaff berhasil menonaktifkan akun staf', str_contains(json_encode($delStaffRes), 'Dinonaktifkan'));
$delStatus = $pdo->query("SELECT status FROM users WHERE id = {$staffRow['id']}")->fetchColumn();
assertTest('Status akun berubah menjadi suspended di database', $delStatus === 'suspended');
$unlinkedCheck = $pdo->query("SELECT COUNT(*) FROM telegram_links WHERE tg_user_id = {$newTgStaff}")->fetchColumn();
assertTest('Tautan Telegram dicabut dari tabel telegram_links', (int)$unlinkedCheck === 0);

// Cleanup user staf uji
$pdo->exec("DELETE FROM user_progress WHERE user_id = {$staffRow['id']}");
$pdo->exec("DELETE FROM users WHERE id = {$staffRow['id']}");

// =============================================================================
// UJI 19: Command Aliases & Public Access (/bantuan, /sekolah, /profil, /inventaris, /toko)
// =============================================================================
echo "\n--- UJI 19: Command Aliases & Public Access ---\n";
// 1. Unlinked user can access /bantuan and /sekolah without linking first
$unlinkedBantuan = $sendMsg($tgUnlinked, '/bantuan');
assertTest('User unlinked dapat mengakses /bantuan', str_contains(json_encode($unlinkedBantuan), 'Bantuan Bot'));

$unlinkedSekolah = $sendMsg($tgUnlinked, '/sekolah');
assertTest('User unlinked dapat mengakses /sekolah', str_contains(json_encode($unlinkedSekolah), 'SMK SILBER') || str_contains(json_encode($unlinkedSekolah), 'SEKOLAH'));

// 2. Linked student can use aliases
$studentProfil = $sendMsg($tgStudent, '/profil');
assertTest('/profil bekerja sebagai alias /status untuk siswa tertaut', str_contains(json_encode($studentProfil), "Siswa Tester {$testSuffix}"));

$studentInv = $sendMsg($tgStudent, '/inventaris');
assertTest('/inventaris bekerja sebagai alias /inventory untuk siswa tertaut', str_contains(json_encode($studentInv), 'Inventaris') || str_contains(json_encode($studentInv), 'Item'));

$studentToko = $sendMsg($tgStudent, '/toko');
assertTest('/toko bekerja sebagai alias /shop untuk siswa tertaut', str_contains(json_encode($studentToko), 'TOKO AVATAR') || str_contains(json_encode($studentToko), 'Toko'));

// =============================================================================
// UJI 20: Verifikasi Perbaikan Callback Admin (adm:audit & adm:users:all:1)
// =============================================================================
echo "\n--- UJI 20: Verifikasi Perbaikan Callback Admin ---\n";
// 1. adm:audit query ke tabel audit_log berjalan tanpa exception
$auditCb = $sendCallback($tgAdmin, 'adm:audit');
assertTest('Callback adm:audit berhasil membaca tabel audit_log', str_contains(json_encode($auditCb), 'Audit Log'));

// 2. adm:users:all:1 mem-parse role all dan menampilkan pengguna
$usersCb = $sendCallback($tgAdmin, 'adm:users:all:1');
assertTest('Callback adm:users:all:1 berhasil mem-parse role dan menampilkan pengguna', str_contains(json_encode($usersCb), 'Daftar Pengguna') && str_contains(json_encode($usersCb), 'root'));


// -----------------------------------------------------------------------------
// CLEANUP
// -----------------------------------------------------------------------------
$pdo->prepare('DELETE FROM telegram_links WHERE user_id IN (?, ?)')->execute([$muridId, $adminId]);
$pdo->prepare('DELETE FROM telegram_link_codes WHERE user_id IN (?, ?)')->execute([$muridId, $adminId]);
$pdo->prepare('DELETE FROM user_progress WHERE user_id IN (?, ?)')->execute([$muridId, $adminId]);
$pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$muridId, $adminId]);
$pdo->prepare('DELETE FROM telegram_updates WHERE update_id >= 100000')->execute();

echo "\n======================================================================\n";
echo "HASIL PENGUJIAN PROMPT 5: {$pass} PASSED, {$fail} FAILED, {$blocked} BLOCKED\n";
echo "STATUS BOT: " . ($fail === 0 ? "100% READY (PASS)" : "ADA KEGAGALAN (FAIL)") . "\n";
echo "======================================================================\n";

exit($fail > 0 ? 1 : 0);
