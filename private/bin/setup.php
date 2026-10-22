<?php
// One-time ROOT provisioning. CLI ONLY: php private/bin/setup.php
// Reads private/config/seed.env, hashes with Argon2id, inserts exactly two root
// accounts, then locks itself (creates .setup_done) and asks owner to delete seed.env.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../src/bootstrap.php';

$doneFlag = \VC_PRIVATE . '/config/.setup_done';
if (is_file($doneFlag)) {
    fwrite(STDERR, "Setup sudah pernah dijalankan. Untuk reset akun ROOT pakai: php private/bin/reset_root.php\n");
    exit(1);
}

$seedFile = \VC_PRIVATE . '/config/seed.env';
$createdFlag = \VC_PRIVATE . '/config/.seed_consumed';
if (!is_file($seedFile)) {
    fwrite(STDERR, "seed.env tidak ditemukan.\nSalin: cp private/config/seed.env.example private/config/seed.env\nlalu isi password kuat (min 14 karakter). Password TIDAK disimpan / dicetak oleh script ini.\n");
    exit(1);
}

$lines = [];
foreach (file($seedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    $lines[trim($k)] = trim($v);
}

$commonFile = \VC_PRIVATE . '/config/common_passwords.txt';
$common = is_file($commonFile) ? array_flip(array_map('trim', file($commonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))) : [];

function passwordProblems(string $pw, string $user, array $common): array
{
    $errs = [];
    if (strlen($pw) < 14) {
        $errs[] = 'Password ROOT minimal 14 karakter.';
    }
    if (str_contains(strtolower($pw), strtolower($user))) {
        $errs[] = 'Password tidak boleh memuat username.';
    }
    if (isset($common[strtolower($pw)])) {
        $errs[] = 'Password ada di daftar password umum. Pilih yang lain.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $errs[] = 'Password harus mengandung huruf dan angka (sarankan: + simbol).';
    }
    return $errs;
}

$pdo = \VC\Database::get();

$accounts = [
    ['user' => $lines['ROOT1_USER'] ?? '', 'pass' => $lines['ROOT1_PASS'] ?? ''],
    ['user' => $lines['ROOT2_USER'] ?? '', 'pass' => $lines['ROOT2_PASS'] ?? ''],
];

$allOk = true;
foreach ($accounts as $i => $acc) {
    if ($acc['user'] === '') {
        fwrite(STDERR, "ROOT" . ($i + 1) . "_USER kosong di seed.env.\n");
        $allOk = false;
        continue;
    }
    $errs = passwordProblems($acc['pass'], $acc['user'], $common);
    if ($errs !== []) {
        fwrite(STDERR, "Password ROOT" . ($i + 1) . " ({$acc['user']}) lemah:\n  - " . implode("\n  - ", $errs) . "\nEdit seed.env lalu jalankan lagi.\n");
        $allOk = false;
        continue;
    }
    $hash = \VC\Security\Auth::hash($acc['pass']);
    $uid = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, status, must_change_password)
        VALUES (?, LOWER(?), ?, "root", ?, "active", 0)
        ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash, role = "root", deleted_at = NULL, status = "active"')
        ->execute([$uid, $acc['user'], $hash, 'ROOT ' . ($i + 1)]);
    \VC\Security\AuditLog::log('root_seeded', ['username' => $acc['user']]);
}

if (!$allOk) {
    exit(1);
}
touch($doneFlag);
@unlink($createdFlag);
chmod($doneFlag, 0600);
echo "✅ Dua akun ROOT berhasil dibuat.\n";
echo "⚠️  PENTING: hapus file seed.env sekarang:  rm private/config/seed.env\n";
echo "   (Script ini sudah terkunci, tapi file seed.env JANGAN ditinggalkan di server.)\n";
