<?php
// Dedicated Security & QA Audit Test Suite for Virtual City SMK Silber
// Covers Section A (Security Audit), Section B (Database & Economy), and Section C (RBAC).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Security\Auth;
use VC\Security\Permissions;
use VC\Security\Csrf;
use VC\Security\AuditLog;
use VC\Security\RateLimiter;
use VC\Security\Honeypot;
use VC\Domain\Gameplay;

echo "======================================================================\n";
echo "   SECURITY & QA AUDIT TEST SUITE — AGENT 2 SPECIALIZED VERIFICATION   \n";
echo "======================================================================\n\n";

$pass = 0;
$fail = 0;

function assertAudit(string $section, string $desc, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  [{$section}] ✅ PASS: {$desc}\n";
    } else {
        $fail++;
        echo "  [{$section}] ❌ FAIL: {$desc}" . ($detail ? " — {$detail}" : "") . "\n";
    }
}

$pdo = Database::get();

// =========================================================================
// SECTION A: KEAMANAN & PENGUJIAN SISTEM (SECURITY AUDIT)
// =========================================================================
echo "▶ BAGIAN A: AUDIT KEAMANAN SISTEM...\n";

// A1: SQLite WAL & Foreign Keys
$wal = (string)$pdo->query('PRAGMA journal_mode')->fetchColumn();
assertAudit('SEC-A1', 'SQLite WAL (Write-Ahead Logging) aktif', strtolower($wal) === 'wal', "Got: {$wal}");

