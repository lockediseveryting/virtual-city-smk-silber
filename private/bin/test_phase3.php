<?php
// Comprehensive automated test suite for Phase 3 (Schedule System)
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Domain\Schedule;
use VC\Security\AuditLog;

echo "=== MEMULAI TEST SUITE PHASE 3 (SCHEDULE SYSTEM) ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "✅ PASS: {$desc}\n";
    } else {
        $fail++;
        echo "❌ FAIL: {$desc} " . ($detail ? "({$detail})" : "") . "\n";
    }
}

// 1. Template CSV Test
$template = Schedule::getTemplateCsv();
assertTest('Template CSV generated', strlen($template) > 50 && str_contains($template, 'Hari_atau_Tanggal,Jam_ke'));

// 2. CSV Parser & Formula Injection Defense Test
$dangerousCsv = "Hari_atau_Tanggal,Jam_ke,Mulai,Selesai,Kelas,Mapel,Guru,Ruang,Keterangan\n" .
                "Senin,1,07:30,08:15,X TJKT 1,=CMD|' /C calc'!A0,guru_tjkt,R01,+FormulaInjection";
$parsed = Schedule::parseCsv($dangerousCsv);
$hasDefused = false;
foreach ($parsed as $r) {
    if (isset($r['data'][5]) && str_starts_with($r['data'][5], "'=")) {
        $hasDefused = true;
    }
}
assertTest('Formula injection sanitized (defused with single quote)', $hasDefused);

// 3. Import Valid Weekly Schedule
$validWeeklyCsv = "Hari_atau_Tanggal,Jam_ke,Mulai,Selesai,Kelas,Mapel,Guru,Ruang,Keterangan\n" .
                  "Senin,1,07:30,08:15,X TJKT 1,Dasar Jaringan Komputer & Telekomunikasi,coffelatte314,LAB_TIK_1,Topologi Jaringan\n" .
                  "Senin,2,08:15,09:00,X TJKT 1,Dasar Jaringan Komputer & Telekomunikasi,coffelatte314,LAB_TIK_1,Pengkabelan\n" .
                  "Senin,1,07:30,08:15,X AKL 1,Dasar-Dasar Akuntansi & Keuangan Lembaga,sugamilas,R01,Jurnal Umum\n" .
                  "Senin,2,08:15,09:00,X AKL 1,Dasar-Dasar Akuntansi & Keuangan Lembaga,sugamilas,R01,Buku Besar";

$rawRows = Schedule::parseCsv($validWeeklyCsv);
$valResult = Schedule::validate($rawRows, 'weekly');

assertTest('Valid weekly schedule validation', $valResult['valid_count'] === 4 && $valResult['conflict_count'] === 0,
    "Valid: {$valResult['valid_count']}, Conflict: {$valResult['conflict_count']}");

$applyResult = Schedule::apply($valResult['valid_rows'], 'weekly', null, 'test_weekly.csv', 'hash123', 1);
assertTest('Apply weekly schedule atomically', ($applyResult['status'] ?? '') === 'success');
$weeklyVerId = $applyResult['version_id'] ?? 0;

// 4. Double-Booking Conflict Detection
// A: Teacher double-booking (coffelatte314 teaching 2 classes at same period)
$teacherConflictCsv = "Hari_atau_Tanggal,Jam_ke,Mulai,Selesai,Kelas,Mapel,Guru,Ruang,Keterangan\n" .
                      "Senin,3,09:00,09:45,X TJKT 1,Dasar Jaringan Komputer & Telekomunikasi,coffelatte314,LAB_TIK_1,Kelas A\n" .
                      "Senin,3,09:00,09:45,X AKL 1,Matematika Terapan,coffelatte314,R02,Kelas B (Bentrok)";
$valTeacher = Schedule::validate(Schedule::parseCsv($teacherConflictCsv), 'weekly');
assertTest('Detect teacher double-booking conflict', $valTeacher['conflict_count'] >= 1 && str_contains(implode(' ', $valTeacher['conflicts']), 'bentrok'));

// B: Room double-booking (same room LAB_TIK_1 used by 2 classes at same period)
$roomConflictCsv = "Hari_atau_Tanggal,Jam_ke,Mulai,Selesai,Kelas,Mapel,Guru,Ruang,Keterangan\n" .
                   "Senin,4,10:05,10:50,X TJKT 1,Dasar Jaringan Komputer & Telekomunikasi,coffelatte314,LAB_TIK_1,Kelas TJKT\n" .
                   "Senin,4,10:05,10:50,X AKL 1,Komputer Akuntansi (Spreadsheet & Software),sugamilas,LAB_TIK_1,Kelas AKL (Bentrok Ruang)";
