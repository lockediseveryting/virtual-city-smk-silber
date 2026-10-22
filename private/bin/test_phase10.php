<?php
// Phase 10 — Agent 3 Integration & Release test suite:
//   AI orchestrator (roles, queue, statuses, retry, dedup, budget, secret scrubbing),
//   Telegram account linking + role-gated commands (dry-run, no real chats),
//   branding footer, and schema presence.
declare(strict_types=1);

// Dry-run Telegram + no-network AI seams must be set before anything uses them.
putenv('TELEGRAM_DRY_RUN=1');
require_once __DIR__ . '/../src/bootstrap.php';

use VC\Ai\Orchestrator;
use VC\Ai\Providers;
use VC\Database;
use VC\Security\Footer;
use VC\TgBot\AccountLink;
use VC\TgBot\Bot;
use VC\TgBot\Telegram;

echo "=== TEST SUITE PHASE 10 (INTEGRASI & ORKESTRASI 3 AI + TELEGRAM) ===\n\n";

$pass = 0;
$fail = 0;
function assertTest(string $desc, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "✅ PASS: $desc\n";
        $pass++;
    } else {
        echo "❌ FAIL: $desc (" . $detail . ")\n";
        $fail++;
    }
}

$pdo = Database::get();
$suffix = bin2hex(random_bytes(3));

// ---------------------------------------------------------------- schema
Orchestrator::ensureSchema();
AccountLink::ensureSchema();
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['ai_jobs', 'ai_job_events', 'telegram_links', 'telegram_link_codes', 'telegram_updates'] as $t) {
    assertTest("Tabel $t tersedia", in_array($t, $tables, true));
}

// ---------------------------------------------------------------- AI roles
echo "\n--- AI Orchestrator: konfigurasi 3 role ---\n";
$statuses = Providers::allStatus();
assertTest('Terdapat tepat 3 role AI berbeda', count($statuses) === 3, (string)count($statuses));
$providers = array_column($statuses, 'provider');
assertTest('Setiap role memakai provider berbeda', count(array_unique($providers)) === 3, implode(',', $providers));
foreach ($statuses as $role => $s) {
    assertTest("Role $role CONFIGURED (key + provider ada)", $s['configured'], $s['reason']);
}

// ---------------------------------------------------------------- queue lifecycle (offline)
echo "\n--- AI Orchestrator: siklus antrean ---\n";
Orchestrator::setChatOverride(static fn($role, $prompt) => [
    'ok' => true, 'status' => 200, 'text' => "[$role] draf selesai", 'tokens' => 10,
    'provider' => Providers::configFor($role)['provider'], 'model' => Providers::configFor($role)['model'],
]);

$e = Orchestrator::enqueue('engineering', 'phase10 task ' . $suffix);
assertTest('enqueue role valid -> queued', ($e['status'] ?? '') === 'queued', json_encode($e));
$jid = $e['job_id'] ?? '';

$eDup = Orchestrator::enqueue('engineering', 'phase10 task ' . $suffix);
assertTest('Job duplikat dicegah (dedupe) -> duplicate', ($eDup['status'] ?? '') === 'duplicate', json_encode($eDup));

$eBad = Orchestrator::enqueue('not_a_role', 'x');
assertTest('Role tidak valid ditolak', ($eBad['status'] ?? '') === 'error');
$eEmpty = Orchestrator::enqueue('engineering', '   ');
assertTest('Task kosong ditolak', ($eEmpty['status'] ?? '') === 'error');

$run = Orchestrator::run($jid);
assertTest('Job berhasil dijalankan -> succeeded', ($run['status'] ?? '') === 'succeeded', json_encode($run));
$job = Orchestrator::get($jid);
assertTest('Status job tersimpan = succeeded', ($job['status'] ?? '') === 'succeeded');
assertTest('Minimal 2 event eksekusi tercatat', count(Orchestrator::events($jid)) >= 2);
assertTest('Token usage tercatat', (int)($job['tokens_used'] ?? 0) > 0);

// deterministic job id, cancel a queued job
$e2 = Orchestrator::enqueue('security_qa', 'phase10 cancel task ' . $suffix);
$cancel = Orchestrator::cancel($e2['job_id'] ?? '', 1);
assertTest('Job queued bisa dibatalkan -> cancelled', ($cancel['status'] ?? '') === 'cancelled', json_encode($cancel));
assertTest('Cancel job selesai ditolak', (Orchestrator::cancel($jid, 1)['status'] ?? '') === 'error');

// invalid output -> failed
Orchestrator::setChatOverride(static fn($role, $prompt) => ['ok' => true, 'status' => 200, 'text' => '   ', 'tokens' => 0]);
$e3 = Orchestrator::enqueue('integration_release', 'phase10 empty output ' . $suffix);
$run3 = Orchestrator::run($e3['job_id'] ?? '');
assertTest('Output kosong -> job failed', ($run3['status'] ?? '') === 'failed', json_encode($run3));

