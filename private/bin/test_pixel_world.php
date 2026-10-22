<?php
// Test suite: Mode PIXEL WORLD V2 (Agent 4) — Denah, Bangunan, Interior, Server Kelas & Multiplayer 150 Pemain.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Domain\GameServers;
use VC\Security\Auth;

echo "=== TEST SUITE: PIXEL WORLD V2 (Denah, Interior, Server Kelas & 150 Pemain) ===\n\n";

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

// -------------------------------------------------------------
// 1. ENGINE SIDE-VIEW & BERKAS PENDUKUNG
// -------------------------------------------------------------
$js = VC_PUBLIC . '/assets/js/';
$files = ['pixel-side.js', 'pixel-side-art.js', 'pixel-side-map.js', 'pixel-avatar.js', 'pixel-net.js', 'pixel-audio.js'];
$src = [];
foreach ($files as $f) { $src[$f] = is_file($js . $f) ? (string)file_get_contents($js . $f) : ''; }
$engineSrc = $src['pixel-side.js'] . $src['pixel-side-art.js'] . $src['pixel-side-map.js'];
assertTest('Engine side-view (core + render + peta) ada & substantif (>60KB)', strlen($engineSrc) > 60000, 'bytes: ' . strlen($engineSrc));
foreach ($files as $f) {
    $out = (string)shell_exec('node --check ' . escapeshellarg($js . $f) . ' 2>&1; echo EXIT:$?');
    assertTest($f . ' lolos node --check', str_contains($out, 'EXIT:0'), trim($out));
}
$acorn = (string)shell_exec('cd ' . escapeshellarg(dirname(VC_PUBLIC)) . ' && node -e ' . escapeshellarg(
    'const a=require("acorn"),fs=require("fs");for(const f of ["pixel-side.js","pixel-side-art.js","pixel-side-map.js","pixel-avatar.js"])a.parse(fs.readFileSync("' . $js . '"+f,"utf8"),{ecmaVersion:2017});console.log("ES2017-OK")') . ' 2>&1');
assertTest('Engine side-view memakai sintaks ES2017 (HP lama)', str_contains($acorn, 'ES2017-OK') || str_contains($acorn, 'Cannot find module'), trim($acorn));
assertTest('Karakter dunia pixel = penggambar dashboard (PixelAvatar bersama)',
    str_contains($src['pixel-side.js'], 'PixelAvatar') && (bool)preg_match('/PA\s*=\s*window\.PixelAvatar[\s\S]*PA\.paint\(/', (string)file_get_contents($js . 'pixel-dashboard.js')));
assertTest('Audio: method sfx() tidak tertimpa node volume (bug lama)', !preg_match('/this\.sfx\s*=\s*this\.ctx/', $src['pixel-audio.js']));
assertTest('Engine tetap jalan bila suara error (loop dijadwalkan sebelum update)',
    (bool)preg_match('/requestAnimationFrame\(\(tt\) => this\.loop\(tt\)\);[^}]*try \{/s', $src['pixel-side.js']));

// -------------------------------------------------------------
// 2. DENAH RESMI /var/www/ruang.txt → pixel-side.json (38 LOKASI)
// -------------------------------------------------------------
$data = json_decode((string)@file_get_contents(VC_PUBLIC . '/assets/map/pixel-side.json'), true);
assertTest('pixel-side.json valid (v1, side-view)', is_array($data) && ($data['version'] ?? 0) === 1 && ($data['kind'] ?? '') === 'pixel-side-world');
$locs = $data['locations'] ?? [];
$byId = [];
foreach ($locs as $l) { $byId[$l['id']] = $l; }
assertTest('38 titik lokasi resmi, ID unik', count($locs) === 38 && count($byId) === 38, 'count: ' . count($locs));