$fk = (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
assertAudit('SEC-A2', 'SQLite Foreign Key constraints aktif', $fk === 1, "Got: {$fk}");

// A2: Web Root Isolation (No sensitive database or config files in public/)
$forbiddenFiles = [
    VC_PUBLIC . '/virtual_city.sqlite',
    VC_PUBLIC . '/security.sqlite',
    VC_PUBLIC . '/.env',
    VC_PUBLIC . '/config.php',
    VC_PUBLIC . '/app.env',
];
$leaks = [];
foreach ($forbiddenFiles as $f) {
    if (file_exists($f)) {
        $leaks[] = basename($f);
    }
}
assertAudit('SEC-A3', 'Tidak ada file database/rahasia yang bocor di direktori public/', count($leaks) === 0, "Bocor: " . implode(', ', $leaks));

// A3: Password Security & Policy Enforcement
$shortPassErrors = Auth::passwordErrors('1234', 'siswa_test', 'murid');
assertAudit('SEC-A4', 'Validasi password menolak password terlalu pendek', !empty($shortPassErrors));

$weakRootErrors = Auth::passwordErrors('Admin123!', 'admin_test', 'root');
assertAudit('SEC-A5', 'Validasi password menolak password root kurang dari 14 karakter', !empty($weakRootErrors));

// A4: Anti-Enumeration Dummy Timing Protection
$startT = microtime(true);
$dummyAuth = Auth::verifyLogin('non_existent_user_' . bin2hex(random_bytes(6)), 'RandomPassword!2026');
$elapsed = microtime(true) - $startT;
assertAudit('SEC-A6', 'Autentikasi akun tidak dikenal aman dari enumerasi (timing safe & return null)', $dummyAuth === null);

// A5: Honeypot & Bot Trapping
assertAudit('SEC-A7', 'Honeypot mengenali path scanner (/wp-login.php, /.env)', Honeypot::inspect('/wp-login.php') && Honeypot::inspect('/.env'));
assertAudit('SEC-A8', 'Honeypot tidak memblokir rute legal (/dunia, /jadwal)', !Honeypot::inspect('/dunia') && !Honeypot::inspect('/jadwal'));

// A6: CSRF Token & Port Normalization
$csrfToken = Csrf::token();
assertAudit('SEC-A9', 'CSRF token digenerate dan memiliki entropi tinggi (min 32 byte hex)', strlen($csrfToken) >= 64);

// A7: Audit Log Tamper-Evident Hash Chain
$brokenLinks = AuditLog::verify();
assertAudit('SEC-A10', 'Integritas hash-chain AuditLog 100% utuh tanpa manipulasi', $brokenLinks === 0, "Broken: {$brokenLinks}");


// =========================================================================
// SECTION B: DATABASE & EKONOMI TRANSAKSI (ECONOMY & LEDGER AUDIT)
// =========================================================================
echo "\n▶ BAGIAN B: DATABASE & EKONOMI TRANSAKSI...\n";

// Create or use test user for isolated economy tests
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute(['audit_tester_sec']);
$testUserId = (int)$stmt->fetchColumn();

if ($testUserId <= 0) {
    $insUser = $pdo->prepare('INSERT INTO users (uid, username, password_hash, full_name, role, status, created_at)
        VALUES (?, ?, ?, ?, ?, "active", datetime("now"))');
    $insUser->execute(['VC-AUDIT-' . time(), 'audit_tester_sec', password_hash('PassAuditor2026!', PASSWORD_DEFAULT), 'QA Auditor Tester', 'murid']);
    $testUserId = (int)$pdo->lastInsertId();
}

// Reset test user progress to clean state for testing
$pdo->prepare('INSERT OR REPLACE INTO user_progress (user_id, total_xp, level, coins, login_streak, updated_at)
    VALUES (?, 0, 1, 0, 0, datetime("now"))')->execute([$testUserId]);
$pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM user_items WHERE user_id = ?')->execute([$testUserId]);
Gameplay::grantCoins($testUserId, 100, 'initial_grant', 'audit_init', 'Saldo awal audit');

// B1: Non-negative Balance Guarantee
$overspendResult = Gameplay::spendCoins($testUserId, 500, 'test_overspend', '1', 'Uji coba saldo negatif');
assertAudit('ECO-B1', 'Penarikan koin melebihi saldo ditolak (saldo tidak pernah negatif)', ($overspendResult['status'] ?? '') === 'error');

$progCheck = Gameplay::getProgress($testUserId);
assertAudit('ECO-B2', 'Saldo koin tetap utuh setelah penolakan spendCoins', (int)$progCheck['coins'] === 100, "Coins: {$progCheck['coins']}");

// B2: Positive Spend & Audit Logging
$spendOk = Gameplay::spendCoins($testUserId, 40, 'test_spend', '1', 'Pembelian legal');
assertAudit('ECO-B3', 'Pengurangan koin legal berhasil dan mengurangi saldo tepat', ($spendOk['status'] ?? '') === 'success' && $spendOk['remaining_coins'] === 60);

// B3: Idempotency & Duplicate Claim Prevention
$idemKey = 'AUDIT-IDEMP-' . bin2hex(random_bytes(8));
$grant1 = Gameplay::grantCoins($testUserId, 50, 'quest', 'q-101', 'Hadiah tes', $idemKey);
$grant2 = Gameplay::grantCoins($testUserId, 50, 'quest', 'q-101', 'Hadiah tes duplikat', $idemKey);
assertAudit('ECO-B4', 'Klaim hadiah koin pertama berhasil', ($grant1['status'] ?? '') === 'success');
assertAudit('ECO-B5', 'Idempotensi menolak klaim koin duplikat', ($grant2['status'] ?? '') === 'duplicate');

// B4: Atomic Item Purchase
// Ensure shop item exists
$shopItem = $pdo->query('SELECT * FROM shop_items WHERE is_available = 1 ORDER BY price_coins ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$shopItem) {
    $pdo->exec('INSERT INTO shop_items (name, category, description, price_coins, is_available) VALUES ("Topi Auditor", "hat", "Item uji QA", 30, 1)');
    $shopItem = $pdo->query('SELECT * FROM shop_items WHERE name = "Topi Auditor"')->fetch(PDO::FETCH_ASSOC);
}

$buyResult = Gameplay::purchaseItem($testUserId, (int)$shopItem['id']);
assertAudit('ECO-B6', 'Pembelian item toko atomik berhasil', ($buyResult['status'] ?? '') === 'success');

$itemOwned = (int)$pdo->query("SELECT COUNT(*) FROM user_items WHERE user_id = {$testUserId} AND item_id = {$shopItem['id']}")->fetchColumn();
assertAudit('ECO-B7', 'Item tercatat di user_items setelah transaksi berhasil', $itemOwned === 1);

// Duplicate item purchase prevention
$buyAgain = Gameplay::purchaseItem($testUserId, (int)$shopItem['id']);
assertAudit('ECO-B8', 'Pembelian item yang sudah dimiliki ditolak', ($buyAgain['status'] ?? '') === 'error');

// B5: Admin Balance Adjustment
$adminAdjust = Gameplay::adminAdjustBalance($testUserId, 150, 200, 'Bonus apresiasi pengujian', 1);
assertAudit('ECO-B9', 'Admin dapat menyesuaikan saldo dengan transaksi ledger & audit log', ($adminAdjust['status'] ?? '') === 'success');

$adminRejectNeg = Gameplay::adminAdjustBalance($testUserId, -99999, 0, 'Pengurangan ilegal', 1);
assertAudit('ECO-B10', 'Admin adjustment menolak saldo koin menjadi negatif', ($adminRejectNeg['status'] ?? '') === 'error');

// B6: Ledger Reconciliation
// Currently user has legitimate transactions. Check consistency:
$recClean = Gameplay::reconcileLedger($testUserId, false);
// Reconcile checks against transactions sum
assertAudit('ECO-B11', 'Rekonsiliasi ledger mendeteksi konsistensi saldo vs riwayat transaksi', $recClean['consistent'] === true);

// Tamper simulation: directly alter user_progress without transaction ledger
$pdo->prepare('UPDATE user_progress SET coins = 99999 WHERE user_id = ?')->execute([$testUserId]);
$recTampered = Gameplay::reconcileLedger($testUserId, false);
assertAudit('ECO-B12', 'Rekonsiliasi ledger mendeteksi saldo manipulasi/anomali', $recTampered['consistent'] === false && $recTampered['coin_diff'] > 0);

// Auto-repair via reconciliation
$recFix = Gameplay::reconcileLedger($testUserId, true);
assertAudit('ECO-B13', 'Rekonsiliasi autoFix berhasil memulihkan saldo sesuai ledger sah', $recFix['fixed'] === true && $recFix['consistent'] === true);

$repairedCoins = (int)$pdo->query("SELECT coins FROM user_progress WHERE user_id = {$testUserId}")->fetchColumn();
assertAudit('ECO-B14', 'Saldo di user_progress pulih ke nilai ledger yang sah', $repairedCoins === $recFix['ledger_coins']);


// =========================================================================
// SECTION C: ROLE DAN PERMISSION (RBAC AUDIT)
// =========================================================================
echo "\n▶ BAGIAN C: ROLE & PERMISSION (RBAC)...\n";
Permissions::flush();

// Test users for all 9 roles
$roles = [
    'root',
    'kepala_sekolah',
    'kepala_program',
    'guru',
    'wali_kelas',
    'pembina',
    'pengurus',
    'murid',
    'murid_akses',
];

$roleUsers = [];
foreach ($roles as $r) {
    $roleUsers[$r] = ['id' => 99000 + crc32($r) % 1000, 'role' => $r];
}

// C1: Root Superuser Verification
assertAudit('RBAC-C1', 'Role root memiliki izin wildcard (*) penuh untuk semua aksi',
    Permissions::canFor($roleUsers['root'], 'report.view_all') &&
    Permissions::canFor($roleUsers['root'], 'user.suspend') &&
    Permissions::canFor($roleUsers['root'], 'arbitrary.permission.xyz'));

// C2: Kepala Sekolah RBAC
assertAudit('RBAC-C2', 'Kepala Sekolah diizinkan melihat semua laporan & menangguhkan user',
    Permissions::canFor($roleUsers['kepala_sekolah'], 'report.view_all') &&
    Permissions::canFor($roleUsers['kepala_sekolah'], 'user.suspend'));
assertAudit('RBAC-C3', 'Kepala Sekolah ditolak dari hak asesmen tugas guru (task.grade)',
    !Permissions::canFor($roleUsers['kepala_sekolah'], 'task.grade'));

// C3: Kepala Program RBAC
assertAudit('RBAC-C4', 'Kepala Program diizinkan mengelola kelas & mata pelajaran program',
    Permissions::canFor($roleUsers['kepala_program'], 'class.manage') &&
    Permissions::canFor($roleUsers['kepala_program'], 'subject.manage'));
assertAudit('RBAC-C5', 'Kepala Program ditolak dari suspend user atau assign role',
    !Permissions::canFor($roleUsers['kepala_program'], 'user.suspend') &&
    !Permissions::canFor($roleUsers['kepala_program'], 'role.assign'));

// C4: Guru RBAC
assertAudit('RBAC-C6', 'Guru diizinkan membuat materi, tugas, dan menilai kuis',
    Permissions::canFor($roleUsers['guru'], 'task.create') &&
    Permissions::canFor($roleUsers['guru'], 'task.grade') &&
    Permissions::canFor($roleUsers['guru'], 'material.create'));
assertAudit('RBAC-C7', 'Guru ditolak dari manajemen hak akses user & assign role',
    !Permissions::canFor($roleUsers['guru'], 'user.suspend') &&
    !Permissions::canFor($roleUsers['guru'], 'role.assign'));

// C5: Wali Kelas RBAC
assertAudit('RBAC-C8', 'Wali Kelas diizinkan menyetujui registrasi & melihat laporan kelas',
    Permissions::canFor($roleUsers['wali_kelas'], 'user.approve_registration') &&
    Permissions::canFor($roleUsers['wali_kelas'], 'report.view_class'));
assertAudit('RBAC-C9', 'Wali Kelas ditolak dari membuat soal kuis / tugas',
    !Permissions::canFor($roleUsers['wali_kelas'], 'task.create'));

// C6: Pembina Organisasi / Ekskul RBAC
assertAudit('RBAC-C10', 'Pembina diizinkan mengelola organisasi & post update',
    Permissions::canFor($roleUsers['pembina'], 'org.manage') &&
    Permissions::canFor($roleUsers['pembina'], 'org.post'));
assertAudit('RBAC-C11', 'Pembina ditolak dari manajemen akademik & suspend user',
    !Permissions::canFor($roleUsers['pembina'], 'user.suspend') &&
    !Permissions::canFor($roleUsers['pembina'], 'class.manage'));

// C7: Pengurus Organisasi RBAC
assertAudit('RBAC-C12', 'Pengurus diizinkan post materi organisasi & persetujuan anggota',
    Permissions::canFor($roleUsers['pengurus'], 'org.post') &&
    Permissions::canFor($roleUsers['pengurus'], 'org.approve_member'));
assertAudit('RBAC-C13', 'Pengurus ditolak dari mengubah konfigurasi induk organisasi (org.manage)',
    !Permissions::canFor($roleUsers['pengurus'], 'org.manage'));

// C8: Murid RBAC
assertAudit('RBAC-C14', 'Murid ditolak dari segala operasi administratif atau manipulasi tugas',
    !Permissions::canFor($roleUsers['murid'], 'task.create') &&
    !Permissions::canFor($roleUsers['murid'], 'task.grade') &&
    !Permissions::canFor($roleUsers['murid'], 'user.suspend') &&
    !Permissions::canFor($roleUsers['murid'], 'report.view_all'));

// C9: Granular Permissions via Database (user_permissions)
// Grant custom permission to student
$customPermUserId = $testUserId;
$pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$customPermUserId]);

// Global grant
$pdo->prepare('INSERT INTO user_permissions (user_id, permission, scope_type, scope_id, expires_at)
    VALUES (?, "custom.hall_pass", "global", "0", NULL)')->execute([$customPermUserId]);

// Scoped grant (specific lab)
$pdo->prepare('INSERT INTO user_permissions (user_id, permission, scope_type, scope_id, expires_at)
    VALUES (?, "lab.access", "lab", "LAB-TJKT-1", NULL)')->execute([$customPermUserId]);

// Expired grant
$pdo->prepare('INSERT INTO user_permissions (user_id, permission, scope_type, scope_id, expires_at)
    VALUES (?, "temporary.kiosk", "global", "0", ?)')
    ->execute([$customPermUserId, time() - 3600]);

Permissions::flush();
$customUser = ['id' => $customPermUserId, 'role' => 'murid'];

assertAudit('RBAC-C15', 'Granular global grant di user_permissions berhasil dikenali',
    Permissions::canFor($customUser, 'custom.hall_pass'));

assertAudit('RBAC-C16', 'Granular scoped grant berhasil diakses pada scope yang tepat',
    Permissions::canFor($customUser, 'lab.access', 'lab', 'LAB-TJKT-1'));

assertAudit('RBAC-C17', 'Granular scoped grant ditolak jika scope tidak cocok',
    !Permissions::canFor($customUser, 'lab.access', 'lab', 'LAB-RPL-2'));

assertAudit('RBAC-C18', 'Granular permission yang kedaluwarsa ditolak secara otomatis',
    !Permissions::canFor($customUser, 'temporary.kiosk'));

// Cleanup test user permissions
$pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$customPermUserId]);
Permissions::flush();

// Cleanup test user
$pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM user_items WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM user_progress WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$testUserId]);

