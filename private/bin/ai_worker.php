<?php
// AI Orchestrator worker. Persistent queue consumer (single instance via flock).
//   php private/bin/ai_worker.php                 # run forever
//   php private/bin/ai_worker.php --once           # drain current queue then exit
//   php private/bin/ai_worker.php --once --limit=5 # process up to 5 jobs then exit
//   php private/bin/ai_worker.php --sleep=3        # idle poll interval (seconds)
// Run under systemd/tmux/nohup. Web requests only enqueue; this worker executes.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require __DIR__ . '/../src/bootstrap.php';

use VC\Ai\Orchestrator;
use VC\Ai\Providers;

$opts = getopt('', ['once', 'limit::', 'sleep::']);
$once  = array_key_exists('once', $opts);
$limit = max(1, (int)($opts['limit'] ?? 2));
$sleep = max(1, (int)($opts['sleep'] ?? 3));

// ---- Single-instance lock: never run two polling workers at once ----
$lockPath = VC_PRIVATE . '/data/ai_worker.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Worker lain sedang berjalan (lock: {$lockPath}). Keluar.\n");
    exit(1);
}

echo "AI Orchestrator worker aktif (pid " . getmypid() . ").\n";
echo "Status role:\n";
foreach (Providers::allStatus() as $role => $s) {
    echo '  - ' . $role . ': ' . $s['state'] . ' (' . $s['provider'] . '/' . $s['model'] . ")\n";
}

while (true) {
    try {
        $summary = Orchestrator::runNext($limit);
    } catch (Throwable $e) {
        fwrite(STDERR, 'worker error: ' . substr($e->getMessage(), 0, 200) . "\n");
        $summary = ['processed' => 0];
    }
    if ($summary['processed'] > 0) {
        foreach ($summary['results'] as $r) {
            echo date('c') . ' job ' . ($r['job_id'] ?? '?') . ' -> ' . ($r['status'] ?? '?') . "\n";
        }
        continue; // keep draining immediately
    }
    if ($once) {
        break;
    }
    sleep($sleep);
}

flock($lock, LOCK_UN);
fclose($lock);
echo "Worker berhenti.\n";
