<?php
// Comprehensive automated test suite for Phase 4 (3D World, 2D Map, and campus.json)
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Security\AuditLog;

echo "=== MEMULAI TEST SUITE PHASE 4 (3D WORLD & CAMPUS MAP) ===\n\n";

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

// 1. Self-hosted Three.js Library Check
$threePath = VC_PUBLIC . '/assets/vendor/three.min.js';
assertTest('Three.js self-hosted file exists and non-empty',
    is_file($threePath) && filesize($threePath) > 500000,
    "Size: " . (is_file($threePath) ? filesize($threePath) : 0) . " bytes");

// 2. campus.json Dataset Validation
$campusPath = VC_PUBLIC . '/assets/map/campus.json';
assertTest('campus.json dataset file exists', is_file($campusPath));

$campusContent = file_get_contents($campusPath);
$campusJson = json_decode($campusContent, true);
assertTest('campus.json is valid JSON structure',
    is_array($campusJson) && isset($campusJson['buildings']) && isset($campusJson['rooms']) && isset($campusJson['spawn']));

$bCount = count($campusJson['buildings'] ?? []);
$rCount = count($campusJson['rooms'] ?? []);
assertTest('campus.json contains buildings and rooms',
    $bCount >= 10 && $rCount >= 20,
    "Buildings: {$bCount}, Rooms: {$rCount}");

// Check bounds on all buildings
$allBoundsValid = true;
foreach ($campusJson['buildings'] as $b) {
    if (!isset($b['bounds']['width']) || $b['bounds']['width'] <= 0 ||
        !isset($b['bounds']['depth']) || $b['bounds']['depth'] <= 0 ||
        !isset($b['bounds']['height']) || $b['bounds']['height'] <= 0) {
        $allBoundsValid = false;
        break;
    }
}
assertTest('All building bounding boxes have positive dimensions', $allBoundsValid);

// 2.5 Confirmed location-ID resolution (Virtual City mapping document, v1.0)
// Every confirmed location ID from html/virtual_city_smk_silber_mapping.md must resolve
// to a campus.json building or room. Coordinates remain configurable/admin-editable.
$mappingIdAliases = [
    'gate-main'               => 'building:gate-main',
    'gedung-a'                => 'building:gedung-a',
    'gedung-b'                => 'building:gedung-b',
    'gedung-c'                => 'building:gedung-c',
    'gedung-d'                => 'building:gedung-d',
    'musholah'                => 'building:musholah',
    'perpus'                  => 'room:PERPUS',
    'lap-futsal'              => 'room:LAP_FUTSAL',
    'bank-mini'               => 'room:BANK_MINI',
    'mini-market'             => 'room:MINI_MARKET',
    'kantor'                  => 'room:KANTOR',
    'ruang-tu'                => 'room:RUANG_TU',
    'ruang-praktik'           => 'room:RUANG_PRAKTIK',
    'ruang-1'                 => 'room:R01',
    'ruang-2'                 => 'room:R02',
    'ruang-3'                 => 'room:R03',
    'ruang-4'                 => 'room:R04',
    'ruang-5'                 => 'room:R05',
    'ruang-6'                 => 'room:R06',
    'ruang-7'                 => 'room:R07',
    'ruang-8'                 => 'room:R08',
    'ruang-9'                 => 'room:R09',
    'ruang-10'                => 'room:R10',
    'ruang-11'                => 'room:R11',
    'ruang-12'                => 'room:R12',
    'ruang-13'                => 'room:R13',
    'lab-tik-atas'            => 'room:LAB_TIK_2',
    'lab-tik-bawah'           => 'room:LAB_TIK_1',
    'lab-rakitan'             => 'room:LAB_RAKITAN',
    'ruang-bk-bkk'            => 'room:RUANG_BK',
    'ruang-seni'              => 'room:RUANG_SENI',
    'ruang-ipm'               => 'room:RUANG_IPM',
    'ruang-silber-industries' => 'room:RUANG_INDUSTRI',
    'taman-tengah'            => 'room:TAMAN_TENGAH',
    'area-parkir'             => 'room:PARKIR',
];
$buildingsById = [];
$roomsById = [];
foreach (($campusJson['buildings'] ?? []) as $b) { $buildingsById[$b['id']] = $b; }
foreach (($campusJson['rooms'] ?? []) as $r) { $roomsById[$r['id']] = $r; }
$unresolvedMappingIds = [];
foreach ($mappingIdAliases as $mappingId => $target) {
    [$kind, $cid] = array_pad(explode(':', $target, 2), 2, '');
    if ($kind === 'building') { if (!isset($buildingsById[$cid])) $unresolvedMappingIds[] = $mappingId . '->' . $cid; }
    elseif ($kind === 'room')  { if (!isset($roomsById[$cid]))    $unresolvedMappingIds[] = $mappingId . '->' . $cid; }
}
assertTest('All 37 confirmed mapping location IDs resolve in campus.json',
    empty($unresolvedMappingIds), 'Unresolved: ' . implode(', ', $unresolvedMappingIds));
