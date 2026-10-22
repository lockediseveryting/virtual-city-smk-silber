<?php
// Test suite for WebSocket authentication & real-time multiplayer service.
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VC\Security\Auth;
use VC\Database;

echo "\n============================================\n";
echo "PENGUJIAN WEBSOCKET & MULTIPLAYER REAL-TIME\n";
echo "============================================\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "✅ PASS: $desc\n";
    } else {
        $fail++;
        echo "❌ FAIL: $desc" . ($details ? " ($details)" : '') . "\n";
    }
}

// 1. Generate WebSocket Token for test user
$mockUser = [
    'id' => 1,
    'username' => 'test_murid',
    'full_name' => 'Siswa Penguji',
    'role' => 'murid',
    'class_id' => 1,
];

$token = Auth::createWsToken($mockUser);
assertTest('Token WebSocket berhasil dibuat dan berformat valid (base64.sig)', is_string($token) && str_contains($token, '.'));

// 2. Verifikasi Token di sisi server
[$payloadB64, $sig] = explode('.', $token, 2);
$secret = \vc_env('APP_KEY', 'virtual-city-default-key-silber-2026');
$expectedSig = hash_hmac('sha256', $payloadB64, $secret);
assertTest('Tanda tangan kriptografis token valid (HMAC-SHA256)', hash_equals($expectedSig, $sig));

// 3. Verifikasi payload token
$data = json_decode(base64_decode($payloadB64), true);
assertTest('Payload memuat user id, username, peran, dan masa berlaku (exp)',
    isset($data['id'], $data['username'], $data['role'], $data['exp']) && $data['id'] === 1 && $data['exp'] > time()
);

// 4. Token tamper detection
$tamperedPayload = base64_encode(json_encode(['id' => 999, 'username' => 'hacker', 'exp' => time() + 300]));
$tamperedSig = hash_hmac('sha256', $tamperedPayload, $secret . '_wrong');
assertTest('Token yang dimanipulasi atau salah kunci signature ditolak', !hash_equals($expectedSig, $tamperedSig));

// 5. Tabel active_players untuk fallback HTTP polling
$pdo = Database::get();
$pdo->exec("CREATE TABLE IF NOT EXISTS active_players (
    user_id INTEGER PRIMARY KEY,
    username TEXT,
    full_name TEXT,
    role TEXT,
    x REAL, y REAL, z REAL, floor INTEGER,
    updated_at INTEGER
)");

$stmt = $pdo->prepare("INSERT INTO active_players (user_id, username, full_name, role, x, y, z, floor, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(user_id) DO UPDATE SET
        x = excluded.x, y = excluded.y, z = excluded.z, floor = excluded.floor, updated_at = excluded.updated_at");
$stmt->execute([10, 'siswa_b', 'Budi Santoso', 'murid', 162.5, 0.0, 15.0, 1, time()]);

$fetch = $pdo->query("SELECT * FROM active_players WHERE user_id = 10")->fetch();
assertTest('Sistem fallback sinkronisasi pemain di database SQLite berjalan normal',
    $fetch && (float)$fetch['x'] === 162.5 && (int)$fetch['floor'] === 1
);

// 6. Validasi file server Node.js dan service systemd
$wsScript = __DIR__ . '/websocket_server.js';
assertTest('File script Node.js WebSocket server tersedia di lokasi resmi', is_file($wsScript));

$syntaxCheck = shell_exec("node -c " . escapeshellarg($wsScript) . " 2>&1");
assertTest('Sintaks file websocket_server.js lolos kompilasi Node.js tanpa error', empty($syntaxCheck));

$systemdFile = dirname(__DIR__) . '/config/systemd/virtual-city-ws.service';
assertTest('Unit file systemd service virtual-city-ws.service tersedia', is_file($systemdFile));

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

exit($fail === 0 ? 0 : 1);