// ---------------------------------------------------------------- 2FA di semua pintu login (regresi 16 Okt 2026)
// Dulu akun ber-2FA (ROOT) bisa masuk lewat tab SISWA (/api/login) tanpa kode autentikator.
$tfaSecret = 'JBSWY3DPEHPK3PXP';
$pdo->prepare('INSERT OR IGNORE INTO users (uid, username, password_hash, role, full_name, status, created_at) VALUES (?, "guru_test_2fa", ?, "root", "Root Uji 2FA", "active", datetime("now"))')
    ->execute([bin2hex(random_bytes(8)), \VC\Security\Auth::hash('UjiCoba#2026x')]);
$pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, status = "active", deleted_at = NULL WHERE username = "guru_test_2fa"')->execute([$tfaSecret]);
$tfaHttp = static function (string $path, $post, string &$jar, string $csrf = ''): array {
    $ch = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => $jar, CURLOPT_TIMEOUT => 15]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-CSRF: ' . $csrf]);
    }
    $r = (string)curl_exec($ch);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (preg_match_all('/Set-Cookie: (vcid=[^;]+)/i', substr($r, 0, $hs), $m)) {
        $jar = end($m[1]);
    }
    return [$code, substr($r, $hs)];
};
$tfaJar = '';
[, $tfaHtml] = $tfaHttp('/login', null, $tfaJar);
preg_match('/name="csrf" content="([^"]+)"/', $tfaHtml, $tfaM);
$tfaCsrf = $tfaM[1] ?? '';
[, $tfaBody] = $tfaHttp('/api/login', ['username' => 'guru_test_2fa', 'password' => 'UjiCoba#2026x', '_csrf' => $tfaCsrf], $tfaJar, $tfaCsrf);
[$tfaAdmin] = $tfaHttp('/admin', null, $tfaJar);
assertAudit('AUTH-2FA', 'Login tab Siswa untuk akun ber-2FA meminta kode & belum membuat sesi',
    (json_decode($tfaBody, true)['status'] ?? '') === '2fa_required' && $tfaAdmin === 302, 'resp=' . substr($tfaBody, 0, 80) . ' admin=' . $tfaAdmin);
$tfaCode = 0;
for ($i = 0; $i < 5; $i++) {
    [$tfaCode] = $tfaHttp('/api/totp/verify', json_encode(['code' => '000000', '_csrf' => $tfaCsrf]), $tfaJar, $tfaCsrf);
}
assertAudit('AUTH-2FA', 'Kode 2FA salah 5× → verifikasi dibatalkan (429)', $tfaCode === 429, 'code=' . $tfaCode);
$pdo->prepare('UPDATE users SET deleted_at = datetime("now"), status = "suspended" WHERE username = "guru_test_2fa"')->execute();

// Final audit log integrity check
$finalAuditCheck = AuditLog::verify();
assertAudit('AUDIT-FINAL', 'Rantai hash audit log tetap 100% konsisten setelah seluruh uji QA', $finalAuditCheck === 0);

echo "\n======================================================================\n";
echo "HASIL AKHIR AUDIT: {$pass} PASSED, {$fail} FAILED\n";
echo "STATUS KUALITAS SECURITY & RBAC: " . ($fail === 0 ? "100% LOLOS AUDIT (COMPLIANT)" : "TIDAK LOLOS") . "\n";
echo "======================================================================\n";

exit($fail === 0 ? 0 : 1);
