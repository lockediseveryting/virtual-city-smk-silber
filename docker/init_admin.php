<?php
// Docker: buat satu akun ROOT pertama bila database belum punya ROOT sama sekali.
// ADMIN_USERNAME (bawaan "admin") dan ADMIN_PASSWORD dari .env; password kosong → dibuat acak,
// dicetak sekali ke log container dan wajib diganti saat login pertama.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require __DIR__ . '/../src/bootstrap.php';

$pdo = \VC\Database::get();
$hasRoot = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'root' AND deleted_at IS NULL")->fetchColumn();
if ($hasRoot > 0) {
    exit(0);
}

$username = strtolower(trim((string)getenv('ADMIN_USERNAME')) ?: 'admin');
$password = (string)getenv('ADMIN_PASSWORD');
$generated = false;
if ($password === '') {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < 16; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $generated = true;
} elseif (strlen($password) < 12) {
    fwrite(STDERR, "[!] ADMIN_PASSWORD minimal 12 karakter. Akun admin belum dibuat.\n");
    exit(0);
}

$pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, status, must_change_password, created_at)
    VALUES (?, ?, ?, "root", ?, "active", ?, ?)')
    ->execute([bin2hex(random_bytes(16)), $username, \VC\Security\Auth::hash($password), 'Administrator', $generated ? 1 : 0, gmdate('c')]);
\VC\Security\AuditLog::log('root_seeded_docker', ['username' => $username]);

echo "\n==============================================================\n";
echo "  Akun ADMIN (ROOT) dibuat. Login staf di /login → mode Staf\n";
echo "  Username : {$username}\n";
if ($generated) {
    echo "  Password : {$password}\n";
    echo "  (acak, tampil SEKALI ini saja — wajib diganti saat login pertama)\n";
} else {
    echo "  Password : (sesuai ADMIN_PASSWORD di .env)\n";
}
echo "==============================================================\n\n";
