<?php
// Test suite: Chat multiplayer in-game (global/area/private) + CRUD misi admin.
// Semua validasi melewati Domain resmi (GameChat / Gameplay) — server-side, tanpa hardcode client.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VC\Domain\GameChat;
use VC\Domain\Gameplay;
use VC\Database;
use VC\Security\AuditLog;

echo "=== TEST SUITE: CHAT MULTIPLAYER & MANAJEMEN MISI ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "✅ PASS: {$desc}\n";
    } else {
        $fail++;
        echo "❌ FAIL: {$desc}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

$pdo = Database::get();
$uidA = (int)($pdo->query("SELECT id FROM users WHERE role='murid' AND status='active' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn());
$uidB = (int)($pdo->query("SELECT id FROM users WHERE role IN ('guru','uae','root') AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn());
assertTest('Dua akun fixture tersedia untuk uji chat', $uidA > 0 && $uidB > 0, "A={$uidA}, B={$uidB}");

// Rweset jatah pengujian pada akun fixture (bukan data produksi siswa lain)
GameChat::saveSettings($uidB, ['enabled' => true, 'mode' => 'global_area_private', 'cooldown' => 1, 'filter' => true, 'blocklist' => 'goblok']);
$clean = $pdo->prepare('DELETE FROM game_chat_messages WHERE user_id = ?');
$clean->execute([$uidA]);
$clean->execute([$uidB]);

// 1. Pengaturan default resmi
$s = GameChat::settings(true);
assertTest('Pengaturan chat dibaca dari tabel settings (enabled=1)', $s['enabled'] === true);
assertTest('Mode default global_area_private dengan daftar scope benar',
    $s['mode'] === 'global_area_private' && $s['scopes'] === ['global', 'area', 'private']);

// Helper: jeda 1.5 dtk antar PENGIRIMAN berhasil agar cooldown (1 dtk) tidak menyala di antara uji.
// (Pengiriman yang ditolak sebelum pemeriksaan cooldown tidak meng-update waktu terakhir.)
$sendWait = function (int $uid, string $scope, string $key, string $text) {
    usleep(1500000);
    return GameChat::send($uid, $scope, $key, $text);
};

// 2. Kirim valid
$rGlobal = $sendWait($uidA, 'global', '', 'Halo semua, uji global 8812');
assertTest('Pesan global tersimpan (status ok + id)', ($rGlobal['status'] ?? '') === 'ok' && ($rGlobal['id'] ?? 0) > 0, json_encode($rGlobal, JSON_UNESCAPED_UNICODE));

// 3. Validasi teks
$rEmpty = GameChat::send($uidA, 'global', '', '   ');
assertTest('Pesan kosong ditolak', ($rEmpty['status'] ?? '') === 'error');
$rLong = GameChat::send($uidA, 'global', '', str_repeat('x', 281));
assertTest('Pesan > 280 karakter ditolak', ($rLong['status'] ?? '') === 'error');

// 4. Validasi scope
$rBadScope = GameChat::send($uidA, 'lut', '', 'hai');
assertTest('Scope tidak dikenal ditolak', ($rBadScope['status'] ?? '') === 'error');
$rBadArea = GameChat::send($uidA, 'area', 'AREA_ORAK_ANGAN', 'hai');
assertTest('Area dengan ID lokasi tidak sah ditolak', ($rBadArea['status'] ?? '') === 'error');
$rOkArea = $sendWait($uidA, 'area', 'PERPUS', 'Uji area 5521');
assertTest('Area dengan ID lokasi internal sah tersimpan', ($rOkArea['status'] ?? '') === 'ok', json_encode($rOkArea, JSON_UNESCAPED_UNICODE));
// Pasangan privat yang tidak melibatkan uidA harus ditolak (bukan peserta).
$outside = $uidB; $other = $uidA + 900000;
$rBadPriv = GameChat::send($uidA, 'private', (string)min($outside, $other) . '-' . (string)max($outside, $other), 'hai');
assertTest('Kanal privat dengan pasangan di-luar kepemilikan ditolak', ($rBadPriv['status'] ?? '') === 'error');
$rPriv = $sendWait($uidA, 'private', (string)min($uidA, $uidB) . '-' . (string)max($uidA, $uidB), 'Uji privat 7742');
assertTest('Pesan privat canonical tersimpan', ($rPriv['status'] ?? '') === 'ok', json_encode($rPriv, JSON_UNESCAPED_UNICODE));

// 5. Filter kata terlarang (blocklist tersimpan resmi)
$rFil = $sendWait($uidA, 'global', '', 'kata GOBLOK ini difilter yaaa');
assertTest('Filter menyamarkan kata dalam blocklist (moderasi otomatis)',
    ($rFil['status'] ?? '') === 'ok' && str_contains((string)($pdo->query('SELECT text FROM game_chat_messages WHERE id=' . (int)$rFil['id'])->fetchColumn()), '**'));

// 6. Cooldown eksplisit: kirim pertama ok -> kedua SEGERA (tanpa jeda) harus ditolak
$rSpam = $sendWait($uidA, 'global', '', 'spam cepat 1');
$rSpam2 = GameChat::send($uidA, 'global', '', 'spam cepat 2'); // tanpa jeda
assertTest('Cooldown menolak pesan kedua yang terlalu cepat',
    ($rSpam['status'] ?? '') === 'ok' && ($rSpam2['status'] ?? '') === 'error', json_encode($rSpam2, JSON_UNESCAPED_UNICODE));
usleep(1500000);

// 7. Moderasi staf: soft-delete + pesan tak muncul di fetch
$rDelPut = GameChat::send($uidA, 'global', '', 'pesan untuk dihapus 3341');
usleep(1500000);
$modRes = GameChat::moderate($uidB, (int)$rDelPut['id']);
assertTest('Moderasi staf soft-delete berhasil', ($modRes['status'] ?? '') === 'ok', json_encode($modRes, JSON_UNESCAPED_UNICODE));
$fetched = GameChat::fetch($uidA, 'global', '');
$delSeen = false;
foreach ($fetched['messages'] ?? [] as $mm) { if ((int)$mm['id'] === (int)$rDelPut['id']) { $delSeen = true; } }
assertTest('Pesan termoderasi tak terlihat lagi di riwayat', $delSeen === false);

// 8. Mode chat membatasi scope: hanya global
GameChat::saveSettings($uidB, ['enabled' => true, 'mode' => 'global', 'cooldown' => 1, 'filter' => false, 'blocklist' => '']);
$s2 = GameChat::settings(true);
$rAreaBlocked = GameChat::send($uidA, 'area', 'PERPUS', 'area saat mode global');
assertTest('Mode "global" menolak pesan area', ($rAreaBlocked['status'] ?? '') === 'error', json_encode($rAreaBlocked, JSON_UNESCAPED_UNICODE));
assertTest('Daftar scope mode "global" hanya 1 elemen', $s2['scopes'] === ['global']);

// Chat dinonaktifkan sepenuhnya
GameChat::saveSettings($uidB, ['enabled' => false, 'mode' => 'global', 'cooldown' => 1, 'filter' => false, 'blocklist' => '']);
$rOff = GameChat::send($uidA, 'global', '', 'coba saat mati');
assertTest('Chat nonaktif menolak pengiriman', ($rOff['status'] ?? '') === 'error' && str_contains((string)$rOff['message'], 'nonaktif'));

// Kembalikan pengaturan bawaan
GameChat::saveSettings($uidB, ['enabled' => true, 'mode' => 'global_area_private', 'cooldown' => 2, 'filter' => true, 'blocklist' => 'goblok, bangsat, tolol']);
assertTest('Pengaturan dikembalikan ke bawaan (mode penuh)', GameChat::settings(true)['enabled'] === true);

// 9. CRUD misi admin
$catBaru = 'kategori_baru_uji';
$save = Gameplay::adminSaveMission($uidB, [
    'title' => 'Misi Uji ChatSuite', 'description' => 'Dibuat otomatis test.', 'category' => $catBaru,
    'target_type' => 'location', 'target_value' => 'PERPUS',
    'xp_reward' => 55, 'coin_reward' => 12, 'badge_id' => 0, 'is_active' => true, 'order_num' => 88,
]);
assertTest('Misi baru dibuat oleh admin (kategori baru extensible)', ($save['status'] ?? '') === 'ok' && ($save['action'] ?? '') === 'created', json_encode($save, JSON_UNESCAPED_UNICODE));
$newId = (int)($save['id'] ?? 0);
assertTest('Kategori baru tersimpan enumerable', in_array($catBaru, Gameplay::missionCategories(), true));

$bad1 = Gameplay::adminSaveMission($uidB, ['title' => 'ab', 'category' => 'academic', 'xp_reward' => 1]);
$bad2 = Gameplay::adminSaveMission($uidB, ['title' => 'Misi x', 'category' => 'a b!', 'xp_reward' => 1]);
$bad3 = Gameplay::adminSaveMission($uidB, ['title' => 'Misi x', 'category' => 'academic', 'target_type' => 'location', 'target_value' => 'TIDAK ADA', 'xp_reward' => 1]);
$bad4 = Gameplay::adminSaveMission($uidB, ['title' => 'Misi x', 'category' => 'academic', 'xp_reward' => 0, 'coin_reward' => 0]);
assertTest('Validasi: judul &lt;3 char ditolak', ($bad1['status'] ?? '') === 'error');
assertTest('Validasi: kategori invalid char ditolak', ($bad2['status'] ?? '') === 'error');
assertTest('Validasi: lokasi target tidak dikenal ditolak', ($bad3['status'] ?? '') === 'error');
assertTest('Validasi: misi tanpa hadiah apa pun ditolak', ($bad4['status'] ?? '') === 'error');

$tog = Gameplay::adminToggleMission($uidB, $newId, false);
assertTest('Toggle nonaktif sukses', ($tog['status'] ?? '') === 'ok' && ($tog['is_active'] ?? 0) === 0);
$tog2 = Gameplay::adminToggleMission($uidB, $newId, true);
assertTest('Toggle aktif ulang sukses', ($tog2['status'] ?? '') === 'ok' && ($tog2['is_active'] ?? 0) === 1);

// 10. Alur misi location-bound + reward idempoten (1x) utk misi baru
$cleanM = $pdo->prepare('DELETE FROM mission_progress WHERE user_id = ? AND mission_id = ?');
$cleanM->execute([$uidA, $newId]);
$pdo->prepare('DELETE FROM transactions WHERE user_id = ? AND idempotency_key LIKE ?')->execute([$uidA, 'MISSION-' . $uidA . '-' . $newId . '%']);
Gameplay::recordVisit($uidA, 'PERPUS');
$comp = Gameplay::completeMission($uidA, $newId);
assertTest('Misi location-bound selesai setelah kunjungan', ($comp['status'] ?? '') === 'success', json_encode($comp, JSON_UNESCAPED_UNICODE));
$again = Gameplay::completeMission($uidA, $newId);
assertTest('Klaim ulang misi ditolak (reward tidak berulang)', ($again['status'] ?? '') === 'duplicate');
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id={$uidA} AND idempotency_key LIKE 'MISSION-{$uidA}-{$newId}%'")->fetchColumn();
assertTest('Ledger hadiah misi baru tepat (jumlah = jumlah reward >0)', $cnt === ((55 > 0 ? 1 : 0) + (12 > 0 ? 1 : 0)), "ada={$cnt}");

// 11. Daftar negatif: endpoint relay tanpa signature harus 403
$relayOut = (string)shell_exec("curl -s -o /dev/null -w '%{http_code}' --max-time 6 -X POST http://127.0.0.1/api/game/chat_relay -d '{}' 2>/dev/null");
assertTest('Relay endpoint menolak request tanpa HMAC (HTTP 403)', $relayOut === '403', "dapat: {$relayOut}");

// bersihkan misi & pesan uji agar data demo tetap rapi
$pdo->prepare('DELETE FROM mission_progress WHERE user_id = ? AND mission_id = ?')->execute([$uidA, $newId]);
$pdo->prepare('DELETE FROM transactions WHERE user_id = ? AND idempotency_key LIKE ?')->execute([$uidA, 'MISSION-' . $uidA . '-' . $newId . '%']);
$pdo->prepare('DELETE FROM location_visits WHERE user_id = ? AND location_id = ?')->execute([$uidA, 'PERPUS']);
$pdo->prepare('DELETE FROM missions WHERE id = ? AND category = ?')->execute([$newId, $catBaru]);

AuditLog::log('test_chat_missions_done', ['user' => $uidA], $uidB);

echo "\n============================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "============================================\n";

if ($fail > 0) exit(1);
