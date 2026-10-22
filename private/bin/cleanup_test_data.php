<?php
// Bersihkan data yang dibuat suite tes (akun uji, server/room uji, chat & keanggotaannya).
// Dipanggil otomatis di akhir test_all.php; bisa juga dijalankan manual.
// audit_log TIDAK PERNAH disentuh (hash-chain).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = \VC\Database::get();

$userIds = $pdo->query(
    "SELECT id FROM users
     WHERE username IN ('siswa_test_1', 'siswa_test_2')
        OR username LIKE 'siswa\\_test\\_ui%' ESCAPE '\\'
        OR username LIKE 'guru\\_test\\_%' ESCAPE '\\'
        OR username LIKE 'e2e\\_room\\_%' ESCAPE '\\'
        OR username LIKE 'uji\\_desain\\_%' ESCAPE '\\'
        OR (role = 'tamu' AND full_name LIKE 'E2E %')"
)->fetchAll(PDO::FETCH_COLUMN);

$serverIds = $pdo->query(
    "SELECT id FROM game_servers
     WHERE name IN ('Server Kelas Uji QA V2', 'E2E Kelas Uji Agent1')"
)->fetchAll(PDO::FETCH_COLUMN);

$eventIds = $pdo->query(
    "SELECT id FROM events
     WHERE title LIKE 'Pengujian Presensi Event %' AND description = 'Event pengujian presensi otomatis'"
)->fetchAll(PDO::FETCH_COLUMN);

$in = static fn(array $ids): string => implode(',', array_map('intval', $ids));
$deleted = 0;

