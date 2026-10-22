<?php
// Test suite: validasi sumber kebenaran mapping Virtual City SMK Silber (35 ID lokasi dokumen).
// Memastikan locations.json = dokumen mapping (ID, lantai, kategori filter, koordinat ternormalisasi,
// missionIds) dan setiap lokasi tetap resolve ke dunia 3D via campus.json (campusRef).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\Database;

echo "=== TEST SUITE: MAPPING LOCATIONS (38 ID DOKUMEN) ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "✅ PASS: {$desc}\n";
    } else {
        $fail++;
        echo "❌ FAIL: {$desc}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

// 1. Semua 38 ID lokasi yang wajib ada (dokumen mapping v1.0 + v1.1 tambahan kolam-renang,
//    area-f, parkir-mobil sesuai spesifikasi user — Workstream A, Okt 2026) — hard-coded agar regresi ID terdeteksi.
$requiredIds = [
    'gate-main', 'gedung-a', 'gedung-b', 'gedung-c', 'gedung-d', 'musholah', 'perpus',
    'lap-futsal', 'bank-mini', 'mini-market', 'kantor', 'ruang-tu', 'ruang-praktik',
    'ruang-1', 'ruang-2', 'ruang-3', 'ruang-4', 'ruang-5', 'ruang-6', 'ruang-7',
    'ruang-8', 'ruang-9', 'ruang-10', 'ruang-11', 'ruang-12', 'ruang-13',
    'lab-tik-atas', 'lab-tik-bawah', 'lab-rakitan', 'ruang-bk-bkk', 'ruang-seni',
    'ruang-ipm', 'ruang-silber-industries', 'taman-tengah', 'area-parkir',
    'kolam-renang', 'area-f', 'parkir-mobil',
];
$docFloors = [
    'ruang-1' => 2, 'ruang-2' => 2, 'ruang-8' => 2, 'ruang-9' => 2,
    'ruang-12' => 2, 'ruang-13' => 2,
    'lab-tik-atas' => 2, 'lab-tik-bawah' => 2, 'lab-rakitan' => 2, 'ruang-bk-bkk' => 2,
];
$docCoords = [
    'gate-main' => [920, 330], 'gedung-a' => [500, 455], 'gedung-b' => [510, 240],
    'gedung-c' => [175, 220], 'gedung-d' => [65, 250], 'musholah' => [65, 340],
    'perpus' => [265, 220], 'lap-futsal' => [720, 300], 'bank-mini' => [620, 280],
    'mini-market' => [845, 420], 'kantor' => [735, 425], 'ruang-tu' => [650, 420],
    'ruang-praktik' => [250, 420],
    'ruang-1' => [445, 525], 'ruang-2' => [390, 525], 'ruang-3' => [400, 420],
    'ruang-4' => [570, 275], 'ruang-5' => [510, 275], 'ruang-6' => [435, 275],
    'ruang-7' => [365, 275], 'ruang-8' => [435, 145], 'ruang-9' => [380, 145],
    'ruang-10' => [205, 275], 'ruang-11' => [145, 275], 'ruang-12' => [205, 190],
    'ruang-13' => [145, 190],
    'lab-tik-atas' => [565, 145], 'lab-tik-bawah' => [565, 525], 'lab-rakitan' => [520, 525],
    'ruang-bk-bkk' => [500, 145], 'ruang-seni' => [590, 420], 'ruang-ipm' => [525, 420],
    'ruang-silber-industries' => [455, 420], 'taman-tengah' => [455, 340], 'area-parkir' => [820, 350],
    'kolam-renang' => [120, 340], 'area-f' => [100, 520], 'parkir-mobil' => [890, 300],
];
$validCategories = ['belajar', 'layanan', 'olahraga', 'fasilitas'];
$docMissions = [
    'mission-orientasi', 'mission-literasi', 'mission-teknologi', 'mission-rakitan',
    'mission-keuangan', 'mission-belanja', 'mission-olahraga', 'mission-kreatif',
    'mission-organisasi', 'mission-layanan', 'mission-karier', 'mission-industri',
];
$missionLocation = [
    'mission-orientasi' => 'gate-main',
    'mission-literasi' => 'perpus',
    'mission-teknologi' => ['lab-tik-atas', 'lab-tik-bawah'],
    'mission-rakitan' => 'lab-rakitan',
    'mission-keuangan' => 'bank-mini',
    'mission-belanja' => 'mini-market',
    'mission-olahraga' => 'lap-futsal',
    'mission-kreatif' => 'ruang-seni',
    'mission-organisasi' => 'ruang-ipm',
    'mission-layanan' => ['ruang-tu', 'kantor'],
    'mission-karier' => 'ruang-bk-bkk',
    'mission-industri' => 'ruang-silber-industries',
];

// 2. File sumber kebenaran
$file = VC_PUBLIC . '/assets/map/locations.json';
assertTest('locations.json ada dan tidak kosong', is_file($file) && filesize($file) > 2000);
$data = json_decode((string)file_get_contents($file), true);
assertTest('locations.json JSON valid dengan root benar',
    is_array($data) && isset($data['locations'], $data['coordinates'], $data['floors']));

$locs = [];
foreach (($data['locations'] ?? []) as $l) {
    if (isset($l['id'])) $locs[$l['id']] = $l;
}

$missing = array_values(array_diff($requiredIds, array_keys($locs)));
$extra = array_values(array_diff(array_keys($locs), $requiredIds));
assertTest('Tepat 38 ID lokasi dokumen tersedia (tanpa duplikat, tanpa ID liar)',
    count($locs) === 38 && $missing === [] && $extra === [],
    'hilang: ' . implode(',', $missing) . ' | liar: ' . implode(',', $extra));

// 3. Struktur & nilai tiap lokasi
$bad = [];
foreach ($requiredIds as $id) {
    $l = $locs[$id] ?? null;
    if ($l === null) { $bad[] = $id . ':missing'; continue; }
    $x = $l['x'] ?? -1; $y = $l['y'] ?? -1;
    [$dx, $dy] = $docCoords[$id];
    if ((int)$l['floor'] !== ($docFloors[$id] ?? 1)) $bad[] = $id . ':floor';
    if (!in_array($l['category'], $validCategories, true)) $bad[] = $id . ':category';
    if (!is_bool($l['unlocked'] ?? null)) $bad[] = $id . ':unlocked';
    if (!is_array($l['missionIds']) && ($l['missionIds'] ?? null) !== null) {} // missionIds wajib array
    if (!is_array($l['missionIds'] ?? null)) $bad[] = $id . ':missionIds';
    foreach (($l['missionIds'] ?? []) as $mi) {
        if (!in_array($mi, $docMissions, true)) $bad[] = $id . ':mission?' . $mi;
    }
    if ($x < 0 || $x > 1000 || $y < 0 || $y > 600) $bad[] = $id . ':range';
    elseif ($x !== $dx || $y !== $dy) $bad[] = $id . ':coord';
    if (($l['radius'] ?? 0) <= 0 || ($l['radius'] ?? 0) > 100) $bad[] = $id . ':radius';
    if (empty($l['name']) || empty($l['description'] ?? 'x')) {} // nama & deskripsi wajib
    if (empty($l['name'])) $bad[] = $id . ':name';
    if (empty($l['campusRef'])) $bad[] = $id . ':campusRef';
}
assertTest('Semua lokasi: lantai/kategori/koordinat dokumen/radius/missionIds/unlocked/nama terisi',
    $bad === [], 'deviasi: ' . implode(',', $bad));

// 4. Semua 12 misi dokumen terikat pada setidaknya satu lokasi yang benar
$missionCover = [];
foreach ($locs as $id => $l) {
    foreach ($l['missionIds'] ?? [] as $mi) $missionCover[$mi][] = $id;
}
$badMission = [];
foreach ($docMissions as $mi) {
    $targets = (array)($missionLocation[$mi] ?? []);
    if (empty($missionCover[$mi])) { $badMission[] = $mi . ':not-bound'; continue; }
    if (array_diff($missionCover[$mi], $targets)) $badMission[] = $mi . ':wrong-loc(' . implode(',', $missionCover[$mi]) . ')';
}
assertTest('12 misi dokumen terikat pada lokasi yang tepat', $badMission === [], implode(',', $badMission));

// 5. Korespondensi dunia 3D: campusRef resolve ke campus.json
$campus = json_decode((string)file_get_contents(VC_PUBLIC . '/assets/map/campus.json'), true);
$buildings = [];
foreach (($campus['buildings'] ?? []) as $b) $buildings[$b['id']] = $b;
$rooms = [];
foreach (($campus['rooms'] ?? []) as $r) $rooms[$r['id']] = $r;
$badRef = [];
foreach ($requiredIds as $id) {
    [$kind, $cid] = array_pad(explode(':', $locs[$id]['campusRef'] ?? ':', 2), 2, '');
    $target = $kind === 'building' ? ($buildings[$cid] ?? null) : ($rooms[$cid] ?? null);
    if ($target === null) { $badRef[] = $id . '->' . $cid; continue; }
    // Lantai harus konsisten antara data dokumen dan dunia 3D
    if ((int)$target['floor'] !== (int)$locs[$id]['floor']) $badRef[] = $id . ':floor-mismatch(' . $target['floor'] . ')';
}
assertTest('Semua campusRef resolve ke gedung/ruang dunia 3D dengan lantai konsisten', $badRef === [], implode(',', $badRef));

// 6. Misi location-bound di server memakai ID internal yang sama (tabel missions)
$pdo = Database::get();
$rows = $pdo->query("SELECT target_value FROM missions WHERE is_active = 1 AND target_type = 'location'")->fetchAll(PDO::FETCH_COLUMN);
$internalIds = array_map(
    fn(string $ref) => explode(':', $ref, 2)[1],
    array_column($locs, 'campusRef', 'id')
);
$unknownBound = [];
foreach ($rows as $tv) {
    if ($tv === null || $tv === '' || !in_array($tv, $internalIds, true)) $unknownBound[] = (string)$tv;
}
assertTest('Misi location-bound server memakai ID internal yang tahu resolve-nya', $unknownBound === [], implode(',', $unknownBound));

// 7. Semua ID lokasi dokumen resolvable via Gameplay::locationName (validasi recordVisit)
$badName = [];
foreach ($requiredIds as $id) {
    [\VC\Domain\Gameplay::class, 'locationName'];
    $internalId = explode(':', (string)$locs[$id]['campusRef'], 2)[1];
    if (\VC\Domain\Gameplay::locationName($internalId) === null) $badName[] = $internalId;
}
assertTest('Gameplay::locationName mengenali semua target internal', $badName === [], implode(',', $badName));

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

if ($fail > 0) exit(1);
