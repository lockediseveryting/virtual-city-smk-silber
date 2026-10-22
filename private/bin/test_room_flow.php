<?php
/**
 * E2E - Alur Room/Kelas (Agent 1): guru membuat room, murid join dengan kode,
 * kode salah ditolak, siswa tidak bisa membuat room. Akun uji dibuat sementara lalu dihapus.
 * Jalankan: php /var/www/private/bin/test_room_flow.php
 */
declare(strict_types=1);

$root = __DIR__ . '/..';
require $root . '/src/bootstrap.php';

use VC\Database;
use VC\Security\Auth;

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ✅ PASS: $name\n"; }
    else { $failed++; echo "  ❌ FAIL: $name\n"; }
}

function httpJson(string $method, string $url, ?array $body, ?string &$cookie = '', bool $formMode = false): array
{
    // $cookie = path cookie-jar curl (seperti browser: 1 nilai per nama domain).
    $cookie = (string)($cookie ?? '');
    if ($cookie === '') { $cookie = sys_get_temp_dir() . '/vcija_' . bin2hex(random_bytes(6)); touch($cookie); }
    $ch = curl_init($url);
    $headers = [];
    if ($body !== null) {
        if ($formMode) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $bodyTxt = substr((string)$raw, $hsize);
    $json = json_decode($bodyTxt, true) ?? [];
    return [$status, $json, $bodyTxt];
}

// ---------- Siapkan akun uji ----------
$pdo = Database::get();
$prefix = 'e2e_room_' . substr(bin2hex(random_bytes(3)), 0, 5) . '_';
$gUser = $prefix . 'guru';
$s1User = $prefix . 'siswa1';

$ins = $pdo->prepare('INSERT INTO users (uid, username, password_hash, full_name, role, status, created_at)
                      VALUES (?, ?, ?, ?, ?, "active", datetime("now"))');
$mk = function (string $uname, string $fullname, string $role) use ($ins, $prefix, $pdo): int {
    static $n = 0;
    $n++;
    $ins->execute([$prefix . 'UID' . $n, $uname, Auth::hash('PassE2E2026!Strong'), $fullname, $role]);
    return (int)$pdo->lastInsertId();
};
$gId = $mk($gUser, 'E2E Guru Room', 'guru');
$s1Id = $mk($s1User, 'E2E Siswa Satu', 'murid');

$base = 'http://localhost';

// ---------- 1) Login guru ----------
$cGuru = '';
[, , $loginPage] = httpJson('GET', $base . '/login', null, $cGuru);
$loginCsrf = '';
preg_match('/name="_csrf"[^>]*value="([^"]+)"/', (string)$loginPage, $m);
if (empty($m[1])) { preg_match('/name="csrf" content="([^"]+)"/', (string)$loginPage, $m); }
$loginCsrf = $m[1] ?? '';
[$st, $res] = httpJson('POST', $base . '/api/login', [
    'username' => $gUser,
    'password' => 'PassE2E2026!Strong',
    '_csrf' => $loginCsrf,
], $cGuru, true);
assertTest('Guru uji login sukses', $st === 200 && ($res['status'] ?? '') === 'success');

[, , $pg] = httpJson('GET', $base . '/pixel', null, $cGuru);
// Normalisasi: sisakan kejadian TERAKHIR tiap cookie (sesi terbaru hasil regenerate login).
$seen = [];
$final = [];
foreach (explode('; ', (string)$cGuru) as $c) {
    $name = trim(explode('=', $c)[0]);
    if ($name === '') { continue; }
    $seen[$name] = $c;
}
$cGuru = implode('; ', $seen);
preg_match('/name="csrf" content="([^"]+)"/', (string)$pg, $m2);
$gCsrf = $m2[1] ?? '';
assertTest('Guru memperoleh CSRF token', $gCsrf !== '');

// ---------- 2) Guru membuat room ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/create',
    ['name' => 'E2E Kelas Uji Agent1', '_csrf' => $gCsrf], $cGuru);
if (($res['status'] ?? '') !== 'ok') { echo '     [debug create] HTTP ' . $st . ' body=' . json_encode($res) . "\n"; }
assertTest('Guru buat room sukses + kode unik', $st === 200 && ($res['status'] ?? '') === 'ok' && isset($res['invite_code']));
$roomCode = (string)($res['invite_code'] ?? '');
$roomId = (int)($res['server_id'] ?? 0);
echo "     Room #$roomId kode: $roomCode\n";

// ---------- 3) Siswa login ----------
[, , $loginPage2] = httpJson('GET', $base . '/login', null, $cS1);
$s1LoginCsrf = '';
preg_match('/name="_csrf"[^>]*value="([^"]+)"/', (string)$loginPage2, $mm);
if (empty($mm[1])) { preg_match('/name="csrf" content="([^"]+)"/', (string)$loginPage2, $mm); }
$s1LoginCsrf = $mm[1] ?? '';
[$st, $res] = httpJson('POST', $base . '/api/login', [
    'username' => $s1User,
    'password' => 'PassE2E2026!Strong',
    '_csrf' => $s1LoginCsrf,
], $cS1, true);
assertTest('Siswa 1 login sukses', $st === 200 && ($res['status'] ?? '') === 'success');

[, , $pg2] = httpJson('GET', $base . '/pixel', null, $cS1);
preg_match('/name="csrf" content="([^"]+)"/', (string)$pg2, $m3);
$s1Csrf = $m3[1] ?? '';

// ---------- 4) Kode salah ditolak ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/join', ['code' => 'ZZZZZZZZ', '_csrf' => $s1Csrf], $cS1);
assertTest('Kode salah ditolak (404)', $st === 404);

// ---------- 5) Join dengan kode benar ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/join', ['code' => $roomCode, '_csrf' => $s1Csrf], $cS1);
assertTest('Siswa join room via kode (tanpa server_id)', $st === 200 && ($res['status'] ?? '') === 'ok');

// ---------- 5b) Halaman Gabung Kelas satu-form ----------
[$st, , $jkPage] = httpJson('GET', $base . '/gabung', null, $cS1);
assertTest('Halaman Gabung Kelas tampil utk murid (form + judul)', $st === 200
    && str_contains((string)$jkPage, 'jkForm') && str_contains((string)$jkPage, 'Gabung Kelas'));

$cGuest = ''; // jar baru = tanpa sesi
[$st, , $guestPage] = httpJson('GET', $base . '/gabung', null, $cGuest);
// Arahan Pak Agus 14-10-2026: tamu gabung cukup KODE ROOM + NAMA, tanpa login.
assertTest('Tamu melihat form gabung cepat (kode room + nama) di /gabung',
    $st === 200 && str_contains((string)$guestPage, 'id="gCode"') && str_contains((string)$guestPage, 'id="gName"'));
preg_match('/name="csrf" content="([^"]+)"/', (string)$guestPage, $mg);
$guestCsrf = $mg[1] ?? '';
[$st, $res] = httpJson('POST', $base . '/api/gabung', ['code' => 'ZZZZ9999', 'name' => 'Tamu Salah', '_csrf' => $guestCsrf], $cGuest);
assertTest('Gabung cepat menolak kode room salah (404)', $st === 404 && ($res['status'] ?? '') === 'error');
// Regresi: room bertipe KELAS (seperti XI TJKT 3) — tamu dulu ditolak 403 saat minta token WS.
$anyClass = (int)$pdo->query('SELECT id FROM classes ORDER BY id LIMIT 1')->fetchColumn();
if ($anyClass > 0) {
    $pdo->prepare('UPDATE game_servers SET type = "class", class_id = ? WHERE id = ?')->execute([$anyClass, $roomId]);
}
$guestName = 'E2E Tamu ' . $prefix;
[$st, $res] = httpJson('POST', $base . '/api/gabung', ['code' => strtolower($roomCode), 'name' => $guestName, '_csrf' => $guestCsrf], $cGuest);
assertTest('Gabung cepat sukses → redirect /dunia?server={id}', $st === 200 && ($res['status'] ?? '') === 'ok'
    && ($res['redirect'] ?? '') === '/dunia?server=' . $roomId && !isset($res['user_id']));
$gst = $pdo->prepare('SELECT id, role FROM users WHERE full_name = ? ORDER BY id DESC LIMIT 1');
$gst->execute([$guestName]);
$guestRow = $gst->fetchAll(\PDO::FETCH_ASSOC)[0] ?? []; // fetchAll: jangan biarkan cursor (snapshot WAL) terbuka
$guestId = (int)($guestRow['id'] ?? 0);
$gm = $pdo->prepare('SELECT COUNT(*) FROM game_server_members WHERE server_id = ? AND user_id = ?');
$gm->execute([$roomId, $guestId]);
$guestIsMember = (int)$gm->fetchColumn() > 0;
$gm->closeCursor();
assertTest('Tamu tersimpan ber-role "tamu" & anggota room', ($guestRow['role'] ?? '') === 'tamu' && $guestIsMember);
[$st, , $guestWorld] = httpJson('GET', $base . '/dunia?server=' . $roomId, null, $cGuest);
preg_match('/name="csrf" content="([^"]+)"/', (string)$guestWorld, $mg2);
assertTest('Tamu bisa membuka ruang 3D /dunia (+ voice.js dimuat)', $st === 200 && str_contains((string)$guestWorld, 'viewport3D')
    && str_contains((string)$guestWorld, '/assets/js/voice.js'));
[$st, $res] = httpJson('GET', $base . '/api/ws/token?server=' . $roomId . '&_csrf=' . urlencode($mg2[1] ?? ''), null, $cGuest);
$tokPayload = json_decode((string)base64_decode(explode('.', (string)($res['token'] ?? ''))[0]), true) ?: [];
assertTest('Tamu mendapat token WS untuk room-nya (server_id + nama asli)', $st === 200 && ($res['status'] ?? '') === 'ok'
    && (int)($tokPayload['server_id'] ?? 0) === $roomId && ($tokPayload['full_name'] ?? '') === $guestName);
[$st, , $fenced] = httpJson('GET', $base . '/tugas', null, $cGuest);
$gNotInClass = $pdo->prepare('SELECT COUNT(*) FROM class_members WHERE user_id = ?');
$gNotInClass->execute([$guestId]);
assertTest('Tamu di room kelas tidak masuk roster kelas', (int)$gNotInClass->fetchColumn() === 0);
$gNotInClass->closeCursor();
assertTest('Tamu dipagari: halaman lain dialihkan ke /dunia', $st === 200 && str_contains((string)$fenced, 'viewport3D'));
[$st, $res] = httpJson('POST', $base . '/api/servers/create', ['name' => 'Hack', '_csrf' => $mg2[1] ?? ''], $cGuest);
assertTest('Tamu dipagari: API di luar ruang 3D ditolak 403', $st === 403);
$pdo->prepare('UPDATE game_servers SET type = "public", class_id = NULL WHERE id = ?')->execute([$roomId]); // kembalikan untuk langkah berikutnya

// ---------- 6) Token WS untuk murid di room guru ----------
[$st, $res] = httpJson('GET', $base . '/api/ws/token?server=' . $roomId . '&_csrf=' . $s1Csrf, null, $cS1);
assertTest('Murid memperoleh token WS di room guru', $st === 200 && ($res['status'] ?? '') === 'ok' && !empty($res['url']));

// ---------- 7) Siswa dilarang membuat room ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/create', ['name' => 'Dilarang', '_csrf' => $s1Csrf], $cS1);
assertTest('Siswa ditolak membuat room (403)', $st === 403);

// ---------- 8) Daftar server memuat room guru ----------
[$st, $res] = httpJson('GET', $base . '/api/servers?_csrf=' . $s1Csrf, null, $cS1);
$found = false;
foreach (($res['servers'] ?? []) as $s) {
    if ((int)($s['id'] ?? 0) === $roomId) { $found = true; }
}
assertTest('Room guru tampil pada daftar server murid', $found);

// ---------- 9) Guru update room (edit server) ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/update', [
    'server_id' => $roomId,
    'name' => 'Room Guru Diedit',
    'capacity' => 45,
    'access' => 'open',
    'regen_code' => 1,
    '_csrf' => $gCsrf
], $cGuru);
assertTest('Guru berhasil mengedit room miliknya', $st === 200 && ($res['status'] ?? '') === 'ok' && ($res['name'] ?? '') === 'Room Guru Diedit');

