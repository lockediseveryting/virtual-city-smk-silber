<?php
// Automated test suite for Phase 6 additions owned here:
// notification center, secure uploads, classroom access control, and route integrity.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Domain\ClassroomAccess;
use VC\Domain\Learning;
use VC\Domain\Notification;
use VC\Domain\Upload;
use VC\Security\AuditLog;

echo "=== MEMULAI TEST SUITE PHASE 6 (NOTIFIKASI, UNGGAHAN AMAN, KONTROL AKSES) ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "✅ PASS: {$desc}\n";
    } else {
        $fail++;
        echo "❌ FAIL: {$desc}" . ($detail ? " ({$detail})" : '') . "\n";
    }
}

$pdo = Database::get();

// ---------------------------------------------------------------- Schema
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
assertTest('notifications table exists (migration 008 applied)', in_array('notifications', $tables, true));
assertTest('notification_prefs table exists', in_array('notification_prefs', $tables, true));
assertTest('notifications is idempotent via dedupe key',
    (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='idx_notif_dedupe'")->fetchColumn());

// ---------------------------------------------------------------- Notification center
$student = (int)$pdo->query("SELECT id FROM users WHERE role='murid' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$teacher = (int)$pdo->query("SELECT id FROM users WHERE role='guru' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if ($student <= 0) {
    $student = (int)$pdo->query("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
}

$key = 'test:' . bin2hex(random_bytes(4));
$before = Notification::unreadCount($student);
assertTest('Notification::push creates a notification', Notification::push($student, 'tugas', 'Uji notifikasi', 'Isi uji', '/tugas', $key));
$after = Notification::unreadCount($student);
assertTest('Unread count increases after push', $after === $before + 1, "before=$before after=$after");
assertTest('Duplicated dedupe_key is ignored (idempotent)', Notification::push($student, 'tugas', 'Uji notifikasi', 'Isi uji', '/tugas', $key) === false);

$list = Notification::forUser($student, 5);
assertTest('Notification::forUser returns newest first', $list !== [] && $list[0]['title'] === 'Uji notifikasi');

$firstId = (int)$list[0]['id'];
assertTest('Notification::markRead marks own notification', Notification::markRead($student, [$firstId]) === 1);
assertTest('Marked notification is now read', (int)$pdo->query("SELECT is_read FROM notifications WHERE id=$firstId")->fetchColumn() === 1);

// Cross-user isolation: another user must not be able to mark someone else's notifications.
$other = (int)$pdo->query("SELECT id FROM users WHERE id <> $student AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
assertTest('A different user cannot mark another user\'s notification read',
    $other > 0 ? Notification::markRead($other, [$firstId]) === 0 : true);

// Preferences
Notification::setPref($student, 'chat', false);
assertTest('Disabled category is not delivered', Notification::push($student, 'chat', 'Harusnya ditolak', '') === false);
$prefs = Notification::prefs($student);
assertTest('Preference is persisted', $prefs['chat'] === false);
Notification::setPref($student, 'chat', true);
assertTest('Re-enabling a category works', Notification::push($student, 'chat', 'Aktif lagi', '') === true);

// Security alerts can never be muted.
Notification::setPref($student, 'keamanan', false);
assertTest('Security ("keamanan") notifications cannot be muted', Notification::push($student, 'keamanan', 'Peringatan keamanan', '') === true);

// Broadcast
assertTest('Notification::pushMany notifies several users', Notification::pushMany([$student, $other], 'event', 'Broadcast uji', '', '/event', 'bc:' . $key) >= 1);

Notification::markAllRead($student);
assertTest('Notification::markAllRead clears the unread badge', Notification::unreadCount($student) === 0);

// ---------------------------------------------------------------- Secure uploads
$tmp = tempnam(sys_get_temp_dir(), 'vc');
file_put_contents($tmp, "plain text, not an image");
$fake = ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), 'name' => 'evil.png'];
$res = Upload::store($fake, 'avatar');
assertTest('Upload::store rejects non-HTTP-upload paths (CLI/direct invocation guard)', $res['ok'] === false);
assertTest('Upload::store rejects an unknown purpose', Upload::store($fake, 'nope')['ok'] === false);

$noUpload = ['tmp_name' => '/etc/passwd', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'p.png'];
assertTest('Upload::store refuses files not created by PHP upload', Upload::store($noUpload, 'avatar')['ok'] === false);
unlink($tmp);

assertTest('Upload::resolve blocks path traversal', Upload::resolve('../../config/app.env') === null);
assertTest('Upload::resolve blocks absolute paths', Upload::resolve('/etc/passwd') === null);
assertTest('Upload::resolve returns null for missing files', Upload::resolve('submission/tidak-ada.pdf') === null);

// ---------------------------------------------------------------- Classroom access control
assertTest('ClassroomAccess::teacherTeachesClass denies an unrelated teacher+class',
    ClassroomAccess::teacherTeachesClass(999999, 1, false) === false);
assertTest('ClassroomAccess::teacherOwnsAssignment denies a non-owner',
    ClassroomAccess::teacherOwnsAssignment(999999, 999999, false) === false);
assertTest('ClassroomAccess::teacherOwnsAssignment allows privileged staff',
    ClassroomAccess::teacherOwnsAssignment(999999, 999999, true) === true);
assertTest('ClassroomAccess::canViewGradebook restricts students to their own class',
    ClassroomAccess::canViewGradebook(2, 5, 3, false) === false);
assertTest('ClassroomAccess::canViewGradebook allows a student their own class',
    ClassroomAccess::canViewGradebook(3, 5, 3, false) === true);

// ---------------------------------------------------------------- Learning read API (used by classroom UI)
foreach (['getMaterialsByClass', 'getAssignmentsByClass', 'getQuizzesByClass', 'getQuiz', 'submitAssignment', 'getSubmissionsForAssignment', 'gradebook'] as $m) {
    assertTest("Learning::{$m}() exists (classroom integration intact)", method_exists(Learning::class, $m));
}

// ---------------------------------------------------------------- Route & CSRF integrity
$src = (string)file_get_contents(VC_PUBLIC . '/index.php');
preg_match_all("/\x27\/(?:[^\x27]*)\x27\s*=>\s*\x27([a-z0-9_]+)\x27/i", $src, $m);
$handlers = array_values(array_unique($m[1]));
preg_match_all("/case \x27([a-z0-9_]+)\x27:/i", $src, $c);
$cases = $c[1];
assertTest('No duplicate switch cases in the front controller', array_count_values($cases) === array_count_values(array_unique($cases)));
assertTest('Every routed handler has a matching case', array_diff($handlers, $cases) === []);

// Every state-changing endpoint must call Csrf::check.
preg_match_all("/case \x27(api_[a-z0-9_]+)\x27:(.*?)(?=case \x27|\\}\\s*$)/s", $src, $blocks, PREG_SET_ORDER);
$stateChanging = [];
$safeReads = ['api_notifications', 'api_task_submissions', 'api_task_file', 'api_gradebook', 'api_campus_get',
              'api_schedule_today', 'api_schedule_versions', 'api_schedule_template', 'api_schedule_whereis', 'api_csrf', 'api_client_error', 'api_stats',
              // Agent 3: read-only AI orchestrator status endpoints (no state change -> no CSRF).
              'api_ai_job_get', 'api_ai_jobs',
              // Agen 2/3: endpoint baca-saja 2FA & Web Push VAPID (tanpa perubahan state -> tanpa CSRF).
              // api_totp_setup tetap wajib CSRF karena menulis $_SESSION['pending_totp_secret'].
              'api_totp_status', 'api_push_key',
              // Agent 1 (disetujui Agent 2, 13 Okt 2026): api_missions & api_game_chat_get
              // read-only session-scoped; api_game_chat_relay server-to-server dengan
              // HMAC APP_KEY + window timestamp 60 detik (bukan permukaan CSRF browser).
              'api_missions', 'api_game_chat_get', 'api_game_chat_relay',
              // Agent 1 (disetujui Agent 2, 13 Okt 2026 — sesi 2): read-only list
              // di bawah Permissions::require, tanpa write state/session.
              'api_admin_chat_recent', 'api_admin_missions_list',
              // Read-only server listing (GET /api/servers)
              'api_servers',
              // GET /api/avatar/me hanya membaca gaya karakter milik sesi sendiri.
              // Penyimpanan (api_avatar_save) tetap wajib CSRF.
              'api_avatar_get', 'api_media_config', 'api_gacha_get', 'api_daily_get', 'api_costumes_get', 'api_inventory_get',
              // GET /api/broadcasts hanya membaca pengumuman aktif.
              'api_broadcasts',
              // GET /api/party hanya membaca status mabar milik sesi.
              'api_party', 'api_users_search', 'api_server_session'];
foreach ($blocks as $b) {
    $name = $b[1];
    if (str_starts_with($name, 'api_') && !in_array($name, $safeReads, true)) {
        $stateChanging[] = [$name, str_contains($b[2], 'Csrf::check')];
    }
}
$missingCsrf = array_values(array_map(fn($x) => $x[0], array_filter($stateChanging, fn($x) => !$x[1])));
assertTest('Every state-changing API endpoint enforces CSRF', $missingCsrf === [], 'missing: ' . implode(', ', $missingCsrf));

// Authorization must be enforced on task/grade endpoints.
$authzOk = str_contains($src, 'ClassroomAccess::teacherTeachesClass')
    && str_contains($src, 'ClassroomAccess::teacherOwnsAssignment')
    && str_contains($src, "Permissions::require('task.grade')");
assertTest('Task endpoints enforce ownership + role scope server-side', $authzOk);

// ---------------------------------------------------------------- Audit chain
assertTest('Audit log hash-chain integrity preserved (0 broken links)', AuditLog::verify() === 0);

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

exit($fail === 0 ? 0 : 1);