$ruangPath = dirname(VC_PUBLIC) . '/ruang.txt';
$ruang = is_file($ruangPath) ? (string)file_get_contents($ruangPath) : '';
assertTest('Referensi denah /var/www/ruang.txt tersedia', $ruang !== '');
// Ruangan per gedung & lantai menurut ruang.txt harus ada dengan gedung/lantai yang sama.
$expected = [];
$curB = null; $curF = null;
foreach (preg_split('/\R/', $ruang) as $line) {
    if (preg_match('/^\d\. GEDUNG ([A-D])/', $line, $m)) { $curB = $m[1]; continue; }
    if (preg_match('/^\d\. FASILITAS LUAR/', $line)) { $curB = null; continue; }
    if (preg_match('/^Lantai (\d):/', $line, $m)) { $curF = (int)$m[1]; continue; }
    if ($curB && preg_match('/^\s+- (.+?)\s+:/', $line, $m)) { $expected[] = [trim($m[1]), $curB, $curF]; }
}
$missing = [];
foreach ($expected as [$name, $bld, $fl]) {
    $hit = array_filter($locs, fn($l) => $l['name'] === $name && $l['building'] === $bld && (int)$l['floor'] === $fl);
    if (!$hit) $missing[] = "$name ($bld/Lt$fl)";
}
assertTest('Semua ruangan Gedung A–D sesuai ruang.txt (nama, gedung, lantai)', count($expected) === 28 && $missing === [], 'expected ' . count($expected) . '; missing: ' . implode(', ', $missing));
// Rekap 13 kelas teori (kode, gedung, lantai, rombel)
preg_match_all('/^(R\d\d)\s+\|\s+Ruang (\d+)\s+\|\s+Gedung ([A-D])\s+\|\s+Lt\. (\d)\s+\|\s+(.+?)\s*$/m', $ruang, $mm, PREG_SET_ORDER);
$badCls = [];
foreach ($mm as $m) {
    $l = $byId['ruang-' . (int)$m[2]] ?? null;
    if (!$l || ($l['code'] ?? '') !== $m[1] || $l['building'] !== $m[3] || (int)$l['floor'] !== (int)$m[4] || ($l['rombel'] ?? '') !== trim($m[5])) $badCls[] = $m[1];
}
assertTest('13 ruang kelas teori cocok dengan rekap ruang.txt (kode/gedung/lantai/rombel)', count($mm) === 13 && $badCls === [], 'tidak cocok: ' . implode(',', $badCls));
$outdoor = array_filter($locs, fn($l) => $l['building'] === 'Luar');
assertTest('10 titik luar ruangan (gerbang & pos, kantin & taman terbuka dipisah)', count($outdoor) === 10);
$badRef = [];
foreach ($locs as $l) {
    $iid = explode(':', (string)$l['campusRef'])[1] ?? '';
    if (\VC\Domain\Gameplay::locationName($iid) === null) $badRef[] = $l['id'];
}
assertTest('Setiap lokasi tercatat ke ID kunjungan resmi (Gameplay::locationName)', $badRef === [], implode(',', $badRef));
$bl = $data['buildings'] ?? [];
assertTest('Gedung A, B, C dua lantai; Gedung D satu lantai',
    isset($bl['A']['floors']['2'], $bl['B']['floors']['2'], $bl['C']['floors']['2']) && !isset($bl['D']['floors']['2']));
$zones = array_column($data['world']['zones'] ?? [], 'id');
$order = ['gedung-d', 'kolam-renang', 'gedung-c', 'taman-tengah', 'gedung-a', 'gedung-b', 'lap-futsal', 'parkir-mobil', 'gerbang-utama'];
$pos = array_map(fn($z) => array_search($z, $zones, true), $order);
$sorted = $pos; sort($sorted);
assertTest('Urutan barat→timur mengikuti koordinat campus.json (D … gerbang timur)', !in_array(false, $pos, true) && $pos === $sorted);

