<?php
// Social Domain Service: Student ID Card, Friends, 24h Stories, Chat & Schedule Focus Mode.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use PDO;

final class Social
{
    /* =========================================================================
     * 1. PROFILES & DIGITAL STUDENT ID CARD
     * ========================================================================= */

    public static function getProfile(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT u.id, u.uid, u.username, u.full_name, u.role, u.nis, u.class_id,
            c.name AS class_name, p.name AS program_name, p.accent_color,
            prof.bio, prof.status_text, prof.avatar_color, prof.interests, prof.card_theme, prof.qr_token, prof.privacy_level
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN programs p ON c.program_id = p.id
            LEFT JOIN user_profiles prof ON u.id = prof.user_id
            WHERE u.id = ? AND u.deleted_at IS NULL');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return [];

        if (empty($row['qr_token'])) {
            $row['qr_token'] = 'SILBER-ID-' . bin2hex(random_bytes(8));
            $pdo->prepare('INSERT OR IGNORE INTO user_profiles (user_id, qr_token) VALUES (?, ?)')->execute([$userId, $row['qr_token']]);
        }

        return $row;
    }

    /** Perbarui profil: hanya kolom yang dikirim yang diubah (sisanya tetap), panjang & pilihan divalidasi. */
    public static function updateProfile(int $userId, array $data): bool
    {
        $pdo = Database::get();
        self::getProfile($userId); // pastikan baris user_profiles ada
        $limits = ['bio' => 160, 'status_text' => 80, 'interests' => 120];
        $set = [];
        $vals = [];
        foreach ($limits as $k => $max) {
            if (array_key_exists($k, $data)) {
                $set[] = $k . ' = ?';
                $vals[] = mb_substr(trim(strip_tags((string)$data[$k])), 0, $max);
            }
        }
        if (array_key_exists('privacy_level', $data)) {
            $pv = (string)$data['privacy_level'];
            $set[] = 'privacy_level = ?';
            $vals[] = in_array($pv, ['public', 'friends', 'private'], true) ? $pv : 'public';
        }
        if (isset($data['avatar_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$data['avatar_color'])) {
            $set[] = 'avatar_color = ?';
            $vals[] = (string)$data['avatar_color'];
        }
        if ($set === []) {
            return true;
        }
        $vals[] = $userId;
        $ok = $pdo->prepare('UPDATE user_profiles SET ' . implode(', ', $set) . ', updated_at = datetime("now") WHERE user_id = ?')->execute($vals);
        if ($ok) {
            AuditLog::log('profile_update', ['fields' => array_map(static fn($x) => strtok($x, ' '), $set)], $userId);
        }
        return $ok;
    }

    /** Ringkasan pencapaian untuk halaman profil (level, misi, lencana, sertifikat, item, kunjungan). */
    public static function achievements(int $userId): array
    {
        $pdo = Database::get();
        $one = static function (string $sql, array $p = []) use ($pdo) {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return $st->fetchColumn();
        };
        $all = static function (string $sql, array $p = []) use ($pdo): array {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };
        $prog = $all('SELECT level, total_xp, coins, login_streak, titles FROM user_progress WHERE user_id = ?', [$userId])[0]
            ?? ['level' => 1, 'total_xp' => 0, 'coins' => 0, 'login_streak' => 0, 'titles' => 'Siswa Baru'];
        return [
            'progress' => $prog,
            'missions_done' => (int)$one('SELECT COUNT(*) FROM mission_progress WHERE user_id = ? AND status = "completed"', [$userId]),
            'places' => (int)$one('SELECT COUNT(*) FROM location_visits WHERE user_id = ?', [$userId]),
            'tasks_done' => (int)$one('SELECT COUNT(*) FROM submissions WHERE student_id = ?', [$userId]),
            'friends' => (int)$one('SELECT COUNT(*) FROM friendships WHERE status = "accepted" AND (user_id = ? OR friend_id = ?)', [$userId, $userId]),
            'badges' => $all('SELECT b.id, b.name, b.description, b.icon_emoji, b.rarity, ub.earned_at FROM user_badges ub JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id = ? ORDER BY ub.earned_at DESC', [$userId]),
            'badge_total' => (int)$one('SELECT COUNT(*) FROM badges'),
            'certificates' => $all('SELECT title, cert_number, issued_at FROM certificates WHERE user_id = ? ORDER BY issued_at DESC LIMIT 12', [$userId]),
            'items' => $all('SELECT s.name, s.category, s.rarity, ui.equipped FROM user_items ui JOIN shop_items s ON s.id = ui.item_id WHERE ui.user_id = ? ORDER BY ui.acquired_at DESC LIMIT 24', [$userId]),
        ];
    }

    /** Status pertemanan dari sudut pandang $viewerId: none | pending_out | pending_in | accepted | blocked. */
    public static function friendStatus(int $viewerId, int $otherId): string
    {
        $st = Database::get()->prepare('SELECT user_id, status FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?) LIMIT 1');
        $st->execute([$viewerId, $otherId, $otherId, $viewerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return 'none';
        }
        if ($r['status'] === 'pending') {
            return (int)$r['user_id'] === $viewerId ? 'pending_out' : 'pending_in';
        }
        return (string)$r['status'];
    }

    /** Cari pengguna aktif berdasarkan nama/username (untuk buka profil orang lain). */
    public static function searchUsers(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $st = Database::get()->prepare('SELECT u.id, u.full_name, u.username, u.role, c.name AS class_name, COALESCE(p.level, 1) AS level
            FROM users u LEFT JOIN classes c ON c.id = u.class_id LEFT JOIN user_progress p ON p.user_id = u.id
            WHERE u.deleted_at IS NULL AND u.status = "active" AND u.role != "tamu"
              AND (u.full_name LIKE ? ESCAPE "!" OR u.username LIKE ? ESCAPE "!")
            ORDER BY u.full_name LIMIT ' . max(1, min(50, $limit)));
        $st->execute([$like, $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // Status online langsung dari database; yang online tampil paling atas.
        $pres = Presence::statusFor(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $s = $pres[(int)$r['id']] ?? ['online' => false, 'place' => '', 'ago' => null];
            $r['id'] = (int)$r['id'];
            $r['online'] = $s['online'];
            $r['presence'] = Presence::label($s);
        }
        unset($r);
        usort($rows, static fn($a, $b) => [(int)!$a['online'], (string)$a['full_name']] <=> [(int)!$b['online'], (string)$b['full_name']]);
        return $rows;
    }

    /** Buka (atau buat) percakapan langsung 1-lawan-1. */
    public static function openDirect(int $a, int $b): int
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return 0;
        }
        $pdo = Database::get();
        $tag = 'dm:' . min($a, $b) . '-' . max($a, $b);
        $st = $pdo->prepare('SELECT id FROM conversations WHERE channel_tag = ? LIMIT 1');
        $st->execute([$tag]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $names = $pdo->prepare('SELECT id, COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id IN (?, ?) AND deleted_at IS NULL');
        $names->execute([$a, $b]);
        $n = $names->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($n) < 2) {
            return 0;
        }
        $pdo->prepare('INSERT INTO conversations (type, name, channel_tag, created_by) VALUES ("direct", ?, ?, ?)')
            ->execute(['💬 ' . $n[$a] . ' & ' . $n[$b], $tag, $a]);
        $id = (int)$pdo->lastInsertId();
        $m = $pdo->prepare('INSERT OR IGNORE INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        $m->execute([$id, $a]);
        $m->execute([$id, $b]);
        return $id;
    }

    public static function getIdCardData(int $userId, int $viewerId): array
    {
        $profile = self::getProfile($userId);
        if (empty($profile)) return [];

        $isOwnerOrStaff = ($userId === $viewerId);
        if (!$isOwnerOrStaff) {
            $pdo = Database::get();
            $viewer = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $viewer->execute([$viewerId]);
            $role = $viewer->fetchColumn();
            if (in_array($role, ['guru', 'wali_kelas', 'kepala_program', 'root'], true)) {
                $isOwnerOrStaff = true;
            }
        }

        // Privacy filtering: redact full NIS and internal tokens for non-staff peers
        if (!$isOwnerOrStaff && $profile['privacy_level'] === 'private') {
            $profile['nis'] = '******';
            $profile['bio'] = 'Profil ini disetel privat.';
        } elseif (!$isOwnerOrStaff) {
            // Mask partial NIS for general peers
            $nis = (string)($profile['nis'] ?? '');
            if (strlen($nis) > 4) {
                $profile['nis'] = substr($nis, 0, 2) . str_repeat('*', strlen($nis) - 4) . substr($nis, -2);
            }
        }

        return $profile;
    }

    /* =========================================================================
     * 2. FRIENDS & SOCIAL GRAPH
     * ========================================================================= */

    public static function sendFriendRequest(int $userId, int $friendId): array
    {
        if ($userId === $friendId) {
            return ['status' => 'error', 'message' => 'Tidak dapat berteman dengan diri sendiri.'];
        }

        $pdo = Database::get();
        // Check existing friendship
        $stmt = $pdo->prepare('SELECT status FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)');
        $stmt->execute([$userId, $friendId, $friendId, $userId]);
        $existing = $stmt->fetchColumn();

        if ($existing === 'accepted') {
            return ['status' => 'error', 'message' => 'Anda sudah berteman.'];
        }
        if ($existing === 'blocked') {
            return ['status' => 'error', 'message' => 'Tidak dapat mengirim permintaan pertemanan.'];
        }
        if ($existing === 'pending') {
            return ['status' => 'error', 'message' => 'Permintaan pertemanan sudah dikirim sebelumnya.'];
        }

        $ins = $pdo->prepare('INSERT INTO friendships (user_id, friend_id, status, created_at) VALUES (?, ?, "pending", datetime("now"))');
        $ins->execute([$userId, $friendId]);

        AuditLog::log('friend_request_send', ['target_id' => $friendId], $userId);

        return ['status' => 'success', 'message' => 'Permintaan pertemanan berhasil dikirim.'];
    }

    public static function respondFriendRequest(int $userId, int $senderId, string $action): bool
    {
        $pdo = Database::get();
        if ($action === 'accept') {
            $stmt = $pdo->prepare('UPDATE friendships SET status = "accepted", accepted_at = datetime("now") WHERE user_id = ? AND friend_id = ? AND status = "pending"');
            $ok = $stmt->execute([$senderId, $userId]);
            if ($ok && $stmt->rowCount() > 0) {
                AuditLog::log('friend_request_accept', ['friend_id' => $senderId], $userId);
                return true;
            }
            return false;
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare('DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = "pending"');
            return $stmt->execute([$senderId, $userId]);
        }
        return false;
    }

    public static function getFriends(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT u.id, u.username, u.full_name, u.role, c.name AS class_name, prof.avatar_color, prof.status_text
            FROM friendships f
            JOIN users u ON (CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END) = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN user_profiles prof ON u.id = prof.user_id
            WHERE (f.user_id = ? OR f.friend_id = ?) AND f.status = "accepted" AND u.deleted_at IS NULL
            ORDER BY u.full_name ASC');
        $stmt->execute([$userId, $userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getPendingRequests(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT f.id AS request_id, u.id AS sender_id, u.username, u.full_name, c.name AS class_name, f.created_at
            FROM friendships f
            JOIN users u ON f.user_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE f.friend_id = ? AND f.status = "pending"
            ORDER BY f.created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================================================================
     * 3. 24-HOUR STORIES
     * ========================================================================= */

    public static function createStory(int $userId, string $text, string $bgColor = '#0d9488'): array
    {
        $cleanText = trim($text);
        if ($cleanText === '') {
            return ['status' => 'error', 'message' => 'Teks cerita tidak boleh kosong.'];
        }

        $pdo = Database::get();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $stmt = $pdo->prepare('INSERT INTO stories (user_id, content_text, background_color, expires_at, created_at)
            VALUES (?, ?, ?, ?, datetime("now"))');
        $stmt->execute([$userId, $cleanText, $bgColor, $expiresAt]);
        $storyId = (int)$pdo->lastInsertId();

        AuditLog::log('story_create', ['story_id' => $storyId], $userId);

        return [
            'status' => 'success',
            'story_id' => $storyId,
            'expires_at' => $expiresAt
        ];
    }

    public static function getActiveStories(): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT s.*, u.username, u.full_name, prof.avatar_color,
            (SELECT COUNT(*) FROM story_views WHERE story_id = s.id) AS view_count
            FROM stories s
            JOIN users u ON s.user_id = u.id
            LEFT JOIN user_profiles prof ON u.id = prof.user_id
            WHERE s.expires_at > datetime("now") AND s.deleted_at IS NULL
            ORDER BY s.created_at DESC');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function viewStory(int $storyId, int $viewerId): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO story_views (story_id, viewer_id, viewed_at)
            VALUES (?, ?, datetime("now"))
            ON CONFLICT(story_id, viewer_id) DO NOTHING');
        return $stmt->execute([$storyId, $viewerId]);
    }

    /* =========================================================================
     * 4. CHAT & FOCUS MODE (RESTRICTION DURING ACTIVE LESSON HOURS)
     * ========================================================================= */

    public static function isFocusModeActive(): bool
    {
        // Check timetable: if current time falls within active school periods (is_break = 0),
        // Focus Mode can restrict public general chat channels to promote classroom learning.
        $pdo = Database::get();
        $dayOfWeek = (int)Schedule::nowLocal('N'); // 1 = Senin, 5 = Jumat (waktu sekolah)
        if ($dayOfWeek > 5) return false;

        $currentTime = Schedule::nowLocal('H:i');
        $stmt = $pdo->prepare('SELECT 1 FROM time_slots 
            WHERE day_of_week = ? AND is_break = 0 AND ? >= start_time AND ? <= end_time
            LIMIT 1');
        $stmt->execute([$dayOfWeek, $currentTime, $currentTime]);
        return (bool)$stmt->fetchColumn();
    }

    public static function getConversations(int $userId): array
    {
        $pdo = Database::get();
        // Return public channels and conversations where user is a member
        $stmt = $pdo->prepare('SELECT c.*,
            (SELECT message_text FROM messages WHERE conversation_id = c.id AND deleted_at IS NULL ORDER BY id DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message_at
            FROM conversations c
            WHERE c.type = "channel" OR c.id IN (SELECT conversation_id FROM conversation_members WHERE user_id = ?)
            ORDER BY c.type DESC, last_message_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Saluran publik terbuka untuk semua; percakapan langsung/grup hanya untuk anggotanya. */
    public static function canAccess(int $conversationId, int $userId): bool
    {
        $st = Database::get()->prepare('SELECT 1 FROM conversations c WHERE c.id = ? AND (c.type = "channel"
            OR EXISTS (SELECT 1 FROM conversation_members m WHERE m.conversation_id = c.id AND m.user_id = ?))');
        $st->execute([$conversationId, $userId]);
        return (bool)$st->fetchColumn();
    }

    /** Moderator chat: boleh menghapus pesan siapa pun & tetap melihat isi pesan yang sudah dihapus. */
    /** Moderasi chat (hapus pesan orang lain, lihat pesan terhapus) = hanya akun pemilik (Owner, coffelatte314). */

    public static function isModerator(int $userId): bool
    {
        return \VC\Security\Owner::isId($userId);
    }

    /**
     * Pesan terbaru (urut lama → baru). Pesan terhapus: isi disembunyikan untuk pengguna biasa,
     * moderator (ROOT/Kepala Sekolah) tetap melihat isi asli + siapa yang menghapus.
     */
    public static function getMessages(int $conversationId, int $userId, int $limit = 50, int $sinceId = 0): array
    {
        if (!self::canAccess($conversationId, $userId)) {
            return [];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT m.id, m.conversation_id, m.sender_id, m.message_text, m.created_at, m.deleted_at, m.deleted_by,
                u.username, u.full_name, u.role, prof.avatar_color, d.full_name AS deleted_by_name
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles prof ON u.id = prof.user_id
            LEFT JOIN users d ON d.id = m.deleted_by
            WHERE m.conversation_id = ? AND m.id > ?
            ORDER BY m.id ' . ($sinceId > 0 ? 'ASC' : 'DESC') . ' LIMIT ?');
        $stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(2, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(3, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($sinceId <= 0) {
            $rows = array_reverse($rows);
        }
        return self::maskDeleted($rows, self::isModerator($userId));
    }

    private static function maskDeleted(array $rows, bool $moderator): array
    {
        foreach ($rows as &$r) {
            $r['is_deleted'] = !empty($r['deleted_at']) ? 1 : 0;
            if ($r['is_deleted'] && !$moderator) {
                $r['message_text'] = '';
                unset($r['deleted_by_name']);
            }
            unset($r['deleted_by']);
        }
        unset($r);
        return $rows;
    }

    /** ID pesan yang dihapus (di antara 200 pesan terakhir) — agar klien yang sedang terbuka ikut menyembunyikan. */
    public static function deletedIds(int $conversationId): array
    {
        $st = Database::get()->prepare('SELECT id FROM (SELECT id, deleted_at FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 200) WHERE deleted_at IS NOT NULL');
        $st->execute([$conversationId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Hapus pesan (soft-delete): pengirim boleh hapus pesannya sendiri, moderator boleh hapus pesan siapa pun. */
    public static function deleteMessage(int $messageId, int $userId): array
    {
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT id, conversation_id, sender_id, deleted_at FROM messages WHERE id = ?');
        $st->execute([$messageId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m || !self::canAccess((int)$m['conversation_id'], $userId)) {
            return ['status' => 'error', 'message' => 'Pesan tidak ditemukan.'];
        }
        $mod = self::isModerator($userId);
        if ((int)$m['sender_id'] !== $userId && !$mod) {
            return ['status' => 'error', 'message' => 'Kamu hanya bisa menghapus pesanmu sendiri.'];
        }
        if (!empty($m['deleted_at'])) {
            return ['status' => 'success', 'message' => 'Pesan sudah dihapus.'];
        }
        $pdo->prepare('UPDATE messages SET deleted_at = datetime("now"), deleted_by = ? WHERE id = ?')->execute([$userId, $messageId]);
        AuditLog::log('chat_message_delete', ['message_id' => $messageId, 'conversation_id' => (int)$m['conversation_id'], 'by_moderator' => $mod && (int)$m['sender_id'] !== $userId], $userId);
        return ['status' => 'success', 'message' => 'Pesan dihapus.'];
    }

    public static function sendMessage(int $conversationId, int $senderId, string $text): array
    {
        $cleanText = trim($text);
        if ($cleanText === '') {
            return ['status' => 'error', 'message' => 'Pesan tidak boleh kosong.'];
        }

        $pdo = Database::get();
        $convStmt = $pdo->prepare('SELECT type, channel_tag FROM conversations WHERE id = ?');
        $convStmt->execute([$conversationId]);
        $conv = $convStmt->fetch(PDO::FETCH_ASSOC);

        if (!$conv || !self::canAccess($conversationId, $senderId)) {
            return ['status' => 'error', 'message' => 'Ruang obrolan tidak ditemukan.'];
        }

        // Focus mode enforcement: during active lessons, general public channels are locked
        if ($conv['type'] === 'channel' && ($conv['channel_tag'] ?? '') === 'general') {
            if (self::isFocusModeActive()) {
                // Check if user is staff/teacher to allow teacher announcements
                $uStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
                $uStmt->execute([$senderId]);
                $role = $uStmt->fetchColumn();
                if (!in_array($role, ['guru', 'wali_kelas', 'kepala_program', 'root'], true)) {
                    return [
                        'status' => 'error',
                        'message' => 'Mode Fokus aktif: Obrolan santai terkunci selama jam pelajaran berlangsung agar siswa fokus belajar.'
                    ];
                }
            }
        }

        $stmt = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, message_text, created_at)
            VALUES (?, ?, ?, datetime("now"))');
        $stmt->execute([$conversationId, $senderId, $cleanText]);
        $msgId = (int)$pdo->lastInsertId();

        AuditLog::log('chat_message_send', ['conversation_id' => $conversationId, 'message_id' => $msgId], $senderId);

        return [
            'status' => 'success',
            'message_id' => $msgId,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
}
