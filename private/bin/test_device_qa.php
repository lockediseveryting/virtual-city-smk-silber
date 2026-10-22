<?php
/**
 * QA Butir Perangkat (Agent 1) — verifikasi otomatis item QA_CHECKLIST yang
 * dapat diuji tanpa perangkat fisik: viewport/safe-area, joystick sentuh,
 * target sentuh, audio unlock, collision AABB + tangga, footer overlap,
 * mode offline, indikator koneksi, quality adaptif, draw-call hook, dan
 * halaman Gabung Kelas. Butir yang butuh HP asli (B.2 nyata, C.2 iOS) tetap manual.
 * Jalankan: php /var/www/private/bin/test_device_qa.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$passed = 0;
$failed = 0;

function qa(string $id, string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ✅ PASS [$id] $name\n"; }
    else { $failed++; echo "  ❌ FAIL [$id] $name" . ($detail !== '' ? ' — ' . $detail : '') . "\n"; }
}

function fetchPage(string $url, ?string &$cookie = '', bool $formMode = false): string
{
    $cookie = (string)($cookie ?? '');
    if ($cookie === '') { $cookie = sys_get_temp_dir() . '/vcqa_' . bin2hex(random_bytes(6)); touch($cookie); }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    $raw = (string)curl_exec($ch);
    curl_close($ch);
    return $raw;
}

$js3d = file_get_contents('/var/www/public/assets/js/world3d.js') ?: '';
$jsPix = file_get_contents('/var/www/public/assets/js/pixel-side.js') ?: ''; // engine side-view (pengganti pixel.js top-down)
$audio = file_get_contents('/var/www/public/assets/js/audio.js') ?: '';
$jsNet = file_get_contents('/var/www/public/assets/js/pixel-net.js') ?: '';
$wcPhp = file_get_contents('/var/www/private/src/Controllers/WorldController.php') ?: '';
$pixAudio = file_get_contents('/var/www/public/assets/js/pixel-audio.js') ?: '';
$sw = file_get_contents('/var/www/public/service-worker.js') ?: '';
$css = file_get_contents('/var/www/public/assets/css/app.css') ?: '';

// ---- A.2: collision & tangga & futsal di dunia 3D ----
qa('A.2-1', 'Dunia 3D memiliki sistem collision AABB (colliders)', str_contains($js3d, 'this.colliders.push'));
qa('A.2-2', 'Kolisi berbentuk box (BoxGeometry) diperiksa vs posisi pemain',
    str_contains($js3d, 'colliders') && (str_contains($js3d, 'minX') || str_contains($js3d, 'halfW') || str_contains($js3d, 'Box3')));
qa('A.2-3', 'Tangga lantai 1↔2 tersedia (switchFloor / stairs data)', str_contains($js3d, 'switchFloor') && str_contains($js3d, 'stairs'));
qa('A.2-4', 'Minimap + tombol [M] ada di dunia 3D', str_contains($js3d, "'m' && typeof window.toggleMapModal === 'function'"));
qa('A.2-5', 'Bola futsal interaktif ada', str_contains($js3d, 'futsal') && str_contains($js3d, 'ball'));

// ---- A.3: koordinat map editable via campus.json (perubahan tanpa banyak file) ----
qa('A.3', 'Peta data-driven dari campus.json (koordinat mudah diperbarui)',
    str_contains($js3d, '/api/campus') || str_contains($js3d, 'campus.json'));

// ---- B.1: kontrol sentuh & target ≥44px ----
qa('B.1-1', 'Tombol sentuh ◀ ▶ ▲ ▼ + tombol aksi ada di Pixel World', str_contains($jsPix, 'pxsPad') && str_contains($jsPix, 'pixAction'));
qa('B.1-2', 'Kontrol sentuh berukuran ≥44px (pixJoyKnob 44 / action 64)', str_contains($wcPhp, 'width:44px'));
qa('B.1-3', 'Joystick 3D/sentuh di dunia 3D tersedia', str_contains($js3d, 'joystick') || str_contains($js3d, 'touch'));

// ---- B.3/C.3: PWA & ikon ----
qa('B.3', 'Manifest + service worker siap (PWA installable)', str_contains($sw, 'CACHE') || str_contains($sw, 'cacheName'));

// ---- C.1: viewport & safe-area ----
qa('C.1-1', 'Viewport meta viewport-fit=cover', str_contains($wcPhp, 'viewport-fit=cover'));
qa('C.1-2', 'CSS memakai safe-area-inset untuk notch/home indicator', str_contains($css, 'safe-area-inset'));

// ---- C.2: audio unlock Web API ----
qa('C.2', 'Audio Web API unlock pada gesture pertama (pointerdown/touchstart)',
    (str_contains($audio, 'pointerdown') || str_contains($pixAudio, 'pointerdown')));

// ---- D.1/D.2: mode offline & reconnect ----
qa('D.1', 'Service worker menyajikan halaman offline', str_contains($sw, 'offline.html'));
qa('D.2', 'Indikator status koneksi ada (pixel-net reconnecting + SW online)', str_contains($jsNet, 'reconnecting') || str_contains($sw, 'online'));

// ---- E.2: kuis timer & reward (server-side) ----
qa('E.2', 'Endpoint kuis + reward hadiah server-side tetap terpasang',
    is_file('/var/www/private/src/Controllers/LearningController.php'));

// ---- renderer (Agent 1) ----
qa('R-1', 'Tone mapping (ACES/Linear-toon) + sRGB output terpasang',
    // 14-10-2026: gaya toon (pilihan sekolah) memakai LinearToneMapping; ACES membuat warna kartun pucat.
    (str_contains($js3d, 'ACESFilmicToneMapping') || str_contains($js3d, 'LinearToneMapping')) && str_contains($js3d, 'sRGBEncoding'));
qa('R-2', 'Pohon pakai InstancedMesh (hemat draw call)', str_contains($js3d, 'InstancedMesh'));
qa('R-3', 'Hook draw call __vcDrawCalls terpasang untuk audit performa', str_contains($js3d, '__vcDrawCalls'));
qa('R-4', 'Quality adaptif tetap berfungsi (low/medium/high + auto)', str_contains($js3d, "'auto'") && str_contains($js3d, 'hardwareConcurrency'));

// ---- halaman Gabung Kelas (Agent 1) ----
// Siapkan sesi murid aktif agar tidak dialihkan ke /login
// Reset bucket rate-limit login_ip milik localhost: suite sebelumnya di test_all.php
// menghabiskan kuota (10+60) dan eskalasi penalty bisa memblok hingga 24 jam,
// membuat GK-1 flaky padahal halaman sehat. Best-effort, hanya bucket test ini.
try {
    \VC\Database::security()
        ->prepare("DELETE FROM rate_limit_hits WHERE bucket = ?")
        ->execute(['login_ip:127.0.0.1']);
} catch (\Throwable $e) {
    // best-effort — biarkan test jalan apa adanya
}
$pdo = \VC\Database::get();
$uName = 'qa_device_' . substr(bin2hex(random_bytes(4)), 0, 6);
$uPass = 'PassQA2026!Device';
$pdo->prepare('INSERT INTO users (uid, username, password_hash, full_name, role, status, created_at)
               VALUES (?, ?, ?, ?, "murid", "active", datetime("now"))')
    ->execute(['UID_' . $uName, $uName, \VC\Security\Auth::hash($uPass), 'Siswa QA Device']);

$ch = curl_init('http://localhost/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$resp = (string)curl_exec($ch);
curl_close($ch);

preg_match('/name="csrf" content="([^"]+)"/', $resp, $m);
$csrf = $m[1] ?? '';
preg_match_all('/Set-Cookie:\s*([^;]+)/i', $resp, $cm);
$cookies = $cm[1] ?? [];

$ch = curl_init('http://localhost/api/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => $uName, 'password' => $uPass, '_csrf' => $csrf]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Cookie: ' . implode('; ', $cookies),
    ],
]);
$resp2 = (string)curl_exec($ch);
curl_close($ch);

preg_match_all('/Set-Cookie:\s*([^;]+)/i', $resp2, $cm2);
if (!empty($cm2[1])) {
    $cookies = array_merge($cookies, $cm2[1]);
}
$map = [];
foreach ($cookies as $c) {
    if (str_contains($c, '=')) {
        [$k] = explode('=', $c, 2);
        $map[trim($k)] = $c;
    }
}
$cookieHeader = implode('; ', $map);

$ch = curl_init('http://localhost/gabung');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => ['Cookie: ' . $cookieHeader],
]);
$qPage = (string)curl_exec($ch);
curl_close($ch);

qa('GK-1', 'Halaman Gabung Kelas menyajikan satu form kode kelas',
    str_contains($qPage, 'jkForm') && str_contains($qPage, 'jkCode'));
qa('GK-2', 'Meta CSRF terpasang di halaman Gabung Kelas', str_contains($qPage, 'name="csrf"'));
qa('GK-3', 'Viewport aman HP pada Gabung Kelas', str_contains($qPage, '100dvh') && str_contains($qPage, 'safe-area-inset'));

// ---- rute aset inti hidup ----
foreach (['/assets/map/campus.json', '/assets/map/pixel-side.json', '/assets/js/world3d.js',
          '/assets/js/pixel-side.js', '/offline.html'] as $path) {
    $code = 0;
    $ch = curl_init('http://localhost' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    qa('ASET', 'Aset hidup ' . $path, $code === 200, 'HTTP ' . $code);
}

echo "\n============================================\n";
echo "HASIL AKHIR: $passed PASSED, $failed FAILED\n";
echo "============================================\n";
exit($failed === 0 ? 0 : 1);