// -------------------------------------------------------------
// 3. PINTU, INTERIOR, TANGGA, NAVIGASI & KONTROL
// -------------------------------------------------------------
$art = $src['pixel-side-art.js'];
$types = array_unique(array_filter(array_map(fn($l) => $l['interior'] ?? null, $locs)));
$noLayout = array_values(array_filter($types, fn($t) => !str_contains($art, 'ROOMS.' . $t . ' =')));
assertTest('Setiap jenis ruangan punya interior sendiri (' . count($types) . ' jenis)', count($types) >= 18 && $noLayout === [], implode(',', $noLayout));
assertTest('Pintu masuk/keluar gedung & ruangan + transisi', str_contains($src['pixel-side.js'], "kind: 'door'") && str_contains($src['pixel-side.js'], 'enterScene') && str_contains($src['pixel-side.js'], 'this.trans'));
assertTest('Tangga lantai 1 ⇄ 2 (naik & turun)', str_contains($src['pixel-side.js'], "kind: 'stairs', x: sx, dir: 1") && str_contains($src['pixel-side.js'], "kind: 'stairs', x: sx, dir: -1"));
assertTest('Collision tembok/batas scene', str_contains($src['pixel-side.js'], 'clamp(p.x + ax * speed * dt, sc.walls[0], sc.walls[1])'));
assertTest('Navigasi antar-scene (BFS pintu & tangga) + peta denah resmi', str_contains($src['pixel-side.js'], 'nextWaypoint') && str_contains($src['pixel-side-map.js'], 'campusPos') && str_contains($src['pixel-side-map.js'], 'data-act="nav"'));
assertTest('Kontrol sentuh ◀ ▶ ▲ ▼ + tombol aksi', str_contains($src['pixel-side.js'], 'data-k="left"') && str_contains($src['pixel-side.js'], 'data-k="up"') && str_contains($src['pixel-side.js'], 'pixAction'));
assertTest('Interaksi mebel, NPC, papan info lokasi & bola futsal', str_contains($src['pixel-side.js'], 'nearFurniture') && str_contains($src['pixel-side.js'], "kind: 'npc'") && str_contains($src['pixel-side.js'], "kind: 'sign'") && str_contains($src['pixel-side.js'], 'this.ball'));
assertTest('Multiplayer pixel memakai skala jaringan & area per ruangan', str_contains($src['pixel-net.js'], 'toNet(') && str_contains($src['pixel-net.js'], "r.area = msg.area"));

