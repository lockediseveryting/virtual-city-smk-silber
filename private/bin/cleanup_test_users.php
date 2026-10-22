<?php
// Bersihkan akun uji sisa (e2e_room_*, agent3_tg_*, bat3_*, qa_srv_*) beserta baris
// dependennya. Idempoten: aman dijalankan berkali-kali. Backup DB otomatis dulu.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use VC\Database;

$junkPatterns = ['e2e_room_%', 'agent3_tg_%', 'bat3_%', 'qa_srv_%'];

$pdo = Database::get();

// 1. Backup
$src = VC_PRIVATE . '/data/virtual_city.sqlite';
$dst = VC_PRIVATE . '/backups/vc-pre-cleanup-' . date('Ymd-His') . '.sqlite';
if (!is_dir(dirname($dst))) {
    mkdir(dirname($dst), 0770, true);
}
copy($src, $dst);
echo "Backup: {$dst}\n";

// 2. Kumpulkan id junk
$ids = [];
foreach ($junkPatterns as $p) {
    $st = $pdo->prepare('SELECT id, username FROM users WHERE username LIKE ?');
    $st->execute([$p]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ids[(int)$r['id']] = $r['username'];
    }
}
if (!$ids) {
    echo "Tidak ada akun uji sisa. Selesai.\n";
    exit(0);
}

// 3. Hapus dependen lalu users, dalam satu transaksi
$depTables = [
    'user_permissions', 'terms_acceptances', 'sessions', 'class_members',
    'org_members', 'event_attendance', 'certificates', 'user_profiles',
    'friendships', 'stories', 'conversation_members', 'transactions',
    'user_progress', 'mission_progress', 'user_badges', 'user_items',
    'notifications', 'notification_prefs', 'active_players',
    'telegram_link_codes', 'telegram_links', 'user_characters',
    'location_visits', 'push_subscriptions', 'game_chat_messages',
    'game_server_members', 'messages', 'submissions', 'quiz_attempts',
    'story_views', 'audit_log', 'event_attendance', 'certificates',
];
$byCreator = ['schedule_versions', 'schedule_overrides', 'events', 'conversations', 'game_servers'];

$pdo->beginTransaction();
try {
    $idList = implode(',', array_keys($ids));
    $removed = 0;
    foreach ($depTables as $t) {
        $removed += $pdo->exec("DELETE FROM {$t} WHERE user_id IN ({$idList})");
    }
    foreach ($byCreator as $t) {
        $cols = array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $conds = [];
        if (in_array('created_by', $cols, true)) {
            $conds[] = "created_by IN ({$idList})";
        }
        if (in_array('owner_id', $cols, true)) {
            $conds[] = "owner_id IN ({$idList})";
        }
        if ($conds !== []) {
            $removed += $pdo->exec("DELETE FROM {$t} WHERE " . implode(' OR ', $conds));
        }
    }
    $removed += $pdo->exec("DELETE FROM friendships WHERE friend_id IN ({$idList})");
    $removed += $pdo->exec("DELETE FROM users WHERE id IN ({$idList})");
    $pdo->commit();
    echo "Dihapus {$removed} baris dependen + " . count($ids) . " akun:\n";
    foreach ($ids as $u) {
        echo "  - {$u}\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'GAGAL, rollback: ' . $e->getMessage() . "\n");
    exit(1);
}
