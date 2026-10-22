<?php
// Import student roster from CSV: nis,full_name,class_name
// Usage: php private/bin/import_roster.php <file.csv>
// CSV rules: UTF-8, header optional (auto-detected), delimiter comma/semicolon/tab.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require __DIR__ . '/../src/bootstrap.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php private/bin/import_roster.php <roster.csv>\nFormat kolom: nis,full_name,class_name\n");
    exit(1);
}
$size = filesize($file);
if ($size > 5 * 1024 * 1024) {
    fwrite(STDERR, "File terlalu besar (maks 5 MB).\n");
    exit(1);
}

$pdo = VC\Database::get();
$pdo->beginTransaction();

// class name cache
$classes = [];
$ins = $pdo->prepare('INSERT INTO student_roster (nis, full_name, class_id) VALUES (?, ?, ?) ON CONFLICT(nis) DO UPDATE SET full_name = excluded.full_name, class_id = excluded.class_id');
$insClass = $pdo->prepare('INSERT INTO classes (uid, program_id, grade, name) VALUES (?, 1, ?, ?)');
$classByName = static function (string $name) use ($pdo, &$classes, $insClass): int {
    $name = trim($name);
    if (isset($classes[$name])) {
        return $classes[$name];
    }
    $stmt = $pdo->prepare('SELECT id FROM classes WHERE name = ? AND deleted_at IS NULL');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $classes[$name] = (int)$id;
        return (int)$id;
    }
    // grade inferred from leading X/XI/XII
    $grade = 'X';
    if (preg_match('/^(XII|XI|X)\b/i', $name, $m)) {
        $grade = strtoupper($m[1]);
    }
    $insClass->execute([bin2hex(random_bytes(8)), $grade, $name]);
    $classes[$name] = (int)$pdo->lastInsertId();
    return (int)$pdo->lastInsertId();
};

$fh = fopen($file, 'rb');
if (!$fh) {
    fwrite(STDERR, "Tidak bisa membuka file.\n");
    exit(1);
}
$lineNo = 0;
$ok = 0;
$bad = [];
while (($row = fgetcsv($fh, 0, ',')) !== false) {
    $lineNo++;
    if ($lineNo === 1 && (!ctype_digit(trim($row[0] ?? '')))) {
        continue; // header row
    }
    $nis = trim($row[0] ?? '');
    $name = trim($row[1] ?? '');
    $class = trim($row[2] ?? '');
    if ($nis === '' || !ctype_digit($nis) || strlen($nis) > 20 || $name === '' || strlen($name) > 100) {
        $bad[] = "baris {$lineNo}";
        continue;
    }
    $classId = $class !== '' ? $classByName($class) : null;
    $ins->execute([$nis, $name, $classId]);
    $ok++;
}
fclose($fh);

if ($bad !== []) {
    // keep good rows, report bad ones (all-or-nothing per row; transaction-safe)
    $pdo->commit();
    fwrite(STDERR, 'Baris tidak valid: ' . implode(', ', $bad) . "\n");
} else {
    $pdo->commit();
}
VC\Security\AuditLog::log('roster_import', ['lines' => $lineNo, 'ok' => $ok, 'bad' => count($bad)]);
echo "Selesai: {$ok} baris dimasukkan, " . count($bad) . " baris gagal.\n";