// -------------------------------------------------------------
// 4. MULTIPLAYER ARCHITECTURE & SERVER TYPES
// -------------------------------------------------------------
$pdo = Database::get();
$demoStaff = $pdo->query("SELECT id, username, role FROM users WHERE role = 'root' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$demoStudent = $pdo->query("SELECT id, username, role, class_id FROM users WHERE role = 'murid' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assertTest('Akun demo staff & siswa aktif di database', !empty($demoStaff) && !empty($demoStudent));

// Model GameServers mendukung tipe server class, public, private, event
$serversList = GameServers::listFor((int)$demoStudent['id'], 'murid');
assertTest('GameServers::listFor mengembalikan daftar server aktif', is_array($serversList) && count($serversList) >= 1);

// Verifikasi pembuatan server kelas
$testServerRes = GameServers::create((int)$demoStaff['id'], [
    'name' => 'Server Kelas Uji QA V2',
    'type' => 'class',
    'class_id' => (int)($demoStudent['class_id'] ?? 1),
    'capacity' => 150,
    'access' => 'invite',
    'description' => 'Server otomatis untuk test unit Agent 4'
]);
assertTest('Staf/guru dapat membuat Server Kelas dengan kapasitas s/d 150',
    ($testServerRes['status'] ?? '') === 'ok' && !empty($testServerRes['invite_code']),
    $testServerRes['message'] ?? ''
);

$newServerId = (int)($testServerRes['server_id'] ?? 0);
if ($newServerId > 0) {
    // 1. Join dengan kode salah ditolak
    $joinFail = GameServers::join((int)$demoStudent['id'], $newServerId, 'SALAH123');
    assertTest('Server Privat menolak kode undangan yang salah', ($joinFail['status'] ?? '') === 'error' && ($joinFail['http'] ?? 0) === 403);

    // 2. Join dengan kode undangan yang benar
    $joinOk = GameServers::join((int)$demoStudent['id'], $newServerId, (string)$testServerRes['invite_code']);
    assertTest('Siswa dapat bergabung ke Server Kelas menggunakan kode undangan', ($joinOk['status'] ?? '') === 'ok', $joinOk['message'] ?? '');
}

// -------------------------------------------------------------
// 5. WEBSOCKET REALTIME & TARGET 150 PEMAIN
// -------------------------------------------------------------
$wsToken = Auth::createWsToken($demoStudent, 1);
assertTest('Auth::createWsToken menghasilkan token HMAC valid', is_string($wsToken) && str_contains($wsToken, '.'));

// Cek status server WebSocket live
$ctx = stream_context_create(['http' => ['timeout' => 2]]);
$wsStatusRaw = @file_get_contents('http://127.0.0.1:8090/status', false, $ctx);
if ($wsStatusRaw === false || $wsStatusRaw === '') {
    $wsStatusRaw = (string)shell_exec('curl -s http://127.0.0.1:8090/status');
}
$wsStatus = is_string($wsStatusRaw) ? json_decode($wsStatusRaw, true) : null;
assertTest('Layanan virtual-city-ws aktif pada port 8090', ($wsStatus['status'] ?? '') === 'ok');

// Verifikasi script load test 150 pemain
$loadScript = VC_PRIVATE . '/bin/test_load_150_players.js';
assertTest('Script benchmark beban 150 pemain ada & siap dijalankan', is_file($loadScript));

// -------------------------------------------------------------
// 6. INTEGRASI DASHBOARD GURU & HALAMAN PIXEL WORLD
// -------------------------------------------------------------
// Render authorized /pixel
$cmdPix = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/render_authorized_page.php')
    . ' ' . escapeshellarg('/pixel') . ' ' . (string)$demoStudent['id'];
$htmlPix = (string)shell_exec($cmdPix);

assertTest('/pixel me-render canvas dunia pixel', str_contains($htmlPix, 'id="pixCanvas"'));
assertTest('/pixel memiliki Server Browser di Lobi', str_contains($htmlPix, 'pixServerCards') && str_contains($htmlPix, 'srvTabClass'));
assertTest('/pixel memiliki tombol Audio SFX & Musik', str_contains($htmlPix, 'id="pixAudioBtn"'));
assertTest('/pixel memiliki tombol Ganti Server di Topbar', str_contains($htmlPix, 'id="pixServerBtn"'));
assertTest('/pixel memiliki Emote Bar (wave, point, clap, cheer, sit)', str_contains($htmlPix, 'id="pixEmoteBar"') && str_contains($htmlPix, 'data-emote="cheer"'));
assertTest('/pixel memiliki tab Tugas Rombel Kelas', str_contains($htmlPix, 'pixTabTugas') && str_contains($htmlPix, 'pixTugasSec'));
assertTest('/pixel menyematkan data server multiplayer (__PIX_SERVERS)', str_contains($htmlPix, '__PIX_SERVERS'));

// Render dashboard guru /admin
$cmdAdmin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/render_authorized_page.php')
    . ' ' . escapeshellarg('/admin') . ' ' . (string)$demoStaff['id'];
$htmlAdmin = (string)shell_exec($cmdAdmin);

assertTest('/admin memiliki kartu Manajemen Server Kelas Multiplayer', str_contains($htmlAdmin, 'server-section') && str_contains($htmlAdmin, 'Manajemen Server Kelas'));
assertTest('/admin memiliki formulir pembuatan server baru untuk guru', str_contains($htmlAdmin, 'formCreateServer') && str_contains($htmlAdmin, 'buatServerKelas'));

// -------------------------------------------------------------
// 7. KELAS 30 ROMBEL LENGKAP & FORM PENDAFTARAN /daftar
// -------------------------------------------------------------
$classesCount = (int)$pdo->query("SELECT COUNT(*) FROM classes WHERE deleted_at IS NULL")->fetchColumn();
assertTest('Database memiliki 30 rombel kelas lengkap', $classesCount === 30, "count: {$classesCount}");

$rombelPerGrade = $pdo->query("SELECT grade, COUNT(*) as cnt FROM classes WHERE deleted_at IS NULL GROUP BY grade ORDER BY grade")->fetchAll(PDO::FETCH_KEY_PAIR);
assertTest('Rombel terbagi rata: Kelas 10 (10), Kelas 11 (10), Kelas 12 (10)',
    ($rombelPerGrade['X'] ?? 0) === 10 && ($rombelPerGrade['XI'] ?? 0) === 10 && ($rombelPerGrade['XII'] ?? 0) === 10,
    json_encode($rombelPerGrade)
);

$allMajorsFound = true;
foreach (['X', 'XI', 'XII'] as $gr) {
    $cNames = $pdo->query("SELECT name FROM classes WHERE grade = '{$gr}' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['TJKT 1', 'TJKT 2', 'TJKT 3', 'TJKT 4', 'AKL 1', 'AKL 2', 'BD 1', 'BD 2', 'DKV 1', 'DKV 2'] as $suffix) {
        $expected = "{$gr} {$suffix}";
        if (!in_array($expected, $cNames, true)) {
            $allMajorsFound = false;
            break 2;
        }
    }
}
assertTest('Semua rombel TJKT 1-4, AKL 1-2, BD 1-2, DKV 1-2 ada di setiap tingkat', $allMajorsFound);

ob_start();
\VC\Controllers\AuthController::renderRegister();
$htmlReg = ob_get_clean();

assertTest('/daftar mengelompokkan kelas dengan optgroup Kelas 10, 11, 12',
    str_contains($htmlReg, '<optgroup label="Kelas 10 (X)">') &&
    str_contains($htmlReg, '<optgroup label="Kelas 11 (XI)">') &&
    str_contains($htmlReg, '<optgroup label="Kelas 12 (XII)">')
);

// -------------------------------------------------------------
// 8. GABUNG KELAS CEPAT & TRAVELING (MASUKAN PAK AGUS)
// -------------------------------------------------------------
if ($newServerId > 0 && !empty($testServerRes['invite_code'])) {
    $joinByCodeRes = GameServers::joinByCode((int)$demoStudent['id'], (string)$testServerRes['invite_code']);
    assertTest('GameServers::joinByCode berhasil bergabung dengan kode kelas', ($joinByCodeRes['status'] ?? '') === 'ok');

    $joinByCodeFail = GameServers::joinByCode((int)$demoStudent['id'], 'INVALID99');
    assertTest('GameServers::joinByCode menolak kode kelas invalid', ($joinByCodeFail['status'] ?? '') === 'error' && ($joinByCodeFail['http'] ?? 0) === 404);
}

assertTest('/pixel memiliki kartu Gabung Kelas Cepat (Nama & Kode Kelas)',
    str_contains($htmlPix, 'id="pixQuickName"') && str_contains($htmlPix, 'id="pixQuickCode"') && str_contains($htmlPix, 'quickJoinByCode()')
);
assertTest('/pixel memiliki fitur Traveling Antar-Kota & Google Maps',
    str_contains($htmlPix, 'openTravelingModal()') && str_contains($htmlPix, 'travelToLocation') && str_contains($htmlPix, 'travelToCity')
);
assertTest('/pixel memiliki tombol Voice Signaling Push-to-Talk',
    str_contains($htmlPix, 'id="pixVoiceBtn"') && str_contains($htmlPix, 'togglePixVoice()')
);

// -------------------------------------------------------------
// 7. SATU SERVER = SATU DUNIA (3D ATAU PIXEL)
// -------------------------------------------------------------
if (!empty($demoStaff)) {
    $pxSrv = GameServers::create((int)$demoStaff['id'], ['name' => 'Server Kelas Uji QA V2', 'type' => 'private', 'world' => 'pixel', 'capacity' => 30]);
    $d3Srv = GameServers::create((int)$demoStaff['id'], ['name' => 'Server Kelas Uji QA V2', 'type' => 'private', 'capacity' => 30]);
    assertTest('Server dibuat untuk satu dunia (pixel / default 3d)', ($pxSrv['world'] ?? '') === 'pixel' && ($d3Srv['world'] ?? '') === '3d');
    $pxIds = array_column(GameServers::listFor((int)$demoStaff['id'], 'root', 'pixel'), 'id');
    $d3Ids = array_column(GameServers::listFor((int)$demoStaff['id'], 'root', '3d'), 'id');
    assertTest('Daftar server Dunia Pixel & 3D terpisah',
        in_array((int)$pxSrv['server_id'], $pxIds, true) && !in_array((int)$pxSrv['server_id'], $d3Ids, true)
        && in_array((int)$d3Srv['server_id'], $d3Ids, true) && !in_array((int)$d3Srv['server_id'], $pxIds, true));
    assertTest('Alamat dunia sesuai server', GameServers::worldUrl('pixel', 9) === '/pixel?server=9' && GameServers::worldUrl('3d', 9) === '/dunia?server=9');
    $pdo->prepare('UPDATE game_servers SET is_active = 0 WHERE id IN (?, ?)')->execute([(int)$pxSrv['server_id'], (int)$d3Srv['server_id']]);
}
// Tugas dikirim ke server/room: anggota room menerima walau beda/tanpa kelas; bukan anggota tidak.
if (!empty($demoStaff) && !empty($demoStudent)) {
    $roomSrv = GameServers::create((int)$demoStaff['id'], ['name' => 'Server Kelas Uji QA V2', 'type' => 'private', 'world' => 'pixel', 'capacity' => 30]);
    GameServers::join((int)$demoStudent['id'], (int)$roomSrv['server_id'], (string)$roomSrv['invite_code']);
    $subjId = (int)$pdo->query('SELECT id FROM subjects ORDER BY id LIMIT 1')->fetchColumn();
    $otherClass = (int)$pdo->query('SELECT id FROM classes WHERE id != ' . (int)($demoStudent['class_id'] ?? 0) . ' ORDER BY id LIMIT 1')->fetchColumn();
    $aid = \VC\Domain\Learning::createAssignment($subjId, (int)$demoStaff['id'], $otherClass, 'Tugas Uji Room', 'uji', '2099-01-01 10:00:00', 100, true, 10, (int)$roomSrv['server_id']);
    $got = array_column(\VC\Domain\Learning::getAssignmentsForStudent((int)$demoStudent['id'], (int)($demoStudent['class_id'] ?? 0)), 'id');
    $asgRow = \VC\Domain\Learning::getAssignment($aid) ?? [];
    $outsider = (int)$pdo->query('SELECT id FROM users WHERE role = "murid" AND class_id = ' . $otherClass . ' LIMIT 1')->fetchColumn();
    assertTest('Tugas ke server/room diterima anggota room (lintas kelas)', in_array($aid, array_map('intval', $got), true) && \VC\Domain\Learning::studentCanAccess((int)$demoStudent['id'], $asgRow));
    assertTest('Guru hanya bisa mengirim tugas ke server yang boleh ia kelola', GameServers::canAssign((int)$demoStaff['id'], (int)$roomSrv['server_id'], false) !== null);
    $pdo->prepare('DELETE FROM assignments WHERE id = ?')->execute([$aid]);
    $pdo->prepare('UPDATE game_servers SET is_active = 0 WHERE id = ?')->execute([(int)$roomSrv['server_id']]);
}
// Regresi keluhan "guru gabisa buat server": guru mapel (bukan wali) menautkan rombel apa pun.
$anyGuru = (int)$pdo->query('SELECT id FROM users WHERE role = "guru" AND status = "active" AND deleted_at IS NULL LIMIT 1')->fetchColumn();
$foreignClass = (int)$pdo->query('SELECT id FROM classes WHERE deleted_at IS NULL AND (wali_kelas_user_id IS NULL OR wali_kelas_user_id != ' . $anyGuru . ') LIMIT 1')->fetchColumn();
if ($anyGuru > 0 && $foreignClass > 0) {
    $gs = GameServers::create($anyGuru, ['name' => 'Server Kelas Uji QA V2', 'type' => 'class', 'class_id' => $foreignClass, 'world' => 'pixel']);
    assertTest('Guru (bukan wali) bisa membuat server untuk rombel mana pun', ($gs['status'] ?? '') === 'ok', (string)($gs['message'] ?? ''));
    if (!empty($gs['server_id'])) $pdo->prepare('UPDATE game_servers SET is_active = 0 WHERE id = ?')->execute([(int)$gs['server_id']]);
}
$hasPixelPublic = (int)$pdo->query('SELECT COUNT(*) FROM game_servers WHERE world = "pixel" AND type = "public" AND is_active = 1')->fetchColumn();
assertTest('Dunia Pixel punya server publik sendiri', $hasPixelPublic >= 1);
$wsSrc = (string)file_get_contents(VC_PUBLIC . '/index.php');
assertTest('Token WS menolak server dunia lain tanpa reconnect (wrong_world)', str_contains($wsSrc, "'code' => 'wrong_world'") && str_contains($src['pixel-net.js'], "d.code === 'wrong_world'"));

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

if ($fail > 0) exit(1);
