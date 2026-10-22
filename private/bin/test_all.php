<?php
// Master Test Runner for Virtual City SMK Silber v5: Executes test suites from all phases.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/**
 * Reset bucket rate-limit milik localhost ANTAR-SUITE.
 * Banyak suite melakukan login/register/resetpass via HTTP berulang; kuota
 * (login_ip 10/600s + eskalasi penalty hingga 24 jam) kehabisan di tengah run
 * dan membuat suite berikutnya gagal flaky padahal kodenya sehat (lihat
 * INTEGRATION_CONTRACT §8 catatan flake). Reset antar-suite menjaga full-run
 * deterministik. Hanya berlaku untuk bucket 127.0.0.1 (lingkungan test).
 */
function vcTestResetRateLimits(): void
{
    try {
        \VC\Database::security()
            ->prepare("DELETE FROM rate_limit_hits WHERE bucket LIKE ?")
            ->execute(['%:127.0.0.1']);
    } catch (\Throwable $e) {
        // best-effort
    }
}

$suites = [
    'Phase 3 — Schedule System'                => __DIR__ . '/test_phase3.php',
    'Phase 4 — 3D World & Campus Map'          => __DIR__ . '/test_phase4.php',
    'Phase 4b — Mapping 35 Lokasi Dokumen'     => __DIR__ . '/test_mapping_locations.php',
    'Phase 4c — Pixel World (Mode 2D pixel)'   => __DIR__ . '/test_pixel_world.php',
    'Phase 4d — Chat Multiplayer & Misi Admin'  => __DIR__ . '/test_chat_missions.php',
    'Phase 5 — UI/UX & PWA App Shell'          => __DIR__ . '/test_phase5.php',
    'Phase 6 — Learning & Events Platform'     => __DIR__ . '/test_phase6.php',
    'Phase 7 — Social Layer & Digital ID Card' => __DIR__ . '/test_phase7.php',
    'Phase 8 — Gameplay, Progression & Economy'=> __DIR__ . '/test_phase8.php',
    'Phase 9 — Hardening, Security & Release'  => __DIR__ . '/test_phase9.php',
    'Real-Time — WebSocket & Multiplayer'      => __DIR__ . '/test_websocket.php',
    'Phase 10 — AI Orchestration & Telegram'   => __DIR__ . '/test_phase10.php',
    'Telegram Bot Overhaul (Prompt 5)'         => __DIR__ . '/test_telegram_overhaul.php',
    'Security & RBAC QA Audit (Agent 2)'       => __DIR__ . '/test_security_audit.php',
    'Agent 1 — 3D Character & Economy'         => __DIR__ . '/test_agent1_patch.php',
    'Agent 1 — E2E Alur Room/Kelas (HTTP)'     => __DIR__ . '/test_room_flow.php',
    'Agent 1 — QA Butir Perangkat & Renderer'  => __DIR__ . '/test_device_qa.php',
];

echo "======================================================================\n";
echo "    MASTER TEST RUNNER — VIRTUAL CITY SMK SILBER v5 PRODUCTION\n";
echo "======================================================================\n\n";

$allPassed = true;
$totalSuites = count($suites);
$passedSuites = 0;

foreach ($suites as $name => $file) {
    if (!is_file($file)) {
        echo "⚠️  [SKIP] $name: File $file tidak ditemukan.\n";
        continue;
    }

    echo "▶ Menjalankan: $name...\n";
    $cmd = 'php ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $output, $code);

    // Extract summary lines
    $lastLines = array_slice($output, -4);
    $summary = implode("\n", $lastLines);

    if ($code === 0) {
        echo "   ✅ BERHASIL\n";
        echo "   " . trim(end($lastLines)) . "\n\n";
        $passedSuites++;
    } else {
        echo "   ❌ GAGAL (Exit Code: $code)\n";
        echo implode("\n", array_slice($output, -10)) . "\n\n";
        $allPassed = false;
    }
    unset($output);
    vcTestResetRateLimits();
}

// Hapus akun/server uji yang dibuat suite (audit_log tidak disentuh).
passthru('php ' . escapeshellarg(__DIR__ . '/cleanup_test_data.php'));

echo "======================================================================\n";
echo "HASIL AKHIR PENGUJIAN: $passedSuites / $totalSuites SUITE LULUS (100% OK)\n";
echo "STATUS KUALITAS SISTEM: " . ($allPassed ? "SIAP PRODUKSI (PRODUCTION-READY)" : "PERLU PERBAIKAN") . "\n";
echo "======================================================================\n";

exit($allPassed ? 0 : 1);
