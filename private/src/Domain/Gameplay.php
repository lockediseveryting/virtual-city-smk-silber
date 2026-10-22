<?php
// Gameplay Domain Service: XP & Coins Transaction Ledger, Level Curves, Missions, Badges, Cosmetic Shop, & Leaderboards.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use PDO;

final class Gameplay
{
    /* =========================================================================
     * 1. LEVEL CALCULATION & LEVEL CURVE
     * ========================================================================= */

    private const LEVEL_THRESHOLDS = [
        1  => 0,
        2  => 100,
        3  => 250,
        4  => 450,
        5  => 700,
        6  => 1000,
        7  => 1400,
        8  => 1900,
        9  => 2500,
        10 => 3200,
        11 => 4000,
        12 => 5000,
        15 => 8000,
        20 => 15000,
    ];

    public static function calculateLevel(int $totalXp): int
    {
        $level = 1;
        foreach (self::LEVEL_THRESHOLDS as $lvl => $xpNeeded) {
            if ($totalXp >= $xpNeeded) {
                $level = $lvl;
            } else {
                break;
            }
        }
        return $level;
    }

    /** XP already earned inside the current level. */
    public static function xpIntoLevel(int $totalXp, int $level): int
    {
        $start = self::LEVEL_THRESHOLDS[$level] ?? 0;
        return max(0, $totalXp - $start);
    }

    /** XP still needed for the next level (0 once the curve is topped out). */
    public static function xpToNextLevel(int $totalXp, int $level): int
    {
        $next = null;
        foreach (self::LEVEL_THRESHOLDS as $lvl => $xpNeeded) {
            if ($lvl > $level) {
                $next = $xpNeeded;
                break;
            }
        }
        if ($next === null) {
            return 0;
        }
        return max(0, $next - $totalXp);
    }

    /* =========================================================================
     * 2. XP & COIN TRANSACTION LEDGER (APPEND-ONLY, IDEMPOTENT)
     * ========================================================================= */