$valRoom = Schedule::validate(Schedule::parseCsv($roomConflictCsv), 'weekly');
assertTest('Detect room double-booking conflict', $valRoom['conflict_count'] >= 1 && str_contains(implode(' ', $valRoom['conflicts']), 'Ruangan'));

// 5. Daily Date-Specific Override Test
$overrideDate = '2026-10-19'; // A Monday
$overrideCsv = "Hari_atau_Tanggal,Jam_ke,Mulai,Selesai,Kelas,Mapel,Guru,Ruang,Keterangan\n" .
               "{$overrideDate},1,07:30,08:15,X TJKT 1,Keamanan Jaringan & Cyber Security Dasar,sugamilas,LAB_TIK_2,Guru Pengganti Khusus";
$valOv = Schedule::validate(Schedule::parseCsv($overrideCsv), 'override', $overrideDate);
assertTest('Validate daily override', $valOv['valid_count'] === 1);

$applyOv = Schedule::apply($valOv['valid_rows'], 'override', $overrideDate, 'test_override.csv', 'hashov', 1);
assertTest('Apply daily override', ($applyOv['status'] ?? '') === 'success');
$ovVerId = $applyOv['version_id'] ?? 0;

// Query today schedule for X TJKT 1 on override date
$classes = Database::get()->query('SELECT id FROM classes WHERE name = "X TJKT 1"')->fetch();
$classId = (int)($classes['id'] ?? 1);

$schedOnOverrideDate = Schedule::getTodayScheduleForClass($classId, $overrideDate);
$slot1 = null;
foreach ($schedOnOverrideDate['schedule'] as $sl) {
    if ($sl['period_number'] === 1) $slot1 = $sl;
}
assertTest('Override replaces base entry on specified date',
    $slot1 !== null && ($slot1['entry']['is_override'] ?? false) === true &&
    str_contains($slot1['entry']['subject_name'] ?? '', 'Cyber Security'));

// 6. 1-Click Rollback Test
$rbResult = Schedule::rollback($ovVerId, 1);
assertTest('Rollback override version', ($rbResult['status'] ?? '') === 'success');

// Re-query after rollback: should revert to original base schedule
$schedAfterRollback = Schedule::getTodayScheduleForClass($classId, $overrideDate);
$slot1After = null;
foreach ($schedAfterRollback['schedule'] as $sl) {
    if ($sl['period_number'] === 1) $slot1After = $sl;
}
assertTest('Schedule reverted to base after rollback',
    $slot1After !== null && !($slot1After['entry']['is_override'] ?? false) &&
    str_contains($slot1After['entry']['subject_name'] ?? '', 'Dasar Jaringan Komputer'));

// 7. Teacher Location Lookup Test ("Guru Sedang di Mana?")
$loc = Schedule::findTeacherLocation(1, '08:00', '2026-10-12'); // Monday 08:00 (Jam 1)
assertTest('Find teacher location query',
    isset($loc['status']) && in_array($loc['status'], ['in_class', 'free', 'outside_hours', 'break']),
    "Status: " . ($loc['status'] ?? 'unknown'));

// 8. Audit Log Hash-Chain Integrity Verification
$brokenAudit = AuditLog::verify();
assertTest('Audit log hash-chain integrity preserved (0 broken links)', $brokenAudit === 0, "Broken: {$brokenAudit}");

// 9. Bersihkan: versi jadwal uji TIDAK boleh tertinggal — versi mingguan aktif terbaru dipakai
//    sebagai jadwal sekolah, jadi sisa uji akan menimpa jadwal asli. (audit_log tidak disentuh)
$pdoClean = \VC\Database::get();
foreach ([(int)$weeklyVerId, (int)$ovVerId] as $vid) {
    if ($vid > 0) {
        $pdoClean->prepare('DELETE FROM schedule_entries WHERE version_id = ?')->execute([$vid]);
        $pdoClean->prepare('DELETE FROM schedule_overrides WHERE version_id = ?')->execute([$vid]);
        $pdoClean->prepare('DELETE FROM schedule_versions WHERE id = ?')->execute([$vid]);
    }
}

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

if ($fail > 0) {
    exit(1);
}