// ---------- 10) Siswa dilarang mengedit room guru ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/update', [
    'server_id' => $roomId,
    'name' => 'Hacked by Siswa',
    '_csrf' => $s1Csrf
], $cS1);
assertTest('Siswa ditolak mengedit room (403)', $st === 403);

// ---------- 11) Guru menghapus room miliknya ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/delete', [
    'server_id' => $roomId,
    '_csrf' => $gCsrf
], $cGuru);
assertTest('Guru berhasil menghapus room miliknya', $st === 200 && ($res['status'] ?? '') === 'ok');

// ---------- 12) Siswa dilarang menghapus room ----------
[$st, $res] = httpJson('POST', $base . '/api/servers/delete', [
    'server_id' => 1,
    '_csrf' => $s1Csrf
], $cS1);
assertTest('Siswa ditolak menghapus server (403)', $st === 403);

// ---------- Bersih-bersih ----------
$pdo->prepare('DELETE FROM game_server_members WHERE server_id = ?')->execute([$roomId]);
$pdo->prepare('DELETE FROM game_servers WHERE id = ?')->execute([$roomId]);
$pdo->prepare('DELETE FROM class_members WHERE user_id = ?')->execute([$s1Id]);
$pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$gId, $s1Id]);
if (!empty($guestId)) { $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "tamu"')->execute([$guestId]); }

echo "\n============================================\n";
echo "HASIL AKHIR: $passed PASSED, $failed FAILED\n";
echo "============================================\n";
exit($failed === 0 ? 0 : 1);