    public static function getProgress(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'user_id' => 0,
                'level' => 1,
                'total_xp' => 0,
                'coins' => 0,
                'login_streak' => 0,
                'username' => 'Tamu',
                'full_name' => 'Tamu',
                'role' => 'guest',
                'titles' => null,
            ];
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT p.*, u.username, u.full_name, u.role
            FROM user_progress p
            JOIN users u ON p.user_id = u.id
            WHERE p.user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Self-healing: initialize if record missing
            $pdo->prepare('INSERT OR IGNORE INTO user_progress (user_id, last_login_date) VALUES (?, date("now"))')->execute([$userId]);
            return self::getProgress($userId);
        }

        return $row;
    }

    public static function grantXP(
        int $userId,
        int $amount,
        string $sourceType,
        string $sourceId = '',
        string $desc = '',
        ?string $idempotencyKey = null
    ): array {
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Jumlah XP harus positif.'];
        }

        $pdo = Database::get();
        $isNested = $pdo->inTransaction();
        if (!$isNested) {
            $pdo->beginTransaction();
        }
        try {
            // Check idempotency
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $check = $pdo->prepare('SELECT 1 FROM transactions WHERE idempotency_key = ?');
                $check->execute([$idempotencyKey]);
                if ($check->fetchColumn()) {
                    if (!$isNested && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return ['status' => 'duplicate', 'message' => 'Reward XP sudah pernah diklaim sebelumnya.'];
                }
            }

            // Insert transaction
            $ins = $pdo->prepare('INSERT INTO transactions 
                (user_id, type, amount, source_type, source_id, description, idempotency_key, created_at)
                VALUES (?, "xp", ?, ?, ?, ?, ?, datetime("now"))');
            $ins->execute([$userId, $amount, $sourceType, $sourceId, $desc, $idempotencyKey]);

            // Update user progress cache
            $prog = self::getProgress($userId);
            $newTotalXp = (int)$prog['total_xp'] + $amount;
            $newLevel = self::calculateLevel($newTotalXp);
            $leveledUp = ($newLevel > (int)$prog['level']);

            $upd = $pdo->prepare('UPDATE user_progress SET total_xp = ?, level = ?, updated_at = datetime("now") WHERE user_id = ?');
            $upd->execute([$newTotalXp, $newLevel, $userId]);

            if (!$isNested) {
                $pdo->commit();
            }

            AuditLog::log('xp_grant', [
                'amount' => $amount,
                'source' => $sourceType,
                'new_total' => $newTotalXp,
                'new_level' => $newLevel
            ], $userId);

            return [
                'status' => 'success',
                'amount' => $amount,
                'total_xp' => $newTotalXp,
                'level' => $newLevel,
                'leveled_up' => $leveledUp
            ];
        } catch (\Throwable $e) {
            if (!$isNested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function grantCoins(
        int $userId,
        int $amount,
        string $sourceType,
        string $sourceId = '',
        string $desc = '',
        ?string $idempotencyKey = null
    ): array {
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Jumlah koin harus positif.'];
        }

        $pdo = Database::get();
        $isNested = $pdo->inTransaction();
        if (!$isNested) {
            $pdo->beginTransaction();
        }
        try {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $check = $pdo->prepare('SELECT 1 FROM transactions WHERE idempotency_key = ?');
                $check->execute([$idempotencyKey]);
                if ($check->fetchColumn()) {
                    if (!$isNested && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return ['status' => 'duplicate', 'message' => 'Koin sudah pernah diklaim sebelumnya.'];
                }
            }

            $ins = $pdo->prepare('INSERT INTO transactions 
                (user_id, type, amount, source_type, source_id, description, idempotency_key, created_at)
                VALUES (?, "coins", ?, ?, ?, ?, ?, datetime("now"))');
            $ins->execute([$userId, $amount, $sourceType, $sourceId, $desc, $idempotencyKey]);

            $prog = self::getProgress($userId);
            $newCoins = (int)$prog['coins'] + $amount;

            $upd = $pdo->prepare('UPDATE user_progress SET coins = ?, updated_at = datetime("now") WHERE user_id = ?');
            $upd->execute([$newCoins, $userId]);

            if (!$isNested) {
                $pdo->commit();
            }

            return [
                'status' => 'success',
                'amount' => $amount,
                'coins' => $newCoins
            ];
        } catch (\Throwable $e) {
            if (!$isNested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function spendCoins(
        int $userId,
        int $amount,
        string $sourceType,
        string $sourceId = '',
        string $desc = '',
        ?string $idempotencyKey = null
    ): array {
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Jumlah koin tidak valid.'];
        }

        $pdo = Database::get();
        $isNested = $pdo->inTransaction();
        if (!$isNested) {
            $pdo->beginTransaction();
        }
        try {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $check = $pdo->prepare('SELECT 1 FROM transactions WHERE idempotency_key = ?');
                $check->execute([$idempotencyKey]);
                if ($check->fetchColumn()) {
                    if (!$isNested && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return ['status' => 'duplicate', 'message' => 'Transaksi sudah pernah diproses.'];
                }
            }

            $prog = self::getProgress($userId);
            $currentCoins = (int)$prog['coins'];

            if ($currentCoins < $amount) {
                if (!$isNested && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['status' => 'error', 'message' => 'Saldo koin tidak mencukupi. Selesaikan misi untuk mendapatkan lebih banyak koin!'];
            }

            $ins = $pdo->prepare('INSERT INTO transactions 
                (user_id, type, amount, source_type, source_id, description, idempotency_key, created_at)
                VALUES (?, "coins", ?, ?, ?, ?, ?, datetime("now"))');
            $ins->execute([$userId, -$amount, $sourceType, $sourceId, $desc, $idempotencyKey]);

            $newCoins = $currentCoins - $amount;
            $upd = $pdo->prepare('UPDATE user_progress SET coins = ?, updated_at = datetime("now") WHERE user_id = ?');
            $upd->execute([$newCoins, $userId]);

            if (!$isNested) {
                $pdo->commit();
            }

            AuditLog::log('coin_spend', ['amount' => $amount, 'source' => $sourceType, 'remaining' => $newCoins], $userId);

            return [
                'status' => 'success',
                'spent' => $amount,
                'remaining_coins' => $newCoins
            ];
        } catch (\Throwable $e) {
            if (!$isNested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function transferCoins(
        int $senderId,
        int $receiverId,
        int $amount,
        string $note = ''
    ): array {
        if ($senderId === $receiverId) {
            return ['status' => 'error', 'message' => 'Tidak dapat mengirim koin ke diri sendiri.'];
        }
        if ($amount <= 0 || $amount > 500) {
            return ['status' => 'error', 'message' => 'Jumlah koin transfer minimal 1 dan maksimal 500 koin.'];
        }

        $pdo = Database::get();
        $recUser = $pdo->prepare('SELECT id, username, full_name FROM users WHERE id = ? AND deleted_at IS NULL');
        $recUser->execute([$receiverId]);
        $target = $recUser->fetch(\PDO::FETCH_ASSOC);
        if (!$target) {
            return ['status' => 'error', 'message' => 'Pemain tujuan tidak ditemukan.'];
        }

        $senderProg = self::getProgress($senderId);
        if ((int)$senderProg['coins'] < $amount) {
            return ['status' => 'error', 'message' => 'Saldo koin Anda tidak mencukupi (' . (int)$senderProg['coins'] . ' koin).'];
        }

        $idempKey = 'TRANSFER-' . $senderId . '-' . $receiverId . '-' . microtime(true);
        $pdo->beginTransaction();
        try {
            $senderDesc = 'Kirim hadiah ' . $amount . ' koin ke ' . ($target['full_name'] ?: $target['username']) . ($note ? " ({$note})" : '');
            $spendRes = self::spendCoins($senderId, $amount, 'player_transfer', (string)$receiverId, $senderDesc, $idempKey . '-OUT');
            if (($spendRes['status'] ?? '') !== 'success') {
                $pdo->rollBack();
                return $spendRes;
            }

            $receiverDesc = 'Menerima hadiah ' . $amount . ' koin dari ' . ($senderProg['full_name'] ?: $senderProg['username']) . ($note ? " ({$note})" : '');
            self::grantCoins($receiverId, $amount, 'player_transfer', (string)$senderId, $receiverDesc, $idempKey . '-IN');

            AuditLog::log('coin_transfer', [
                'from_user' => $senderId,
                'to_user' => $receiverId,
                'amount' => $amount,
                'note' => $note
            ], $senderId);

            $pdo->commit();

            return [
                'status' => 'success',
                'amount' => $amount,
                'sender_coins' => $spendRes['remaining_coins'],
                'target_name' => $target['full_name'] ?: $target['username'],
                'message' => 'Berhasil mengirim ' . $amount . ' koin ke ' . ($target['full_name'] ?: $target['username']) . '!'
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['status' => 'error', 'message' => 'Gagal memproses transfer koin: ' . $e->getMessage()];
        }
    }

    public static function recordLoginStreak(int $userId): int
    {
        $pdo = Database::get();
        $prog = self::getProgress($userId);
        $today = date('Y-m-d');
        $lastDate = (string)($prog['last_login_date'] ?? '');

        if ($lastDate === $today) {
            return (int)$prog['login_streak'];
        }

        $streak = (int)$prog['login_streak'];
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        if ($lastDate === $yesterday) {
            $streak++;
        } else {
            $streak = 1;
        }

        $stmt = $pdo->prepare('UPDATE user_progress SET login_streak = ?, last_login_date = ?, updated_at = datetime("now") WHERE user_id = ?');
        $stmt->execute([$streak, $today, $userId]);

        // Daily login reward (XP & coins)
        $idemp = 'LOGIN-' . $userId . '-' . $today;
        self::grantXP($userId, 20, 'login', $today, 'Hadiah login harian', $idemp . '-XP');
        self::grantCoins($userId, 10, 'login', $today, 'Hadiah login harian', $idemp . '-COIN');

        return $streak;
    }

    /* =========================================================================
     * 3. MISSIONS & QUESTS (ORIENTASI, AKADEMIK, EKSPLORASI)
     * ========================================================================= */

    public static function getMissions(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT m.*, 
            COALESCE(mp.status, "not_started") AS user_status,
            mp.started_at, mp.completed_at
            FROM missions m
            LEFT JOIN mission_progress mp ON m.id = mp.mission_id AND mp.user_id = ?
            WHERE m.is_active = 1
            ORDER BY m.order_num ASC, m.id ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function startMission(int $userId, int $missionId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO mission_progress (user_id, mission_id, status, started_at)
            VALUES (?, ?, "in_progress", datetime("now"))
            ON CONFLICT(user_id, mission_id) DO NOTHING');
        $stmt->execute([$userId, $missionId]);

        return ['status' => 'success', 'message' => 'Misi berhasil dimulai.'];
    }

    public static function completeMission(int $userId, int $missionId): array
    {
        $pdo = Database::get();
        $mStmt = $pdo->prepare('SELECT * FROM missions WHERE id = ? AND is_active = 1');
        $mStmt->execute([$missionId]);
        $mission = $mStmt->fetch(PDO::FETCH_ASSOC);

        if (!$mission) {
            return ['status' => 'error', 'message' => 'Misi tidak ditemukan.'];
        }

        // Check if already completed
        $check = $pdo->prepare('SELECT status FROM mission_progress WHERE user_id = ? AND mission_id = ?');
        $check->execute([$userId, $missionId]);
        $status = $check->fetchColumn();

        if ($status === 'completed') {
            return ['status' => 'duplicate', 'message' => 'Misi ini sudah diselesaikan sebelumnya.'];
        }

        // Aturan Agen 1: misi location-bound hanya bisa diklaim bila lokasi target
        // sudah dikunjungi — divalidasi dari rekaman server (location_visits),
        // bukan dari klaim klien.
        if (($mission['target_type'] ?? '') === 'location' && !empty($mission['target_value'])) {
            $targetId = (string)$mission['target_value'];
            if (!self::hasVisited($userId, $targetId)) {
                $locName = self::locationName($targetId) ?? $targetId;
                return ['status' => 'error',
                    'message' => 'Kunjungi "' . $locName . '" dulu di dunia 3D atau cable/2D sebelum mengklaim misi ini.',
                    'required_location' => $targetId];
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO mission_progress (user_id, mission_id, status, completed_at)
                VALUES (?, ?, "completed", datetime("now"))
                ON CONFLICT(user_id, mission_id) DO UPDATE SET status = "completed", completed_at = datetime("now")');
            $stmt->execute([$userId, $missionId]);

            // Grant XP & Coins
            $idempKey = 'MISSION-' . $userId . '-' . $missionId;
            self::grantXP($userId, (int)$mission['xp_reward'], 'mission', (string)$missionId, 'Menyelesaikan misi: ' . $mission['title'], $idempKey . '-XP');
            self::grantCoins($userId, (int)$mission['coin_reward'], 'mission', (string)$missionId, 'Menyelesaikan misi: ' . $mission['title'], $idempKey . '-COIN');

            // Award badge if linked
            if (!empty($mission['badge_id'])) {
                self::awardBadge($userId, (int)$mission['badge_id']);
            }

            $pdo->commit();

            AuditLog::log('mission_complete', ['mission_id' => $missionId, 'title' => $mission['title']], $userId);

            return [
                'status' => 'success',
                'message' => 'Selamat! Misi "' . $mission['title'] . '" berhasil diselesaikan.',
                'rewards' => [
                    'xp' => (int)$mission['xp_reward'],
                    'coins' => (int)$mission['coin_reward']
                ]
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /* =========================================================================
     * 3.5 KUNJUNGAN LOKASI (validasi misi location-bound, terekam server-side)
     * ========================================================================= */

    /** Nama tampilan lokasi dari campus.json (rooms + buildings); null bila tidak ada. */
    public static function locationName(string $locationId): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $locationId)) {
            return null;
        }
        $file = \VC_PUBLIC . '/assets/map/campus.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        foreach (($data['rooms'] ?? []) as $r) {
            if (($r['id'] ?? '') === $locationId) {
                return (string)($r['name'] ?? $locationId);
            }
        }
        foreach (($data['buildings'] ?? []) as $b) {
            if (($b['id'] ?? '') === $locationId) {
                return (string)($b['name'] ?? $locationId);
            }
        }
        return null;
    }

    /** Rekam kunjungan lokasi pemain; ID divalidasi terhadap campus.json. */
    public static function recordVisit(int $userId, string $locationId): array
    {
        $locationId = trim($locationId);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $locationId)) {
            return ['status' => 'error', 'message' => 'ID lokasi tidak valid.'];
        }
        $name = self::locationName($locationId);
        if ($name === null) {
            return ['status' => 'error', 'message' => 'Lokasi tidak dikenal di peta sekolah.'];
        }
        $pdo = Database::get();
        $pdo->prepare('INSERT INTO location_visits (user_id, location_id, visits)
            VALUES (?, ?, 1)
            ON CONFLICT(user_id, location_id) DO UPDATE SET visits = visits + 1, last_seen_at = datetime("now")')
            ->execute([$userId, $locationId]);
        return ['status' => 'ok', 'location' => $locationId, 'name' => $name];
    }

    public static function hasVisited(int $userId, string $locationId): bool
    {
        $stmt = Database::get()->prepare('SELECT 1 FROM location_visits WHERE user_id = ? AND location_id = ?');
        $stmt->execute([$userId, $locationId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Daftar ID lokasi internal yang sudah dikunjungi pemain (untuk indicator progres peta). */
    public static function getVisitedLocations(int $userId): array
    {
        $stmt = Database::get()->prepare('SELECT location_id FROM location_visits WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /* =========================================================================
     * 3.6 ADMIN CRUD MISSIONS (dashboard admin, kategori extensible, validasi server)
     * ========================================================================= */

    /** Kategori yang pernah dipakai (untuk datalist UI — bukan whitelist kaku). */
    public static function missionCategories(): array
    {
        $rows = Database::get()->query('SELECT DISTINCT category FROM missions WHERE category != ""
            ORDER BY category ASC LIMIT 50')->fetchAll(PDO::FETCH_COLUMN);
        return $rows;
    }

    /** Validasi + simpan misi (create bila id=0/null, update bila id>0). */
    public static function adminSaveMission(int $actorId, array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $category = trim((string)($input['category'] ?? ''));
        $targetType = (string)($input['target_type'] ?? 'all');
        $targetValue = trim((string)($input['target_value'] ?? ''));
        $xp = (int)($input['xp_reward'] ?? 0);
        $coins = (int)($input['coin_reward'] ?? 0);
        $badgeId = (int)($input['badge_id'] ?? 0) ?: null;
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $orderNum = max(0, min(999, (int)($input['order_num'] ?? 0)));

        if (mb_strlen($title) < 3 || mb_strlen($title) > 80) {
            return ['status' => 'error', 'message' => 'Nama misi 3-80 karakter.'];
        }
        if (mb_strlen($description) > 600) {
            return ['status' => 'error', 'message' => 'Deskripsi maksimal 600 karakter.'];
        }
        if ($category === '' || mb_strlen($category) > 32 || !preg_match('/^[a-z0-9_\-]+$/u', $category)) {
            return ['status' => 'error', 'message' => 'Kategori wajib diisi (huruf kecil/angka/underscore, maks 32).'];
        }
        if (!in_array($targetType, ['all', 'location'], true)) {
            return ['status' => 'error', 'message' => 'Target misi hanya "all" atau "location".'];
        }
        if ($targetType === 'location' && self::locationName($targetValue) === null) {
            return ['status' => 'error', 'message' => 'Lokasi target tidak ada di campus.json — pakai ID internal yang sah.'];
        }
        if ($xp < 0 || $xp > 10000 || $coins < 0 || $coins > 10000) {
            return ['status' => 'error', 'message' => 'Hadiah XP/koin 0-10000.'];
        }
        if ($xp === 0 && $coins === 0 && $badgeId === null) {
            return ['status' => 'error', 'message' => 'Misi harus punya minimal satu hadiah (XP/koin/lencana).'];
        }
        if ($badgeId !== null) {
            $chk = Database::get()->prepare('SELECT 1 FROM badges WHERE id = ?');
            $chk->execute([$badgeId]);
            if (!$chk->fetchColumn()) {
                return ['status' => 'error', 'message' => 'Lencana tidak ditemukan.'];
            }
        }

        $pdo = Database::get();
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT 1 FROM missions WHERE id = ?');
            $chk->execute([$id]);
            if (!$chk->fetchColumn()) {
                return ['status' => 'error', 'message' => 'Misi tidak ditemukan.'];
            }
            $stmt = $pdo->prepare('UPDATE missions SET title=?, description=?, category=?, target_type=?, target_value=?,
                xp_reward=?, coin_reward=?, badge_id=?, is_active=?, order_num=? WHERE id=?');
            $stmt->execute([$title, $description, $category, $targetType,
                $targetType === 'location' ? $targetValue : null, $xp, $coins, $badgeId, $isActive, $orderNum, $id]);
            AuditLog::log('mission_admin_update', ['mission_id' => $id, 'title' => $title], $actorId);
            return ['status' => 'ok', 'id' => $id, 'action' => 'updated'];
        }
        $stmt = $pdo->prepare('INSERT INTO missions (title, description, category, target_type, target_value,
            xp_reward, coin_reward, badge_id, is_active, order_num, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?, datetime("now"))');
        $stmt->execute([$title, $description, $category, $targetType,
            $targetType === 'location' ? $targetValue : null, $xp, $coins, $badgeId, $isActive, $orderNum]);
        $newId = (int)$pdo->lastInsertId();
        AuditLog::log('mission_admin_create', ['mission_id' => $newId, 'title' => $title], $actorId);
        return ['status' => 'ok', 'id' => $newId, 'action' => 'created'];
    }

    /** Aktif/nonaktifkan misi (tidak menghapus riwayat progres siswa). */
    public static function adminToggleMission(int $actorId, int $id, bool $active): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE missions SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
        if ($stmt->rowCount() === 0) {
            return ['status' => 'error', 'message' => 'Misi tidak ditemukan.'];
        }
        AuditLog::log('mission_admin_toggle', ['mission_id' => $id, 'is_active' => $active ? 1 : 0], $actorId);
        return ['status' => 'ok', 'id' => $id, 'is_active' => $active ? 1 : 0];
    }

    /** Daftar semua misi utuk dashboard admin (termasuk yang nonaktif). */
    public static function adminListMissions(): array
    {
        return Database::get()->query('SELECT m.*, b.name AS badge_name, b.icon_emoji AS badge_icon
            FROM missions m LEFT JOIN badges b ON b.id = m.badge_id
            ORDER BY m.order_num ASC, m.id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Daftar definisi lencana untuk pilihan hadiah misi di dashboard. */
    public static function badgeList(): array
    {
        return Database::get()->query('SELECT id, name, icon_emoji FROM badges ORDER BY id ASC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================================================================
     * 4. BADGES & ACHIEVEMENTS (LENCANA PRESTASI)
     * ========================================================================= */

    public static function getAllBadges(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT b.*, 
            CASE WHEN ub.id IS NOT NULL THEN 1 ELSE 0 END AS is_earned,
            ub.earned_at
            FROM badges b
            LEFT JOIN user_badges ub ON b.id = ub.badge_id AND ub.user_id = ?
            ORDER BY b.id ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function awardBadge(int $userId, int $badgeId): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO user_badges (user_id, badge_id, earned_at)
            VALUES (?, ?, datetime("now"))
            ON CONFLICT(user_id, badge_id) DO NOTHING');
        $ok = $stmt->execute([$userId, $badgeId]);

        if ($ok && $stmt->rowCount() > 0) {
            AuditLog::log('badge_earned', ['badge_id' => $badgeId], $userId);
            return true;
        }
        return false;
    }

    /* =========================================================================
     * 5. COSMETIC SHOP & INVENTORY
     * ========================================================================= */

    public static function getShopItems(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT s.*, 
            CASE WHEN ui.id IS NOT NULL THEN 1 ELSE 0 END AS is_owned,
            COALESCE(ui.equipped, 0) AS is_equipped
            FROM shop_items s
            LEFT JOIN user_items ui ON s.id = ui.item_id AND ui.user_id = ?
            WHERE s.is_available = 1
            ORDER BY s.price_coins ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function purchaseItem(int $userId, int $itemId, ?string $idempotencyKey = null): array
    {
        $pdo = Database::get();
        $isNested = $pdo->inTransaction();
        if (!$isNested) {
            $pdo->beginTransaction();
        }
        try {
            $itemStmt = $pdo->prepare('SELECT * FROM shop_items WHERE id = ? AND is_available = 1');
            $itemStmt->execute([$itemId]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                if (!$isNested && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['status' => 'error', 'message' => 'Item toko tidak ditemukan.'];
            }

            // Check already owned
            $check = $pdo->prepare('SELECT 1 FROM user_items WHERE user_id = ? AND item_id = ?');
            $check->execute([$userId, $itemId]);
            if ($check->fetchColumn()) {
                if (!$isNested && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['status' => 'error', 'message' => 'Anda sudah memiliki item ini.'];
            }

            $spend = self::spendCoins($userId, (int)$item['price_coins'], 'shop', (string)$itemId, 'Membeli item: ' . $item['name'], $idempotencyKey);
            if (($spend['status'] ?? '') !== 'success') {
                if (!$isNested && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return $spend;
            }

            $ins = $pdo->prepare('INSERT INTO user_items (user_id, item_id, acquired_at) VALUES (?, ?, datetime("now"))');
            $ins->execute([$userId, $itemId]);

            // If item is character, also link to user_characters
            if (($item['category'] ?? '') === 'character') {
                $name = (string)$item['name'];
                $slug = match (true) {
                    str_contains($name, 'TKJ') => 'cyber_tech_tkj',
                    str_contains($name, 'RPL') => 'rpl_coder',
                    str_contains($name, 'DKV') => 'dkv_creative',
                    str_contains($name, 'Varsity') => 'silber_varsity',
                    default => null
                };
                if ($slug !== null) {
                    $pId = $pdo->prepare('SELECT id FROM character_presets WHERE slug = ?');
                    $pId->execute([$slug]);
                    $presetId = $pId->fetchColumn();
                    if ($presetId) {
                        $pdo->prepare('INSERT OR IGNORE INTO user_characters (user_id, preset_id, acquired_at) VALUES (?, ?, datetime("now"))')
                            ->execute([$userId, (int)$presetId]);
                    }
                }
            }

            if (!$isNested) {
                $pdo->commit();
            }

            return [
                'status' => 'success',
                'message' => 'Berhasil membeli ' . $item['name'] . '!',
                'remaining_coins' => $spend['remaining_coins']
            ];
        } catch (\Throwable $e) {
            if (!$isNested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Admin balance adjustment with permission check and audit log.
     */
    public static function adminAdjustBalance(
        int $targetUserId,
        int $coinDelta,
        int $xpDelta,
        string $reason,
        int $adminId
    ): array {
        if ($coinDelta === 0 && $xpDelta === 0) {
            return ['status' => 'error', 'message' => 'Tidak ada perubahan saldo.'];
        }

        $pdo = Database::get();
        $isNested = $pdo->inTransaction();
        if (!$isNested) {
            $pdo->beginTransaction();
        }
        try {
            $prog = self::getProgress($targetUserId);
            $currentCoins = (int)$prog['coins'];
            $newCoins = $currentCoins + $coinDelta;
            if ($newCoins < 0) {
                if (!$isNested && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['status' => 'error', 'message' => 'Penyesuaian saldo gagal: saldo koin tidak boleh negatif.'];
            }

            $currentXp = (int)$prog['total_xp'];
            $newXp = max(0, $currentXp + $xpDelta);
            $newLevel = self::calculateLevel($newXp);

            // Record coin transaction if changed
            if ($coinDelta !== 0) {
                $insCoin = $pdo->prepare('INSERT INTO transactions 
                    (user_id, type, amount, source_type, source_id, description, created_at)
                    VALUES (?, "coins", ?, "admin_adjustment", ?, ?, datetime("now"))');
                $insCoin->execute([$targetUserId, $coinDelta, (string)$adminId, "Penyesuaian admin: " . $reason]);
            }

            // Record XP transaction if changed
            if ($xpDelta !== 0) {
                $insXp = $pdo->prepare('INSERT INTO transactions 
                    (user_id, type, amount, source_type, source_id, description, created_at)
                    VALUES (?, "xp", ?, "admin_adjustment", ?, ?, datetime("now"))');
                $insXp->execute([$targetUserId, $xpDelta, (string)$adminId, "Penyesuaian admin: " . $reason]);
            }

            $upd = $pdo->prepare('UPDATE user_progress SET coins = ?, total_xp = ?, level = ?, updated_at = datetime("now") WHERE user_id = ?');
            $upd->execute([$newCoins, $newXp, $newLevel, $targetUserId]);

            if (!$isNested) {
                $pdo->commit();
            }

            AuditLog::log('admin_balance_adjust', [
                'target_user_id' => $targetUserId,
                'admin_id' => $adminId,
                'coin_delta' => $coinDelta,
                'xp_delta' => $xpDelta,
                'new_coins' => $newCoins,
                'new_xp' => $newXp,
                'reason' => $reason
            ], $adminId);

            return [
                'status' => 'success',
                'target_user_id' => $targetUserId,
                'new_coins' => $newCoins,
                'new_xp' => $newXp,
                'new_level' => $newLevel
            ];
        } catch (\Throwable $e) {
            if (!$isNested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reconcile cached user_progress with immutable transactions ledger.
     */
    public static function reconcileLedger(int $userId, bool $autoFix = false): array
    {
        $pdo = Database::get();
        $prog = self::getProgress($userId);
        $cachedCoins = (int)$prog['coins'];
        $cachedXp = (int)$prog['total_xp'];

        $stmtC = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = "coins"');
        $stmtC->execute([$userId]);
        $ledgerCoins = (int)$stmtC->fetchColumn();

        $stmtX = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = "xp"');
        $stmtX->execute([$userId]);
        $ledgerXp = max(0, (int)$stmtX->fetchColumn());

        $coinDiff = $cachedCoins - $ledgerCoins;
        $xpDiff = $cachedXp - $ledgerXp;
        $isConsistent = ($coinDiff === 0 && $xpDiff === 0);

        $result = [
            'user_id' => $userId,
            'consistent' => $isConsistent,
            'cached_coins' => $cachedCoins,
            'ledger_coins' => $ledgerCoins,
            'coin_diff' => $coinDiff,
            'cached_xp' => $cachedXp,
            'ledger_xp' => $ledgerXp,
            'xp_diff' => $xpDiff,
            'fixed' => false
        ];

        if (!$isConsistent && $autoFix) {
            $newLevel = self::calculateLevel($ledgerXp);
            $upd = $pdo->prepare('UPDATE user_progress SET coins = ?, total_xp = ?, level = ?, updated_at = datetime("now") WHERE user_id = ?');
            $upd->execute([$ledgerCoins, $ledgerXp, $newLevel, $userId]);

            AuditLog::log('ledger_reconciliation_fix', [
                'user_id' => $userId,
                'previous_coins' => $cachedCoins,
                'repaired_coins' => $ledgerCoins,
                'previous_xp' => $cachedXp,
                'repaired_xp' => $ledgerXp
            ], $userId);

            $result['fixed'] = true;
            $result['cached_coins'] = $ledgerCoins;
            $result['cached_xp'] = $ledgerXp;
            $result['coin_diff'] = 0;
            $result['xp_diff'] = 0;
            $result['consistent'] = true;
        }

        return $result;
    }

    /**
     * Check all users' progress against ledger.
     */
    public static function reconcileAll(bool $autoFix = false): array
    {
        $pdo = Database::get();
        $users = $pdo->query('SELECT user_id FROM user_progress')->fetchAll(PDO::FETCH_COLUMN);
        $discrepancies = [];
        $totalChecked = count($users);

        foreach ($users as $uid) {
            $rec = self::reconcileLedger((int)$uid, $autoFix);
            if (!$rec['consistent'] || $rec['fixed']) {
                $discrepancies[] = $rec;
            }
        }

        return [
            'total_checked' => $totalChecked,
            'discrepancies_count' => count($discrepancies),
            'discrepancies' => $discrepancies
        ];
    }

    public static function equipItem(int $userId, int $itemId): array
    {
        $pdo = Database::get();
        $check = $pdo->prepare('SELECT ui.equipped, s.category, s.name 
            FROM user_items ui 
            JOIN shop_items s ON ui.item_id = s.id 
            WHERE ui.user_id = ? AND ui.item_id = ?');
        $check->execute([$userId, $itemId]);
        $item = $check->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return ['status' => 'error', 'message' => 'Item belum dimiliki.'];
        }

        $newEquipped = ($item['equipped'] == 1) ? 0 : 1;

        if ($newEquipped === 1) {
            // Unequip other items in same category
            $pdo->prepare('UPDATE user_items SET equipped = 0 
                WHERE user_id = ? AND item_id IN (SELECT id FROM shop_items WHERE category = ?)')
                ->execute([$userId, $item['category']]);
        }

        $pdo->prepare('UPDATE user_items SET equipped = ? WHERE user_id = ? AND item_id = ?')
            ->execute([$newEquipped, $userId, $itemId]);

        return [
            'status' => 'success',
            'message' => $newEquipped ? ('Berhasil menggunakan ' . $item['name']) : ('Melepas ' . $item['name']),
            'is_equipped' => (bool)$newEquipped
        ];
    }

    /* =========================================================================
     * 6. LEADERBOARDS (PAPAN PERINGKAT SEKOLAH & KELAS)
     * ========================================================================= */

    public static function getLeaderboard(string $scope = 'school', ?int $classId = null, int $limit = 20): array
    {
        $pdo = Database::get();
        $sql = 'SELECT p.level, p.total_xp, p.coins, p.login_streak, p.titles,
                       u.id AS user_id, u.username, u.full_name, u.role,
                       c.name AS class_name, prog.name AS program_name, prog.accent_color
                FROM user_progress p
                JOIN users u ON p.user_id = u.id
                LEFT JOIN classes c ON u.class_id = c.id
                LEFT JOIN programs prog ON c.program_id = prog.id
                WHERE u.deleted_at IS NULL AND u.role = "murid"';
        $params = [];

        if ($scope === 'class' && $classId !== null && $classId > 0) {
            $sql .= ' AND u.class_id = ?';
            $params[] = $classId;
        }

        $sql .= ' ORDER BY p.total_xp DESC, p.level DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================================================================
     * 7. CHARACTER PRESETS & AVATAR SELECTION
     * ========================================================================= */

    public static function getCharacterPresets(int $userId): array
    {
        $pdo = Database::get();
        if ($userId <= 0) {
            $userLevel = 1;
            $activeAvatar = 'student_casual';
            $acquiredIds = [];
        } else {
            $prog = self::getProgress($userId);
            $userLevel = (int)$prog['level'];

            $profStmt = $pdo->prepare('SELECT active_avatar, avatar_config FROM user_profiles WHERE user_id = ?');
            $profStmt->execute([$userId]);
            $prof = $profStmt->fetch(PDO::FETCH_ASSOC);
            $activeAvatar = $prof ? ($prof['active_avatar'] ?? 'student_casual') : 'student_casual';

            $acqStmt = $pdo->prepare('SELECT preset_id FROM user_characters WHERE user_id = ?');
            $acqStmt->execute([$userId]);
            $acquiredIds = $acqStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $presets = $pdo->query('SELECT * FROM character_presets ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($presets as $p) {
            $isAcquired = in_array((int)$p['id'], $acquiredIds, true) || ((int)$p['price_coins'] === 0);
            $isActive = ($p['slug'] === $activeAvatar);

            $reqLevel = 1;
            if (!empty($p['unlock_req']) && preg_match('/level:(\d+)/', $p['unlock_req'], $m)) {
                $reqLevel = (int)$m[1];
            }
            $canUnlock = ($userLevel >= $reqLevel);

            $config = json_decode((string)$p['default_config'], true) ?? [];

            $result[] = [
                'id' => (int)$p['id'],
                'slug' => $p['slug'],
                'name' => $p['name'],
                'description' => $p['description'],
                'price_coins' => (int)$p['price_coins'],
                'is_default' => (bool)$p['is_default'],
                'required_level' => $reqLevel,
                'can_unlock' => $canUnlock,
                'is_acquired' => $isAcquired,
                'is_active' => $isActive,
                'config' => $config,
            ];
        }

        return [
            'status' => 'success',
            'active_avatar' => $activeAvatar,
            'characters' => $result,
        ];
    }

    public static function getActiveAvatar(int $userId): array
    {
        $defConfig = [
            'hair' => 'neat_short',
            'hair_color' => '#262626',
            'outfit' => 'casual',
            'outfit_color' => '#0284c7',
            'skin_tone' => '#ffd19a',
            'shoes_color' => '#1e293b',
            'accessory' => 'none'
        ];

        if ($userId <= 0) {
            return [
                'slug' => 'student_casual',
                'name' => 'Siswa Kasual SMK Silber',
                'config' => $defConfig
            ];
        }

        $pdo = Database::get();
        $profStmt = $pdo->prepare('SELECT active_avatar, avatar_config FROM user_profiles WHERE user_id = ?');
        $profStmt->execute([$userId]);
        $prof = $profStmt->fetch(PDO::FETCH_ASSOC);

        $slug = $prof ? ($prof['active_avatar'] ?? 'student_casual') : 'student_casual';
        $userConfig = ($prof && !empty($prof['avatar_config'])) ? (json_decode((string)$prof['avatar_config'], true) ?: []) : [];

        // Fetch preset default config to merge
        $pStmt = $pdo->prepare('SELECT name, default_config FROM character_presets WHERE slug = ?');
        $pStmt->execute([$slug]);
        $presetRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        $defConfigStr = $presetRow['default_config'] ?? null;
        if ($defConfigStr) {
            $parsed = json_decode($defConfigStr, true);
            if (is_array($parsed)) {
                $defConfig = array_merge($defConfig, $parsed);
            }
        }

        $finalConfig = array_merge($defConfig, $userConfig);

        return [
            'slug' => $slug,
            'name' => $presetRow['name'] ?? 'Siswa SMK Silber',
            'config' => $finalConfig
        ];
    }

    public static function selectCharacter(int $userId, int $presetId, ?string $idempotencyKey = null): array
    {
        if ($userId <= 0) {
            return ['status' => 'error', 'message' => 'Login diperlukan untuk memilih karakter.'];
        }

        $pdo = Database::get();
        $isNested = $pdo->inTransaction();
        if (!$isNested) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT * FROM character_presets WHERE id = ?');
            $stmt->execute([$presetId]);
            $preset = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$preset) {
                if (!$isNested && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['status' => 'error', 'message' => 'Preset karakter tidak ditemukan.'];
            }

            $prog = self::getProgress($userId);
            $userLevel = (int)$prog['level'];

            $reqLevel = 1;
            if (!empty($preset['unlock_req']) && preg_match('/level:(\d+)/', $preset['unlock_req'], $m)) {
                $reqLevel = (int)$m[1];
            }
            if ($userLevel < $reqLevel) {
                if (!$isNested && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['status' => 'error', 'message' => "Karakter ini membutuhkan Level {$reqLevel}. Selesaikan misi untuk meningkatkan level!"];
            }

            $acqCheck = $pdo->prepare('SELECT 1 FROM user_characters WHERE user_id = ? AND preset_id = ?');
            $acqCheck->execute([$userId, $presetId]);
            $isAcquired = (bool)$acqCheck->fetchColumn();

            $price = (int)$preset['price_coins'];
            $idemp = $idempotencyKey ?: ('CHAR_SELECT-' . $userId . '-' . $presetId);
            if (!$isAcquired && $price > 0) {
                $spend = self::spendCoins($userId, $price, 'character_unlock', (string)$presetId, 'Membeli karakter: ' . $preset['name'], $idemp);
                if (($spend['status'] ?? '') !== 'success') {
                    if (!$isNested && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return $spend;
                }

                $ins = $pdo->prepare('INSERT INTO user_characters (user_id, preset_id, acquired_at) VALUES (?, ?, datetime("now"))');
                $ins->execute([$userId, $presetId]);
            } elseif (!$isAcquired && $price === 0) {
                $ins = $pdo->prepare('INSERT OR IGNORE INTO user_characters (user_id, preset_id, acquired_at) VALUES (?, ?, datetime("now"))');
                $ins->execute([$userId, $presetId]);
            }

            $qrToken = bin2hex(random_bytes(16));
            $updProf = $pdo->prepare('INSERT INTO user_profiles (user_id, qr_token, active_avatar, avatar_config, updated_at)
                VALUES (?, ?, ?, ?, datetime("now"))
                ON CONFLICT(user_id) DO UPDATE SET active_avatar = excluded.active_avatar, avatar_config = excluded.avatar_config, updated_at = datetime("now")');
            $updProf->execute([$userId, $qrToken, $preset['slug'], $preset['default_config']]);

            $updAct = $pdo->prepare('UPDATE active_players SET avatar_config = ? WHERE user_id = ?');
            $updAct->execute([$preset['default_config'], $userId]);

            if (!$isNested) {
                $pdo->commit();
            }

            AuditLog::log('character_select', [
                'preset_id' => $presetId,
                'slug' => $preset['slug'],
                'name' => $preset['name']
            ], $userId);

            $config = json_decode((string)$preset['default_config'], true) ?? [];

            return [
                'status' => 'success',
                'message' => 'Karakter ' . $preset['name'] . ' berhasil dipilih!',
                'active_avatar' => $preset['slug'],
                'name' => $preset['name'],
                'config' => $config,
            ];
        } catch (\Throwable $e) {
            if (!$isNested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function saveAvatarConfig(int $userId, array $newConfig): array
    {
        if ($userId <= 0) {
            return ['status' => 'error', 'message' => 'Login diperlukan untuk kustomisasi karakter.'];
        }

        $pdo = Database::get();
        $current = self::getActiveAvatar($userId);
        $config = $current['config'];

        // Allowed hair styles
        $allowedHairs = ['neat_short', 'undercut', 'ponytail', 'hijab'];
        if (isset($newConfig['hair']) && in_array($newConfig['hair'], $allowedHairs, true)) {
            $config['hair'] = $newConfig['hair'];
        }

        // Allowed hex color fields
        $colorFields = ['hair_color', 'outfit_color', 'skin_tone', 'shoes_color'];
        foreach ($colorFields as $cf) {
            if (isset($newConfig[$cf]) && is_string($newConfig[$cf]) && preg_match('/^#[0-9a-fA-F]{6}$/', $newConfig[$cf])) {
                $config[$cf] = $newConfig[$cf];
            }
        }

        // Allowed outfits
        $allowedOutfits = ['casual', 'blazer', 'cyber_hoodie', 'tech_jacket', 'creative_vest', 'varsity'];
        if (isset($newConfig['outfit']) && in_array($newConfig['outfit'], $allowedOutfits, true)) {
            $config['outfit'] = $newConfig['outfit'];
        }

        // Allowed accessories
        $allowedAccessories = ['none', 'tie', 'cyber_visor', 'headphones', 'glasses', 'backpack'];
        if (isset($newConfig['accessory']) && in_array($newConfig['accessory'], $allowedAccessories, true)) {
            $config['accessory'] = $newConfig['accessory'];
        }

        $jsonStr = json_encode($config);
        $upd = $pdo->prepare('UPDATE user_profiles SET avatar_config = ?, updated_at = datetime("now") WHERE user_id = ?');
        $upd->execute([$jsonStr, $userId]);
        if ($upd->rowCount() === 0) {
            $qrToken = bin2hex(random_bytes(16));
            $ins = $pdo->prepare('INSERT INTO user_profiles (user_id, qr_token, active_avatar, avatar_config, updated_at) VALUES (?, ?, ?, ?, datetime("now"))');
            $ins->execute([$userId, $qrToken, $current['slug'], $jsonStr]);
        }

        // Sync with active_players
        $mergedAvatar = ['slug' => $current['slug'], 'config' => $config];
        $pdo->prepare('UPDATE active_players SET avatar_config = ? WHERE user_id = ?')
            ->execute([json_encode($mergedAvatar), $userId]);

        AuditLog::log('avatar_customize', ['slug' => $current['slug'], 'config' => $config], $userId);

        return [
            'status' => 'success',
            'message' => 'Konfigurasi gaya karakter berhasil disimpan!',
            'avatar' => $mergedAvatar
        ];
    }
}
