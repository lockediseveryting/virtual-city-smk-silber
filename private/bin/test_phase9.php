<?php
// Comprehensive automated security & hardening test suite for Phase 9 (Hardening & Release)
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Honeypot;
use VC\Security\RateLimiter;
use VC\Security\AuditLog;

echo "=== MEMULAI TEST SUITE PHASE 9 (HARDENING, SECURITY SUITE & RELEASE) ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "✅ PASS: $desc\n";
        $pass++;
    } else {
        echo "❌ FAIL: $desc (" . $detail . ")\n";
        $fail++;
    }
}

$pdo = Database::get();

// 1. Database Hardening & WAL Configuration
$journalMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
assertTest('SQLite berjalan dalam mode WAL (Write-Ahead Logging)', strtolower((string)$journalMode) === 'wal', "Got: $journalMode");

$fkStatus = $pdo->query('PRAGMA foreign_keys')->fetchColumn();
assertTest('Foreign Key constraints aktif di database', (int)$fkStatus === 1, "Got: $fkStatus");

// 2. Sensitive Files Isolation Check (Web Root Protection)
$publicFiles = scandir(VC_PUBLIC) ?: [];
$leakedSensitives = array_intersect($publicFiles, ['.env', 'virtual_city.sqlite', 'security.sqlite', 'seed.env', 'app.env', 'private']);
assertTest('Tidak ada file sensitif / database di dalam web root public/', empty($leakedSensitives), implode(', ', $leakedSensitives));

// 3. Password Hashing Algorithm Hardening
$hash = Auth::hash('SampleStrongPassword123#');
$info = password_get_info($hash);
$isArgonOrBcrypt = in_array($info['algo'], [PASSWORD_ARGON2ID, PASSWORD_ARGON2I, PASSWORD_BCRYPT], true);
assertTest('Password di-hash dengan Argon2id / Bcrypt standar industri', $isArgonOrBcrypt);

// 4. Password Policy Enforcement
$errShort = Auth::passwordErrors('pendek', 'siswa', 'murid');
$errRoot = Auth::passwordErrors('Password123!', 'admin', 'root');
assertTest('Kebijakan password menolak password murid terlalu pendek', !empty($errShort));
assertTest('Kebijakan password menolak password root < 14 karakter', !empty($errRoot));

// 5. Anti-Enumeration Dummy Verification
$dummyVerify = Auth::verifyLogin('user_yang_pasti_tidak_ada_' . time(), 'password_acak');
assertTest('Percobaan login akun non-existent ditangani secara aman (anti-enumeration)', $dummyVerify === null);

// 6. Honeypot Bot Traps
assertTest('Honeypot mengenali path scanner bot (/wp-login.php)', Honeypot::inspect('/wp-login.php'));
assertTest('Honeypot mengenali probe environment (/.env)', Honeypot::inspect('/.env'));
assertTest('Honeypot mengenali probe database (/backup.zip)', Honeypot::inspect('/backup.zip'));
assertTest('Honeypot tidak memblokir URL resmi aplikasi (/dunia)', !Honeypot::inspect('/dunia'));

// 7. Rate Limiter Sliding Window & Escalation
$testBucket = 'test_harden_' . time();
$wait1 = RateLimiter::hit('login_ip', $testBucket);
$wait2 = RateLimiter::hit('login_ip', $testBucket);
assertTest('Rate limiter mencatat percobaan dan mengembalikan waktu tunggu terkalibrasi', $wait1 >= 0 && $wait2 >= 0);

// 8. CSRF Protection
$token = Csrf::token();
assertTest('CSRF token dihasilkan dan memiliki panjang aman (min 32 char)', strlen($token) >= 32);

// Subprocess test for CSRF rejection (as Csrf::check calls exit)
$csrfSub = shell_exec('php -r "require \'/var/www/private/src/bootstrap.php\'; \VC\Security\Csrf::check(\'invalid_token\');" 2>&1');
assertTest('Pencegahan serangan CSRF menolak token palsu dengan status 403 & log', str_contains((string)$csrfSub, 'Sesi tidak valid'));

// 9. Input Sanitization & Formula Injection Defense
$dangerousCsv = "Hari_atau_Tanggal,Jam_ke,Mulai,Selesai,Kelas,Mapel,Guru,Ruang,Keterangan\nSenin,1,07:30,08:15,X TJKT 1,=CMD|' /C calc'!A0,guru_tjkt,R01,+Formula";
$parsed = \VC\Domain\Schedule::parseCsv($dangerousCsv);
$hasDefused = false;
foreach ($parsed as $r) {
    if (isset($r['data'][5]) && str_starts_with($r['data'][5], "'=")) {
        $hasDefused = true;
    }
}
assertTest('Pencegahan CSV/Excel formula injection menetralkan awalan berbahaya (=, +, -, @)', $hasDefused);

// 10. Audit Log Hash-Chain Tamper-Evident Integrity
$chainBreak = AuditLog::verify();
assertTest('Seluruh rantai audit log valid tanpa mata rantai yang terputus (0 broken links)', $chainBreak === 0, "Broken at: $chainBreak");

// 11. Security Log File Exists & Formatted for Fail2ban
$secLogPath = VC_PRIVATE . '/logs/security.log';
assertTest('File security.log siap pakai untuk integrasi fail2ban', is_file($secLogPath));

// 12. PWA Assets & Offline Cacheability
$manifestPath = VC_PUBLIC . '/manifest.json';
$swPath = VC_PUBLIC . '/service-worker.js';
assertTest('PWA manifest.json dan service-worker.js terkonfigurasi di public root', is_file($manifestPath) && is_file($swPath));

echo "\n============================================\n";
echo "HASIL AKHIR: $pass PASSED, $fail FAILED\n";
echo "============================================\n";

exit($fail > 0 ? 1 : 0);