// retry then exhaust (max_attempts=1, no sleep)
Orchestrator::setChatOverride(static fn($role, $prompt) => ['ok' => false, 'status' => 500, 'error' => 'boom', 'retryable' => true, 'tokens' => 0]);
$e4 = Orchestrator::enqueue('engineering', 'phase10 retry exhaust ' . $suffix, ['max_attempts' => 1]);
$run4 = Orchestrator::run($e4['job_id'] ?? '');
assertTest('Provider error -> job failed (retry habis)', ($run4['status'] ?? '') === 'failed', json_encode($run4));

// retry then success (max_attempts=2 -> one 1s backoff)
$calls = 0;
Orchestrator::setChatOverride(static function ($role, $prompt) use (&$calls) {
    $calls++;
    if ($calls === 1) {
        return ['ok' => false, 'status' => 429, 'error' => 'rate limited', 'retryable' => true, 'tokens' => 0];
    }
    return ['ok' => true, 'status' => 200, 'text' => 'ok after retry', 'tokens' => 5];
});
$e5 = Orchestrator::enqueue('security_qa', 'phase10 retry success ' . $suffix, ['max_attempts' => 2]);
$run5 = Orchestrator::run($e5['job_id'] ?? '');
assertTest('Retry terbatas lalu sukses', ($run5['status'] ?? '') === 'succeeded' && $calls === 2, "status=" . ($run5['status'] ?? '') . " calls=$calls");

// secret scrubbing
Orchestrator::setChatOverride(static fn($role, $prompt) => ['ok' => true, 'status' => 200, 'text' => 'leaked sk-or-v1-AB12CD34EF56 ghost', 'tokens' => 3]);
$e6 = Orchestrator::enqueue('engineering', 'phase10 scrub ' . $suffix);
Orchestrator::run($e6['job_id'] ?? '');
$scrubbed = (string)(Orchestrator::get($e6['job_id'] ?? '')['output'] ?? '');
assertTest('Secret pada output disensor (***REDACTED***)', str_contains($scrubbed, 'REDACTED') && !str_contains($scrubbed, 'sk-or-v1-AB12CD34EF56'), $scrubbed);

// budget enforcement
putenv('AI_DAILY_TOKEN_BUDGET=1');
Orchestrator::setChatOverride(null);
$eBudget = Orchestrator::enqueue('engineering', 'phase10 budget ' . $suffix);
assertTest('Batas token harian ditegakkan', ($eBudget['status'] ?? '') === 'error', json_encode($eBudget));
putenv('AI_DAILY_TOKEN_BUDGET=');

