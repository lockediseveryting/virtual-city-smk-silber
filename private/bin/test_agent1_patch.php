<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Domain\Gameplay;
use VC\Security\Footer;

echo "=== TEST AGENT 1: GAME & ECONOMY PATCH ===\n";

$pdo = Database::get();

// 1. Branding Check
$gameFooter = Footer::gameHtml();
assert(str_contains($gameFooter, 'Made by - <a href="https://locked.rudd-catla.ts.net/"'), 'Game footer link check failed');
assert(str_contains($gameFooter, 'Hacker TKJ'), 'Game footer brand text failed');
assert(str_contains($gameFooter, 'target="_blank"'), 'Game footer target=_blank failed');
assert(str_contains($gameFooter, 'rel="noopener noreferrer"'), 'Game footer rel security check failed');
echo "✅ TEST 1: Branding Footer & Link PASSED\n";

// Setup two test users for isolation and transaction tests
$userA = 1; // existing admin / root or test user
$userB = 2; // existing student

// 2. Character Customization & Persistence for User A
$customConfigA = [
    'hair' => 'undercut',
    'hair_color' => '#22d3ee',
    'outfit' => 'tech_jacket',
    'outfit_color' => '#1e3a8a',
    'skin_tone' => '#ffd19a',
    'shoes_color' => '#06b6d4',
    'accessory' => 'cyber_visor'
];
$resA = Gameplay::saveAvatarConfig($userA, $customConfigA);
assert($resA['status'] === 'success', 'Save avatar config User A failed: ' . json_encode($resA));

$avatarA = Gameplay::getActiveAvatar($userA);
assert($avatarA['config']['hair'] === 'undercut', 'User A hair mismatch');
assert($avatarA['config']['outfit'] === 'tech_jacket', 'User A outfit mismatch');
assert($avatarA['config']['accessory'] === 'cyber_visor', 'User A accessory mismatch');
echo "✅ TEST 2: Character Customization & Persistence User A PASSED\n";

// 3. User B Customization (Multi-user Isolation Check)
$customConfigB = [
    'hair' => 'hijab',
    'hair_color' => '#ec4899',
    'outfit' => 'blazer',
    'outfit_color' => '#059669',
    'skin_tone' => '#c68642',
    'shoes_color' => '#ffffff',
    'accessory' => 'glasses'
];
$resB = Gameplay::saveAvatarConfig($userB, $customConfigB);
assert($resB['status'] === 'success', 'Save avatar config User B failed: ' . json_encode($resB));

$avatarB = Gameplay::getActiveAvatar($userB);
assert($avatarB['config']['hair'] === 'hijab', 'User B hair mismatch');
assert($avatarB['config']['outfit'] === 'blazer', 'User B outfit mismatch');
assert($avatarB['config']['skin_tone'] === '#c68642', 'User B skin mismatch');

// Verify User A remained completely unchanged
$avatarARecheck = Gameplay::getActiveAvatar($userA);
assert($avatarARecheck['config']['hair'] === 'undercut', 'User A corrupted by User B');
assert($avatarARecheck['config']['accessory'] === 'cyber_visor', 'User A accessory corrupted');
echo "✅ TEST 3: Multi-User Avatar Isolation PASSED\n";

// 4. Shop & Atomic Transaction (Coin wallet check & Insufficient Funds)
// Give User A 50 coins to test
$pdo->prepare('UPDATE user_progress SET coins = 50 WHERE user_id = ?')->execute([$userA]);
$progA = Gameplay::getProgress($userA);
assert((int)$progA['coins'] === 50, 'Coins setup failed');

// Attempt to spend 99999 coins (insufficient funds)
$spendFail = Gameplay::spendCoins($userA, 99999, 'shop', 'test_item', 'Beli item mahal');
assert($spendFail['status'] === 'error', 'Insufficient funds should fail');
$progAAfter = Gameplay::getProgress($userA);
assert((int)$progAAfter['coins'] === 50, 'Coins should not be deducted on failure');
echo "✅ TEST 4: Insufficient Balance Rejection PASSED\n";

// 5. Successful Atomic Spend with Idempotency Key
$idempKey = 'TEST-PURCHASE-' . time() . '-' . uniqid();
$spendSuccess = Gameplay::spendCoins($userA, 20, 'shop', 'test_item_2', 'Beli item valid', $idempKey);
assert($spendSuccess['status'] === 'success', 'Spend coins should succeed');
assert((int)$spendSuccess['coins'] === 30, 'Coins after spend should be 30');

// 6. Duplicate Transaction Prevention
$spendDuplicate = Gameplay::spendCoins($userA, 20, 'shop', 'test_item_2', 'Beli item duplicate', $idempKey);
assert($spendDuplicate['status'] === 'duplicate', 'Duplicate key should be rejected');
$progAIdemp = Gameplay::getProgress($userA);
assert((int)$progAIdemp['coins'] === 30, 'Coins should not be deducted again on duplicate');
echo "✅ TEST 5 & 6: Atomic Coin Spend & Idempotency Duplicate Prevention PASSED\n";

// 7. Atomic Character Selection & Preset Ownership
$selectRes = Gameplay::selectCharacter($userA, 1);
assert($selectRes['status'] === 'success', 'Select character preset 1 failed: ' . json_encode($selectRes));
$activePreset = Gameplay::getActiveAvatar($userA);
assert($activePreset['slug'] === 'student_silber_classic', 'Active preset slug mismatch');
echo "✅ TEST 7: Atomic Preset Selection & Switching PASSED\n";

// 8. Guest / Unauthenticated Preset & Avatar retrieval
$guestPresets = Gameplay::getCharacterPresets(0);
if (($guestPresets['status'] ?? '') !== 'success' || empty($guestPresets['characters'])) {
    fwrite(STDERR, "❌ TEST 8 FAILED: Guest preset retrieval failed.\n");
    exit(1);
}
$guestAvatar = Gameplay::getActiveAvatar(0);
if (empty($guestAvatar['slug']) || empty($guestAvatar['config'])) {
    fwrite(STDERR, "❌ TEST 8 FAILED: Guest avatar default failed.\n");
    exit(1);
}
echo "✅ TEST 8: Guest / Unauthenticated Presets & Avatar Retrieval PASSED\n";

// Restore coins for clean state
$pdo->prepare('UPDATE user_progress SET coins = 100 WHERE user_id = ?')->execute([$userA]);

echo "=============================================\n";
echo "🎉 ALL AGENT 1 PATCH TESTS PASSED SUCCESSFULLY (8/8)\n";
echo "=============================================\n";
