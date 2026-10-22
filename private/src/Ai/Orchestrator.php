<?php
// AiOrchestrator: persistent multi-agent job queue with three distinct roles
// (engineering, security_qa, integration_release). Real provider requests, job IDs,
// statuses (queued/running/succeeded/failed/cancelled), retry, dedup, budget and
// secret-free execution logging. Owned by Agent 3 (Integration & Release).
declare(strict_types=1);

namespace VC\Ai;

use VC\Database;

final class Orchestrator
{
    public const STATUSES = ['queued', 'running', 'succeeded', 'failed', 'cancelled'];

    /** Testing seam: when set, replaces the real network call (offline suites). */
    private static $chatOverride = null;

    public static function setChatOverride(?callable $fn): void
    {
        self::$chatOverride = $fn;
    }

    /** Idempotent schema so the feature works even if migrate.php was not run. */
    public static function ensureSchema(): void
    {
        $pdo = Database::get();
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_jobs (
            id TEXT PRIMARY KEY,
            role TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "queued",
            task TEXT NOT NULL,
            input TEXT,
            output TEXT,
            provider TEXT,
            model TEXT,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            dedupe_key TEXT,
            requested_by INTEGER,
            error TEXT,
            tokens_used INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TEXT,
            finished_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aijobs_status ON ai_jobs(status, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aijobs_role ON ai_jobs(role, created_at)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_aijobs_dedupe ON ai_jobs(dedupe_key) WHERE dedupe_key IS NOT NULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_job_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id TEXT NOT NULL,
            ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level TEXT NOT NULL DEFAULT "info",
            message TEXT NOT NULL,
            meta TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aijobevents_job ON ai_job_events(job_id, id)');
    }

    private static function dailyBudget(): int
    {
        return max(0, (int)\vc_env('AI_DAILY_TOKEN_BUDGET', '200000'));
    }

    private static function tokensToday(): int
    {
        self::ensureSchema();
        $n = (int)Database::get()->query(
            'SELECT COALESCE(SUM(tokens_used),0) FROM ai_jobs WHERE date(created_at) = date("now")'
        )->fetchColumn();
        return $n;
    }

    /**
     * Enqueue a job. Returns ['status' => 'queued'|'duplicate'|'error', ...].
     * Input is validated before anything is written.
     */
    public static function enqueue(string $role, string $task, array $opts = []): array
    {
        self::ensureSchema();
        $role = strtolower(trim($role));
        if (!Providers::isValidRole($role)) {
            return ['status' => 'error', 'message' => 'Role AI tidak valid: ' . $role];
        }
        $task = trim($task);
        if ($task === '') {
            return ['status' => 'error', 'message' => 'Task tidak boleh kosong.'];
        }
        if (strlen($task) > 8000) {
            return ['status' => 'error', 'message' => 'Task terlalu panjang (maks 8000 karakter).'];
        }

        $status = Providers::status($role);
        if (!$status['configured']) {
            return ['status' => 'error', 'message' => 'Role ' . $role . ' BLOCKED/NOT CONFIGURED: ' . $status['reason']];
        }

        if (self::tokensToday() >= self::dailyBudget()) {
            return ['status' => 'error', 'message' => 'Batas token harian tercapai. Coba lagi besok atau naikkan AI_DAILY_TOKEN_BUDGET.'];
        }

        $dedupe = $opts['dedupe_key'] ?? ('auto:' . $role . ':' . sha1($task));
        $pdo = Database::get();

        // Duplicate guard: reuse an existing live job rather than piling up parallel work.
        $dupe = $pdo->prepare('SELECT id, status FROM ai_jobs WHERE dedupe_key = ? AND status IN ("queued","running","succeeded") LIMIT 1');
        $dupe->execute([$dedupe]);
        if ($row = $dupe->fetch()) {
            return ['status' => 'duplicate', 'message' => 'Job serupa sudah ada.', 'job_id' => $row['id'], 'job_status' => $row['status']];
        }

        $id = 'aijob_' . bin2hex(random_bytes(12));
        $ins = $pdo->prepare('INSERT INTO ai_jobs (id, role, status, task, input, max_attempts, dedupe_key, requested_by)
            VALUES (?, ?, "queued", ?, ?, ?, ?, ?)');
        $ins->execute([
            $id,
            $role,
            $task,
            isset($opts['input']) ? substr((string)$opts['input'], 0, 8000) : null,
            max(1, min(5, (int)($opts['max_attempts'] ?? 3))),
            $dedupe,
            isset($opts['requested_by']) ? (int)$opts['requested_by'] : null,
        ]);

        self::log($id, 'info', 'Job diantrekan.', ['role' => $role, 'provider' => $status['provider'], 'model' => $status['model']]);

        return ['status' => 'queued', 'job_id' => $id, 'role' => $role, 'provider' => $status['provider'], 'model' => $status['model']];
    }

    /** Claim and run the next queued job(s). Returns a per-job summary. */
    public static function runNext(int $limit = 1): array
    {
        self::ensureSchema();
        $results = [];
        for ($i = 0; $i < max(1, $limit); $i++) {
            $id = Database::get()->query(
                'SELECT id FROM ai_jobs WHERE status = "queued" ORDER BY created_at ASC, rowid ASC LIMIT 1'
            )->fetchColumn();
            if ($id === false || $id === null) {
                break;
            }
            $results[] = self::run((string)$id);
        }
        return ['processed' => count($results), 'results' => $results];
    }

    /** Run one job by id (atomic claim; safe against double-processing). */
    public static function run(string $id): array
    {
        self::ensureSchema();
        $pdo = Database::get();
        $job = self::get($id);
        if ($job === null) {
            return ['status' => 'error', 'message' => 'Job tidak ditemukan.'];
        }
        if ($job['status'] === 'cancelled') {
            return ['status' => 'cancelled', 'job_id' => $id];
        }
        if (!in_array($job['status'], ['queued'], true)) {
            return ['status' => 'error', 'message' => 'Job tidak dalam status queued (sekarang: ' . $job['status'] . ').'];
        }

        // Atomic claim — only one worker can win this row.
        $claim = $pdo->prepare('UPDATE ai_jobs SET status = "running", started_at = datetime("now"), attempts = attempts + 1
            WHERE id = ? AND status = "queued"');
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) {
            return ['status' => 'error', 'message' => 'Job sudah diklaim worker lain.'];
        }

        $maxAttempts = (int)$job['max_attempts'];
        $attempt = 1;
        $role = (string)$job['role'];
        $status = Providers::status($role);
        if (!$status['configured']) {
            self::finish($id, 'failed', null, null, null, 0, 'Role BLOCKED/NOT CONFIGURED: ' . $status['reason']);
            return ['status' => 'failed', 'job_id' => $id, 'reason' => 'not_configured'];
        }

        $prompt = self::buildPrompt($job);
        self::log($id, 'info', 'Mulai memanggil provider.', ['attempt' => $attempt, 'provider' => $status['provider']]);

        while (true) {
            $res = self::callProvider($role, $prompt);
            if (!empty($res['ok'])) {
                $out = self::validateOutput((string)($res['text'] ?? ''));
                if ($out === null) {
                    self::finish($id, 'failed', null, null, null, (int)($res['tokens'] ?? 0), 'Output kosong/tidak valid.');
                    return ['status' => 'failed', 'job_id' => $id, 'reason' => 'invalid_output'];
                }
                self::finish($id, 'succeeded', $out, $res['provider'] ?? null, $res['model'] ?? null, (int)($res['tokens'] ?? 0), null);
                self::log($id, 'info', 'Job selesai.', ['tokens' => (int)($res['tokens'] ?? 0)]);
                return ['status' => 'succeeded', 'job_id' => $id];
            }

            // Failure
            $retryable = (bool)($res['retryable'] ?? false);
            if ($retryable && $attempt < $maxAttempts) {
                $attempt++;
                $pdo->prepare('UPDATE ai_jobs SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
                self::log($id, 'warn', 'Provider error, akan dicoba ulang.', ['attempt' => $attempt, 'error' => (string)($res['error'] ?? ''), 'status' => (int)($res['status'] ?? 0)]);
                // Bounded backoff (1s, 2s, 4s … capped 8s). Only reached in the CLI worker.
                sleep(min(8, 1 << ($attempt - 1)));
                continue;
            }

            self::finish($id, 'failed', null, $res['provider'] ?? null, $res['model'] ?? null, 0, (string)($res['error'] ?? 'provider error'));
            self::log($id, 'error', 'Job gagal.', ['error' => (string)($res['error'] ?? ''), 'http' => (int)($res['status'] ?? 0)]);
            return ['status' => 'failed', 'job_id' => $id];
        }
    }

    public static function cancel(string $id, ?int $actor = null): array
    {
        self::ensureSchema();
        $job = self::get($id);
        if ($job === null) {
            return ['status' => 'error', 'message' => 'Job tidak ditemukan.'];
        }
        if (in_array($job['status'], ['succeeded', 'failed'], true)) {
            return ['status' => 'error', 'message' => 'Job sudah selesai dan tidak bisa dibatalkan.'];
        }
        Database::get()->prepare('UPDATE ai_jobs SET status = "cancelled", finished_at = datetime("now") WHERE id = ? AND status IN ("queued","running")')
            ->execute([$id]);
        self::log($id, 'warn', 'Job dibatalkan.', ['by' => $actor]);
        return ['status' => 'cancelled', 'job_id' => $id];
    }

    // ---------------------------------------------------------------- helpers

    private static function callProvider(string $role, string $prompt): array
    {
        if (self::$chatOverride !== null) {
            return (self::$chatOverride)($role, $prompt);
        }
        $maxTok = (int)\vc_env('AI_MAX_TOKENS', '700');
        return Providers::chat($role, $prompt, $maxTok);
    }

    private static function buildPrompt(array $job): string
    {
        $p = 'TASK yang sudah divalidasi:' . "\n" . (string)$job['task'];
        if (!empty($job['input'])) {
            $p .= "\n\nKONTEKS / OUTPUT TAHAP SEBELUMNYA:\n" . (string)$job['input'];
        }
        return $p;
    }

    /** Reject empty/oversized output and scrub anything that looks like a secret. */
    private static function validateOutput(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > 40000) {
            $text = substr($text, 0, 40000);
        }
        return self::scrubSecrets($text);
    }

    private static function scrubSecrets(string $text): string
    {
        // Redact common API-key shapes so provider secrets can never land in the log/DB.
        return (string)preg_replace(
            ['/\b(sk-[A-Za-z0-9\-_]{8,})/', '/\b(gsk_[A-Za-z0-9]{8,})/', '/\b(nvapi-[A-Za-z0-9\-_]{8,})/', '/\b(sk-or-v1-[A-Za-z0-9]{8,})/', '/\b(key_[A-Za-z0-9]{8,})/'],
            '***REDACTED***',
            $text
        );
    }

    private static function finish(string $id, string $status, ?string $output, ?string $provider, ?string $model, int $tokens, ?string $error): void
    {
        Database::get()->prepare('UPDATE ai_jobs SET status = ?, output = ?, provider = ?, model = ?, tokens_used = ?, error = ?, finished_at = datetime("now") WHERE id = ?')
            ->execute([$status, $output ? self::scrubSecrets($output) : null, $provider, $model, $tokens, $error !== null ? self::scrubSecrets($error) : null, $id]);
    }

    private static function log(string $jobId, string $level, string $message, array $meta = []): void
    {
        Database::get()->prepare('INSERT INTO ai_job_events (job_id, level, message, meta) VALUES (?, ?, ?, ?)')
            ->execute([$jobId, $level, self::scrubSecrets($message), $meta ? json_encode(self::scrubArray($meta), JSON_UNESCAPED_UNICODE) : null]);
    }

    private static function scrubArray(array $a): array
    {
        array_walk_recursive($a, static function (&$v) {
            if (is_string($v)) {
                $v = (string)preg_replace(['/\b(sk-[A-Za-z0-9\-_]{8,})/', '/\b(nvapi-[A-Za-z0-9\-_]{8,})/', '/\b(sk-or-v1-[A-Za-z0-9]{8,})/'], '***REDACTED***', $v);
            }
        });
        return $a;
    }

    public static function get(string $id): ?array
    {
        self::ensureSchema();
        $s = Database::get()->prepare('SELECT * FROM ai_jobs WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        return $row ?: null;
    }

    public static function events(string $id, int $limit = 100): array
    {
        self::ensureSchema();
        $s = Database::get()->prepare('SELECT * FROM ai_job_events WHERE job_id = ? ORDER BY id ASC LIMIT ?');
        $s->execute([$id, $limit]);
        return $s->fetchAll();
    }

    public static function list(int $limit = 50, ?string $status = null, ?string $role = null): array
    {
        self::ensureSchema();
        $sql = 'SELECT id, role, status, task, provider, model, attempts, max_attempts, tokens_used, error, created_at, started_at, finished_at
                FROM ai_jobs WHERE 1=1';
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($role !== null && Providers::isValidRole($role)) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        $sql .= ' ORDER BY created_at DESC, rowid DESC LIMIT ?';
        $params[] = max(1, min(200, $limit));
        $s = Database::get()->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }

    public static function stats(): array
    {
        self::ensureSchema();
        $pdo = Database::get();
        $byStatus = ['queued' => 0, 'running' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($pdo->query('SELECT status, COUNT(*) c FROM ai_jobs GROUP BY status') as $r) {
            $byStatus[$r['status']] = (int)$r['c'];
        }
        $byRole = [];
        foreach ($pdo->query('SELECT role, COUNT(*) c FROM ai_jobs GROUP BY role') as $r) {
            $byRole[$r['role']] = (int)$r['c'];
        }
        return [
            'by_status'    => $byStatus,
            'by_role'      => $byRole,
            'tokens_today' => self::tokensToday(),
            'daily_budget' => self::dailyBudget(),
            'roles'        => Providers::allStatus(),
        ];
    }
}