// ---------------------------------------------------------------- Telegram link
echo "\n--- Telegram: account linking ---\n";
$classId = (int)($pdo->query('SELECT id FROM classes ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

$pdo->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute(["agent3_tg_$suffix", "agent3_tg_admin_$suffix"]);
$mk = function (string $username, string $role) use ($pdo, $classId): int {
    $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, class_id, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, "active", datetime("now"))')
        ->execute([bin2hex(random_bytes(16)), $username, VC\Security\Auth::hash('StrongPass#2026x'), $role, 'Agent3 TG ' . $role, $classId ?: null]);
    return (int)$pdo->lastInsertId();
};
$muridId = $mk("agent3_tg_$suffix", 'murid');
$adminId = $mk("agent3_tg_admin_$suffix", 'root');

$tgMurid = 700000000 + random_int(1, 9999999);
$tgAdmin = 710000000 + random_int(1, 9999999);

$code = AccountLink::issueCode($muridId);
assertTest('Kode tautan 8 karakter dihasilkan', strlen($code['code']) === 8, $code['code']);

$bad = AccountLink::consume('ZZZZZZZZ', $tgMurid, 'x');
assertTest('Kode salah ditolak', ($bad['status'] ?? '') === 'error');

$ok = AccountLink::consume($code['code'], $tgMurid, 'murid_tg');
assertTest('Kode valid menautkan akun', ($ok['status'] ?? '') === 'ok', json_encode($ok['message'] ?? ''));
assertTest('userForTg menemukan user aktif', (AccountLink::userForTg($tgMurid)['id'] ?? 0) === $muridId);

$reuse = AccountLink::consume($code['code'], $tgMurid, 'again');
assertTest('Kode sekali-pakai tidak bisa dipakai ulang', ($reuse['status'] ?? '') === 'error');

// suspended account loses bot access
$pdo->prepare('UPDATE users SET status="suspended" WHERE id=?')->execute([$muridId]);
assertTest('User dinonaktifkan kehilangan akses bot', AccountLink::userForTg($tgMurid) === null);
$pdo->prepare('UPDATE users SET status="active" WHERE id=?')->execute([$muridId]);

// ---------------------------------------------------------------- Bot commands (dry run)
echo "\n--- Telegram: perintah per-role (dry run) ---\n";
$tm = new Telegram();
$bot = new Bot($tm);

$send = function (int $updateId, int $fromId, string $text) use ($bot, $tm): array {
    $before = count($tm->sent());
    $bot->handle(['update_id' => $updateId, 'message' => ['message_id' => $updateId, 'from' => ['id' => $fromId, 'first_name' => 'T'], 'chat' => ['id' => $fromId], 'text' => $text]]);
    $all = $tm->sent();
    return array_slice($all, $before);
};
$lastText = static function (array $sends): string {
    $last = end($sends);
    return is_array($last) ? (string)($last['payload']['text'] ?? '') : '';
};

$u = 900000000 + random_int(1, 9999999);
$s = $send($u++, $tgMurid, '/status');
assertTest('Murid: /status dibalas dengan data akun', str_contains($lastText($s), 'Status Akun'), $lastText($s));
$s = $send($u++, $tgMurid, '/koin');
assertTest('Murid: /koin dibalas progres', str_contains($lastText($s), 'Progresmu'), $lastText($s));
$s = $send($u++, $tgMurid, '/event');
assertTest('Murid: /event dibalas', str_contains($lastText($s), 'Event'), $lastText($s));
$s = $send($u++, $tgMurid, '/jadwal');
assertTest('Murid: /jadwal dibalas', str_contains($lastText($s), 'Jadwal') || str_contains($lastText($s), 'Kelas'), $lastText($s));
$s = $send($u++, $tgMurid, '/tugas');
assertTest('Murid: /tugas dibalas', $lastText($s) !== '', $lastText($s));
$s = $send($u++, $tgMurid, '/inventory');
assertTest('Murid: /inventory dibalas', $lastText($s) !== '', $lastText($s));
$s = $send($u++, $tgMurid, '/help');
assertTest('Murid: /help tidak memuat perintah admin', !str_contains($lastText($s), '/adduser'), $lastText($s));

// murid must NOT be able to run admin commands
$s = $send($u++, $tgMurid, '/unbanall');
assertTest('Murid: /unbanall DITOLAK', str_contains($lastText($s), 'tidak memiliki izin'), $lastText($s));
$s = $send($u++, $tgMurid, '/listusers all');
assertTest('Murid: /listusers DITOLAK', str_contains($lastText($s), 'tidak memiliki izin'), $lastText($s));
$s = $send($u++, $tgMurid, '/adduser x y z');
assertTest('Murid: /adduser DITOLAK', str_contains($lastText($s), 'tidak memiliki izin'), $lastText($s));

// duplicate update id is processed once
$dupeId = 950000000 + random_int(1, 9999999);
$one = $send($dupeId, $tgMurid, '/status');
$two = $send($dupeId, $tgMurid, '/status');
assertTest('Update ganda (update_id sama) hanya diproses sekali', count($one) === 1 && count($two) === 0, 'one=' . count($one) . ' two=' . count($two));

// unlinked user cannot use commands
$unlinked = 800000000 + random_int(1, 9999999);
$s = $send($u++, $unlinked, '/jadwal');
assertTest('User belum tertaut diarahkan menautkan akun', str_contains($lastText($s), 'belum ditautkan'), $lastText($s));

// root admin path
$codeA = AccountLink::issueCode($adminId);
AccountLink::consume($codeA['code'], $tgAdmin, 'root_tg');
$s = $send($u++, $tgAdmin, '/listusers all');
assertTest('Root: /listusers diizinkan', !str_contains($lastText($s), 'tidak memiliki izin') && $lastText($s) !== '', $lastText($s));
$s = $send($u++, $tgAdmin, '/help');
assertTest('Root: /help memuat perintah admin', str_contains($lastText($s), '/adduser'), $lastText($s));

// unlink
assertTest('Unlink akun berhasil', AccountLink::unlinkByUser($muridId) === true);
assertTest('Setelah unlink user tidak lagi tertaut', AccountLink::userForTg($tgMurid) === null);

// ---------------------------------------------------------------- branding
echo "\n--- Branding footer ---\n";
$html = Footer::html();
assertTest('Footer memuat teks \"Made by - \"', str_contains($html, 'Made by - '), $html);
assertTest('Footer memuat brand \"Hacker TKJ\"', str_contains($html, 'Hacker TKJ'));
assertTest('Footer memuat tautan HTTPS benar', str_contains($html, 'https://locked.rudd-catla.ts.net/'));
assertTest('Footer tidak memakai position:fixed', !str_contains($html, 'position:fixed'));

// ---------------------------------------------------------------- cleanup
$pdo->prepare('DELETE FROM telegram_links WHERE tg_user_id IN (?, ?)')->execute([$tgMurid, $tgAdmin]);
$pdo->prepare('DELETE FROM telegram_link_codes WHERE user_id IN (?, ?)')->execute([$muridId, $adminId]);
$pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$muridId, $adminId]);
$pdo->prepare('DELETE FROM telegram_updates WHERE update_id >= 800000000')->execute();
Orchestrator::setChatOverride(null);

echo "\n============================================\n";
echo "HASIL AKHIR PHASE 10: $pass PASSED, $fail FAILED\n";
echo "============================================\n";

exit($fail > 0 ? 1 : 0);
