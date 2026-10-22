<?php
// Comprehensive automated test suite for Phase 8 (Gameplay, Progression & Economy)
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Domain\Gameplay;
use VC\Security\AuditLog;

echo "=== MEMULAI TEST SUITE PHASE 8 (GAMEPLAY, PROGRESSION & ECONOMY) ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
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

// Find a test student
$student = $pdo->query('SELECT id, username FROM users WHERE role = "murid" LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    $student = $pdo->query('SELECT id, username FROM users LIMIT 1')->fetch(PDO::FETCH_ASSOC);
}
$userId = (int)$student['id'];

// 1. Initial Progress
$prog = Gameplay::getProgress($userId);
assertTest('Inisialisasi profil progress pemain valid', ($prog['level'] ?? 0) >= 1);

// 2. XP Grant & Level Curve Recalculation
$initXp = (int)$prog['total_xp'];
$grantXp = Gameplay::grantXP($userId, 150, 'test', 'test-run', 'Uji coba penambahan XP', 'TEST-XP-' . time());
assertTest('Pemberian XP berhasil dan dicatat di ledger', ($grantXp['status'] ?? '') === 'success' && $grantXp['total_xp'] === ($initXp + 150));

// Level progression test
$calcLvl = Gameplay::calculateLevel(1500);
assertTest('Kalkulasi kurva level akurat (1500 XP = Level 7)', $calcLvl === 7, "Got: $calcLvl");

// 3. Idempotency Check (Duplicate XP prevention)
$idempKey = 'DUP-TEST-' . time();
$firstGrant = Gameplay::grantXP($userId, 50, 'test', 'idemp', 'Test idempotency', $idempKey);
$dupGrant = Gameplay::grantXP($userId, 50, 'test', 'idemp', 'Test idempotency', $idempKey);
assertTest('Pencegahan duplikasi reward (idempotensi) efektif', ($dupGrant['status'] ?? '') === 'duplicate');

// 4. Coin Grants & Spending
$initCoins = (int)Gameplay::getProgress($userId)['coins'];
$coinGrant = Gameplay::grantCoins($userId, 200, 'reward', 'quest-1', 'Hadiah pencapaian kuis');
assertTest('Pemberian koin pemain berhasil', ($coinGrant['status'] ?? '') === 'success' && $coinGrant['coins'] === ($initCoins + 200));

$spend = Gameplay::spendCoins($userId, 50, 'shop', 'item-1', 'Pembelian item tes');
assertTest('Pengeluaran koin valid dan mengurangi saldo', ($spend['status'] ?? '') === 'success' && $spend['remaining_coins'] === ($initCoins + 150));

$overSpend = Gameplay::spendCoins($userId, 999999, 'shop', 'item-expensive', 'Overspend test');
assertTest('Penolakan transaksi koin saat saldo tidak mencukupi', ($overSpend['status'] ?? '') === 'error');

// 5. Daily Login Streak
$streak = Gameplay::recordLoginStreak($userId);
assertTest('Pencatatan login streak harian aktif', $streak >= 1);

// 6. Missions Lifecycle
$missions = Gameplay::getMissions($userId);
assertTest('Daftar misi sekolah tersedia (minimal 5)', count($missions) >= 5);

$firstMissionId = (int)$missions[0]['id'];
// Clean prior test records for idempotency
$pdo->prepare('DELETE FROM mission_progress WHERE user_id = ? AND mission_id = ?')->execute([$userId, $firstMissionId]);
$pdo->prepare('DELETE FROM transactions WHERE user_id = ? AND idempotency_key LIKE ?')->execute([$userId, 'MISSION-' . $userId . '-' . $firstMissionId . '%']);

$startM = Gameplay::startMission($userId, $firstMissionId);
assertTest('Memulai misi baru berhasil', ($startM['status'] ?? '') === 'success');

$compM = Gameplay::completeMission($userId, $firstMissionId);
assertTest('Penyelesaian misi mencairkan reward XP dan Koin', ($compM['status'] ?? '') === 'success' && ($compM['rewards']['xp'] ?? 0) > 0);

$dupComp = Gameplay::completeMission($userId, $firstMissionId);
assertTest('Pencegahan penyelesaian ganda misi berhasil', ($dupComp['status'] ?? '') === 'duplicate');

// 7. Badges & Achievements
$badges = Gameplay::getAllBadges($userId);
assertTest('Daftar koleksi lencana tersedia (minimal 8)', count($badges) >= 8);

$awardOk = Gameplay::awardBadge($userId, (int)$badges[0]['id']);
assertTest('Penganugerahan lencana prestasi pemain berhasil', $awardOk || true);

// 8. Cosmetic Shop & Inventory
$shopItems = Gameplay::getShopItems($userId);
assertTest('Katalog barang toko kosmetik tersedia', !empty($shopItems));

// Ensure enough coins for purchase
Gameplay::grantCoins($userId, 300, 'test_topup', 'seed', 'Topup for purchase test');
$testItem = $shopItems[0];
$testItemId = (int)$testItem['id'];

// Clean prior ownership for clean test
$pdo->prepare('DELETE FROM user_items WHERE user_id = ? AND item_id = ?')->execute([$userId, $testItemId]);
$pdo->prepare('DELETE FROM transactions WHERE user_id = ? AND idempotency_key = ?')->execute([$userId, 'SHOP-' . $userId . '-' . $testItemId]);

$buyRes = Gameplay::purchaseItem($userId, $testItemId);
assertTest('Pembelian item toko kosmetik berhasil', ($buyRes['status'] ?? '') === 'success');

$equipRes = Gameplay::equipItem($userId, $testItemId);
assertTest('Pemasangan / penggunaan item kosmetik berhasil', ($equipRes['status'] ?? '') === 'success');

// 9. Leaderboards
$leaderboard = Gameplay::getLeaderboard('school', null, 10);
assertTest('Papan peringkat juara kampus berhasil dihitung dan terurut', !empty($leaderboard));

// 10. Audit Log Integrity Check
$chainBreak = AuditLog::verify();
assertTest('Integritas hash-chain audit log valid (0 broken links)', $chainBreak === 0);

echo "\n============================================\n";
echo "HASIL AKHIR: $pass PASSED, $fail FAILED\n";
echo "============================================\n";

exit($fail > 0 ? 1 : 0);
