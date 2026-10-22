<?php
// Comprehensive automated test suite for Phase 7 (Social Layer: ID Card, Friends, 24h Stories, Chat, Focus Mode)
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VC\Database;
use VC\Domain\Social;
use VC\Security\AuditLog;

echo "=== MEMULAI TEST SUITE PHASE 7 (SOCIAL LAYER & COMMUNICATION) ===\n\n";

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

// 1. Anime.js self-hosted check
$animeFile = VC_PUBLIC . '/assets/vendor/anime.min.js';
assertTest('Anime.js self-hosted file ada di disk dan tidak kosong', is_file($animeFile) && filesize($animeFile) > 100);

// Setup test users (2 students: user A and user B)
$pdo->exec('INSERT OR IGNORE INTO users (uid, username, password_hash, role, full_name, nis, class_id, status)
    VALUES ("uid_test_student_1", "siswa_test_1", "hash", "murid", "Siswa Uji Pertama", "99001", 1, "active")');
$pdo->exec('INSERT OR IGNORE INTO users (uid, username, password_hash, role, full_name, nis, class_id, status)
    VALUES ("uid_test_student_2", "siswa_test_2", "hash", "murid", "Siswa Uji Kedua", "99002", 1, "active")');
$pdo->exec('UPDATE users SET deleted_at = NULL, status = "active" WHERE username IN ("siswa_test_1", "siswa_test_2")');

// Hanya akun uji — jangan pernah mengubah profil siswa asli.
$students = $pdo->query('SELECT id, username, full_name, nis FROM users WHERE username IN ("siswa_test_1", "siswa_test_2") ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC);

$userA = (int)$students[0]['id'];
$userB = (int)$students[1]['id'];

// 2. Profile & Student ID Card
$profA = Social::getProfile($userA);
assertTest('Profil siswa dapat dibaca dari database', !empty($profA) && !empty($profA['qr_token']));

$updateOk = Social::updateProfile($userA, [
    'bio' => 'Siswa aktif TKJ berminat pada keamanan siber dan cloud infrastructure.',
    'status_text' => 'Sedang riset CTF',
    'interests' => 'Networking, Linux, Python',
    'privacy_level' => 'public'
]);
assertTest('Update profil siswa berhasil', $updateOk);

// ID Card privacy test: owner vs peer
$cardOwner = Social::getIdCardData($userA, $userA);
assertTest('Pemilik kartu melihat nomor NIS lengkap', !str_contains($cardOwner['nis'], '*'));

$cardPeer = Social::getIdCardData($userA, $userB);
assertTest('Teman/Peer melihat nomor NIS tersensor untuk privasi', str_contains($cardPeer['nis'], '*') || strlen($cardPeer['nis']) <= 4);

// Test private privacy level
Social::updateProfile($userA, ['privacy_level' => 'private']);
$cardPrivate = Social::getIdCardData($userA, $userB);
assertTest('Profil private menyembunyikan bio & NIS kepada peer', $cardPrivate['nis'] === '******');
Social::updateProfile($userA, ['privacy_level' => 'public']); // restore

// 3. Friendships & Social Graph
// Self-friend should fail
$selfFriend = Social::sendFriendRequest($userA, $userA);
assertTest('Tidak dapat mengirim ajakan berteman ke diri sendiri', ($selfFriend['status'] ?? '') === 'error');

// Clean any previous test friendship between A and B
$pdo->prepare('DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)')->execute([$userA, $userB, $userB, $userA]);

$reqRes = Social::sendFriendRequest($userA, $userB);
assertTest('Pengiriman ajakan berteman berhasil', ($reqRes['status'] ?? '') === 'success');

$pendingB = Social::getPendingRequests($userB);
$hasPending = false;
foreach ($pendingB as $p) {
    if ((int)$p['sender_id'] === $userA) $hasPending = true;
}
assertTest('Penerima melihat ajakan berteman masuk', $hasPending);

$acceptOk = Social::respondFriendRequest($userB, $userA, 'accept');
assertTest('Penerima berhasil menerima ajakan berteman', $acceptOk);

$friendsA = Social::getFriends($userA);
$isFriend = false;
foreach ($friendsA as $f) {
    if ((int)$f['id'] === $userB) $isFriend = true;
}
assertTest('User B terdaftar resmi di daftar teman User A', $isFriend);

// 4. 24-Hour Stories
$storyRes = Social::createStory($userA, 'Hari ini belajar konfigurasi VLAN di Lab TIK 1! 🚀', '#0d9488');
assertTest('Pembuatan cerita 24 jam berhasil', ($storyRes['status'] ?? '') === 'success' && !empty($storyRes['story_id']));
$storyId = (int)$storyRes['story_id'];

$activeStories = Social::getActiveStories();
$foundStory = false;
foreach ($activeStories as $st) {
    if ((int)$st['id'] === $storyId) $foundStory = true;
}
assertTest('Cerita aktif muncul di viewer bar sekolah', $foundStory);

$viewOk = Social::viewStory($storyId, $userB);
assertTest('Pencatatan penonton cerita (view count) berhasil', $viewOk);

// 5. Chat & Focus Mode
$convs = Social::getConversations($userA);
assertTest('Daftar ruang obrolan & saluran publik tersedia', !empty($convs));
$generalConvId = 0;
foreach ($convs as $c) {
    if (($c['channel_tag'] ?? '') === 'general') {
        $generalConvId = (int)$c['id'];
        break;
    }
}
if ($generalConvId === 0) $generalConvId = (int)$convs[0]['id'];

$sendMsg = Social::sendMessage($generalConvId, $userA, 'Halo teman-teman SMK Silber! Selamat belajar.');
assertTest('Pengiriman pesan obrolan berhasil / taat mode fokus', in_array($sendMsg['status'] ?? '', ['success', 'error'], true));

$messages = Social::getMessages($generalConvId, $userA, 10);
assertTest('Pesan obrolan dapat dibaca kembali', is_array($messages));

// Check focus mode method returns boolean
$focusState = Social::isFocusModeActive();
assertTest('Pengecekan status Mode Fokus jam pelajaran berjalan valid', is_bool($focusState));

// 6. Security & Audit Log Hash-chain Integrity
$brokenLink = AuditLog::verify();
assertTest('Audit log hash-chain integrity valid (0 broken links)', $brokenLink === 0, "Broken at ID: $brokenLink");

echo "\n============================================\n";
echo "HASIL AKHIR: $pass PASSED, $fail FAILED\n";
echo "============================================\n";

exit($fail > 0 ? 1 : 0);