// Tugas buatan akun uji / dikirim ke server uji: notifikasinya bisa sampai ke siswa ASLI → hapus semuanya.
$asgIds = [];
if ($userIds || $serverIds) {
    $conds = [];
    if ($userIds) $conds[] = 'teacher_id IN (' . $in($userIds) . ')';
    if ($serverIds) $conds[] = 'server_id IN (' . $in($serverIds) . ')';
    $hasServerCol = in_array('server_id', array_column($pdo->query('PRAGMA table_info(assignments)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
    if (!$hasServerCol) $conds = array_values(array_filter($conds, fn($c) => !str_starts_with($c, 'server_id')));
    if ($conds) $asgIds = $pdo->query('SELECT id FROM assignments WHERE ' . implode(' OR ', $conds))->fetchAll(PDO::FETCH_COLUMN);
}

$pdo->beginTransaction();
if ($asgIds) {
    $ids = $in($asgIds);
    $note = $pdo->prepare("DELETE FROM notifications WHERE dedupe_key LIKE ?");
    foreach ($asgIds as $aid) { $note->execute(['%:assignment:' . (int)$aid]); $deleted += $note->rowCount(); }
    $deleted += $pdo->exec("DELETE FROM submissions WHERE assignment_id IN ($ids)");
    $deleted += $pdo->exec("DELETE FROM assignments WHERE id IN ($ids)");
}
if ($serverIds) {
    $ids = $in($serverIds);
    $keys = "'" . implode("','", array_map(fn($id) => 'srv-' . (int)$id, $serverIds)) . "'";
    $deleted += $pdo->exec("DELETE FROM game_chat_messages WHERE scope_key IN ($keys)");
    $deleted += $pdo->exec("DELETE FROM game_server_members WHERE server_id IN ($ids)");
    $deleted += $pdo->exec("DELETE FROM game_servers WHERE id IN ($ids)");
}
if ($eventIds) {
    $ids = $in($eventIds);
    $deleted += $pdo->exec("DELETE FROM event_attendance WHERE event_id IN ($ids)");
    $deleted += $pdo->exec("DELETE FROM certificates WHERE event_id IN ($ids)");
    $deleted += $pdo->exec("DELETE FROM events WHERE id IN ($ids)");
}
if ($userIds) {
    $ids = $in($userIds);
    // Percakapan buatan akun uji (mis. "🐞 Laporan" ke ROOT): hapus beserta anggota, pesan,
    // dan notifikasi yang sempat terkirim ke akun ROOT asli.
    $convIds = $pdo->query("SELECT id FROM conversations WHERE created_by IN ($ids)")->fetchAll(PDO::FETCH_COLUMN);
    if ($convIds) {
        $cids = $in($convIds);
        $note = $pdo->prepare('DELETE FROM notifications WHERE link = ?');
        foreach ($convIds as $cid) { $note->execute(['/obrolan?conv=' . (int)$cid]); $deleted += $note->rowCount(); }
        $deleted += $pdo->exec("DELETE FROM messages WHERE conversation_id IN ($cids)");
        $deleted += $pdo->exec("DELETE FROM conversation_members WHERE conversation_id IN ($cids)");
        $deleted += $pdo->exec("DELETE FROM conversations WHERE id IN ($cids)");
    }
    // Broadcast buatan akun uji
    $hasBc = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'broadcasts'")->fetchColumn();
    if ($hasBc) {
        // notifikasi broadcast/jadwal-kumpul/undangan-mabar dari akun uji yang terkirim ke akun asli
        $note = $pdo->prepare('DELETE FROM notifications WHERE dedupe_key LIKE ?');
        foreach ($pdo->query("SELECT id FROM broadcasts WHERE created_by IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $bid) { $note->execute(['%:broadcast:' . (int)$bid]); $deleted += $note->rowCount(); }
        $deleted += $pdo->exec("DELETE FROM broadcasts WHERE created_by IN ($ids)");
    }
    $hasSs = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'server_sessions'")->fetchColumn();
    if ($hasSs) {
        $note = $pdo->prepare('DELETE FROM notifications WHERE dedupe_key LIKE ?');
        foreach ($pdo->query("SELECT id FROM server_sessions WHERE created_by IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $sid) { $note->execute(['%:gather:' . (int)$sid]); $deleted += $note->rowCount(); }
        foreach ($pdo->query("SELECT id FROM parties WHERE captain_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $pid) { $note->execute(['%:party:' . (int)$pid . ':%']); $deleted += $note->rowCount(); }
    }
    // Berkas foto profil akun uji (public/assets/avatars/p_<hex>.*) ikut dihapus.
    try {
        foreach ($pdo->query("SELECT photo FROM user_profiles WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $ph) {
            if (preg_match('#^/assets/avatars/(p_[a-f0-9]{16}\.(jpg|png|webp))$#', (string)$ph, $pm)) { @unlink(__DIR__ . '/../../public/assets/avatars/' . $pm[1]); }
        }
    } catch (Throwable $e) { /* kolom photo belum ada */ }
    $userCols = ['user_id', 'friend_id', 'student_id', 'teacher_id', 'sender_id', 'owner_id', 'created_by', 'captain_id', 'from_id', 'to_id'];
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if ($t === 'audit_log' || $t === 'users') continue;
        foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (in_array($c['name'], $userCols, true)) {
                $deleted += $pdo->exec("DELETE FROM \"$t\" WHERE \"{$c['name']}\" IN ($ids)");
            }
        }
    }
    if ($hasSs) { // anggota/undangan party yang kaptennya akun uji (akun asli bisa ikut diundang)
        $deleted += $pdo->exec('DELETE FROM party_members WHERE party_id NOT IN (SELECT id FROM parties)');
        $deleted += $pdo->exec('DELETE FROM party_invites WHERE party_id NOT IN (SELECT id FROM parties)');
        $deleted += $pdo->exec('DELETE FROM server_session_attendance WHERE session_id NOT IN (SELECT id FROM server_sessions)');
    }
    $deleted += $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
}
// Versi jadwal buatan tes (test_weekly.csv / test_override.csv): jangan sampai menimpa jadwal asli.
$tv = $pdo->query("SELECT id FROM schedule_versions WHERE filename IN ('test_weekly.csv', 'test_override.csv')")->fetchAll(PDO::FETCH_COLUMN);
if ($tv) {
    $vids = $in($tv);
    $deleted += $pdo->exec("DELETE FROM schedule_entries WHERE version_id IN ($vids)");
    $deleted += $pdo->exec("DELETE FROM schedule_overrides WHERE version_id IN ($vids)");
    $deleted += $pdo->exec("DELETE FROM schedule_versions WHERE id IN ($vids)");
}
$pdo->commit();

$brokenLinks = \VC\Security\AuditLog::verify();
echo "Bersih: " . count($userIds) . " akun uji, " . count($serverIds) . " server uji, " . count($eventIds) . " event uji, " . count($asgIds) . " tugas uji, $deleted baris. "
    . "Audit log putus: $brokenLinks\n";
exit($brokenLinks === 0 ? 0 : 1);
