<?php
// Break-glass ROOT password reset. CLI ONLY, requires shell access.
// Usage: php private/bin/reset_root.php <username>
// Generates a random temporary password, prints it ONCE, forces change at next login.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require __DIR__ . '/../src/bootstrap.php';

$username = strtolower($argv[1] ?? '');
if ($username === '') {
    fwrite(STDERR, "Usage: php private/bin/reset_root.php <root_username>\n");
    exit(1);
}
$pdo = \VC\Database::get();
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND role = "root" AND deleted_at IS NULL');
$stmt->execute([$username]);
$userId = $stmt->fetchColumn();
if (!$userId) {
    fwrite(STDERR, "Akun ROOT '$username' tidak ditemukan.\n");
    exit(1);
}

// Unambiguous alphabet, 16 chars.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
$temp = '';
for ($i = 0; $i < 16; $i++) {
    $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
$pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
    ->execute([\VC\Security\Auth::hash($temp), $userId]);
// Revoke all sessions for safety.
$pdo->prepare('UPDATE sessions SET revoked = 1 WHERE user_id = ?')->execute([$userId]);

\VC\Security\AuditLog::log('root_breakglass_reset', ['username' => $username], (int)$userId, 'ok');
echo "Password sementara untuk '$username': $temp\n";
echo "PENTING: catat sekarang, muncul sekali ini saja. Wajib ganti saat login berikutnya.\n";