// Floor consistency: floor-2 mapping IDs must place their room on floor 2.
$floor2Pairs = ['ruang-8' => 'R08', 'ruang-9' => 'R09', 'ruang-12' => 'R12', 'ruang-13' => 'R13',
    'lab-tik-atas' => 'LAB_TIK_2', 'lab-rakitan' => 'LAB_RAKITAN', 'ruang-bk-bkk' => 'RUANG_BK'];
$floorMismatch = [];
foreach ($floor2Pairs as $mid => $cid) {
    if ((int)($roomsById[$cid]['floor'] ?? 1) !== 2) $floorMismatch[] = $mid . '->' . $cid;
}
assertTest('Floor-2 mapping locations map to floor-2 rooms only',
    empty($floorMismatch), 'Mismatch: ' . implode(', ', $floorMismatch));

// NOTE: Route-dispatch tests below are executed in a child process because
// public/index.php terminally exits() after handling a route; requiring it
// in-process would silently end this suite after the first include.
// Auth-gated pages (e.g. /dunia, /peta) are rendered with a synthetic active
// student session via render_authorized_page.php (fixture accounts only).
$demoStudentId = (int)((Database::get()->query(
    "SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL AND role = 'murid' ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC)['id'] ?? 0));
assertTest('Demo student account available for auth-gated page tests', $demoStudentId > 0, "id: {$demoStudentId}");

$renderPage = function (string $uri, ?int $userId = null): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/render_authorized_page.php')
        . ' ' . escapeshellarg($uri) . ($userId ? ' ' . (string)$userId : '');
    return (string)shell_exec($cmd);
};
assertTest('world3d.js procedural engine exists',
    is_file(VC_PUBLIC . '/assets/js/world3d.js') && filesize(VC_PUBLIC . '/assets/js/world3d.js') > 1000);
assertTest('map2d.js 2D fallback map engine exists',
    is_file(VC_PUBLIC . '/assets/js/map2d.js') && filesize(VC_PUBLIC . '/assets/js/map2d.js') > 1000);

// 4. API /api/campus endpoint test (child process: index.php exits after the route)
$apiCampusOut = $renderPage('/api/campus');
$apiCampusJson = json_decode($apiCampusOut, true);
assertTest('/api/campus endpoint returns valid JSON map data',
    is_array($apiCampusJson) && isset($apiCampusJson['school']) && $apiCampusJson['school'] === 'SMK Silber');

// 5. Web /dunia page rendering test (authenticated)
$duniaOut = $renderPage('/dunia', $demoStudentId);
assertTest('/dunia renders 3D viewport canvas & Three.js include',
    str_contains($duniaOut, 'id="viewport3D"') &&
    str_contains($duniaOut, 'three.min.js') &&
    str_contains($duniaOut, 'world3d.js'));

// 6. Web /peta page rendering test (2D Fallback, authenticated)
$petaOut = $renderPage('/peta', $demoStudentId);
assertTest('/peta renders 2D interactive floorplan canvas',
    str_contains($petaOut, 'id="mapCanvas"') &&
    str_contains($petaOut, 'map2d.js') &&
    str_contains($petaOut, 'Lantai 1'));

// 7. Security check: Map Editor endpoint unauthorized protection (student session)
$editorOut = $renderPage('/editor-peta', $demoStudentId);
assertTest('/editor-peta denies access without world.edit_details permission',
    str_contains($editorOut, 'tidak punya izin') || str_contains($editorOut, 'Akses Ditolak'),
    'Response: ' . substr(strip_tags($editorOut), 0, 120));

// 7.5 Agen 1 — kunjungan lokasi & validasi misi location-bound
$gamePdo = Database::get();
assertTest('Tabel location_visits ada hasil migrasi 012',
    (string)$gamePdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='location_visits'")->fetchColumn() === 'location_visits');

$invalidVisit = \VC\Domain\Gameplay::recordVisit($demoStudentId, 'Tidak/Valid!');
assertTest('recordVisit menolak ID lokasi tidak valid', ($invalidVisit['status'] ?? '') === 'error');
$unknownVisit = \VC\Domain\Gameplay::recordVisit($demoStudentId, 'LOKASI_ORAKAN');
assertTest('recordVisit menolak lokasi tak dikenal (tidak ada di campus.json)', ($unknownVisit['status'] ?? '') === 'error');
$okVisit = \VC\Domain\Gameplay::recordVisit($demoStudentId, 'PERPUS');
assertTest('recordVisit menerima lokasi kampus sah', ($okVisit['status'] ?? '') === 'ok', json_encode($okVisit, JSON_UNESCAPED_UNICODE));

$mapMiss = $gamePdo->query("SELECT id FROM missions WHERE title = 'Pemburu Informasi' AND is_active = 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Misi mapping location-bound tersedia di DB', is_array($mapMiss) && isset($mapMiss['id']));
$mid = (int)($mapMiss['id'] ?? 0);

// Reset jatah pengujian pada akun fixture (bukan data produksi)
$gamePdo->prepare('DELETE FROM mission_progress WHERE user_id = ? AND mission_id = ?')->execute([$demoStudentId, $mid]);
$gamePdo->prepare('DELETE FROM transactions WHERE user_id = ? AND idempotency_key LIKE ?')->execute([$demoStudentId, 'MISSION-' . $demoStudentId . '-' . $mid . '%']);
$gamePdo->prepare('DELETE FROM user_badges WHERE user_id = ? AND badge_id IN (SELECT id FROM badges WHERE name = ?)')->execute([$demoStudentId, 'Lencana Literasi']);
$gamePdo->prepare('DELETE FROM location_visits WHERE user_id = ? AND location_id = ?')->execute([$demoStudentId, 'PERPUS']);

$noVisit = \VC\Domain\Gameplay::completeMission($demoStudentId, $mid);
assertTest('Misi location-bound ditolak tanpa kunjungan lokasi',
    ($noVisit['status'] ?? '') === 'error' && ($noVisit['required_location'] ?? '') === 'PERPUS',
    json_encode($noVisit, JSON_UNESCAPED_UNICODE));
$completedEarly = $gamePdo->prepare('SELECT status FROM mission_progress WHERE user_id = ? AND mission_id = ? AND status = "completed"');
$completedEarly->execute([$demoStudentId, $mid]);
assertTest('Klaim yang ditolak tidak menandai misi selesai', $completedEarly->fetchColumn() === false);

\VC\Domain\Gameplay::recordVisit($demoStudentId, 'PERPUS');
$withVisit = \VC\Domain\Gameplay::completeMission($demoStudentId, $mid);
assertTest('Misi location-bound sukses setelah kunjungan terekam', ($withVisit['status'] ?? '') === 'success', json_encode($withVisit, JSON_UNESCAPED_UNICODE));
// Idempotensia hadiah: jumlah entri ledger = jumlah komponen reward (XP/koin) yang
// memang >0 pada misi, dan setiap idempotency_key muncul tepat satu kali.
$misRow = $gamePdo->query("SELECT xp_reward, coin_reward FROM missions WHERE id = {$mid}")->fetch(PDO::FETCH_ASSOC);
$expectedEntries = ((int)$misRow['xp_reward'] > 0 ? 1 : 0) + ((int)$misRow['coin_reward'] > 0 ? 1 : 0);
$ledgerCount = $gamePdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND idempotency_key LIKE ?');
$ledgerCount->execute([$demoStudentId, 'MISSION-' . $demoStudentId . '-' . $mid . '%']);
$ledgerAfterFirst = (int)$ledgerCount->fetchColumn();
assertTest('Ledger hadyah sesuai jumlah komponen reward (idempoten, tanpa duplikat)',
    $ledgerAfterFirst === $expectedEntries &&
    $expectedEntries === (int)$gamePdo->query("SELECT COUNT(DISTINCT idempotency_key) FROM transactions WHERE idempotency_key LIKE 'MISSION-{$demoStudentId}-{$mid}%'")->fetchColumn(),
    "tersedia: {$ledgerAfterFirst}, diharapkan: {$expectedEntries}");
// Klaim kedua HARUS ditolak dan TIDAK menambah entri hadiah mana pun (XP, koin, lencana).
$secondClaim = \VC\Domain\Gameplay::completeMission($demoStudentId, $mid);
assertTest('Klaim misi kedua ditolak sebagai duplikat (reward tidak berulang)',
    ($secondClaim['status'] ?? '') === 'duplicate', json_encode($secondClaim, JSON_UNESCAPED_UNICODE));
$ledgerCount->execute([$demoStudentId, 'MISSION-' . $demoStudentId . '-' . $mid . '%']);
assertTest('Ledger hadiah tetap bolak-balik (tidak bertambah) setelah klaim ulang',
    (int)$ledgerCount->fetchColumn() === $ledgerAfterFirst);
$badgeGiven = $gamePdo->prepare('SELECT COUNT(*) FROM user_badges ub JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id = ? AND b.name = ?');
$badgeGiven->execute([$demoStudentId, 'Lencana Literasi']);
assertTest('Lencana Literasi dianugerahkan otomatis server-side', (int)$badgeGiven->fetchColumn() === 1);

// 8. Audit log integrity check
$broken = AuditLog::verify();
assertTest('Audit log hash-chain integrity preserved (0 broken links)', $broken === 0, "Broken: {$broken}");

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

if ($fail > 0) {
    exit(1);
}
