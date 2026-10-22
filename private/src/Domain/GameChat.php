<?php
// GameChat — chat multiplayer in-game (global / area / private) untuk dunia 3D & Pixel.
// Satu jalur validasi: keduanya lewat REST lokal WS relay & klien langsung memakai
// method ini. Pesan SELALU disimpan di database resmi (`game_chat_messages`);
// identitas dari sesi/token server — never trust client.

declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use PDO;

final class GameChat
{
    public const SCOPES = ['global', 'area', 'private'];
    public const MODES = [
        'global'             => ['global'],
        'global_area'        => ['global', 'area'],
        'global_area_private'=> ['global', 'area', 'private'],
    ];
    private const MAX_TEXT = 280;
    public const AREA_RADIUS = 55.0;

    /** Mode penuh boleh menyembunyikan scope; scope dinyatakan di settings `chat_mode`. */
    private static ?array $cache = null;

    public static function settings(bool $refresh = false): array
    {
        if (self::$cache !== null && !$refresh) {
            return self::$cache;
        }
        $pdo = Database::get();
        $rows = $pdo->query("SELECT key, value FROM settings WHERE key IN
            ('chat_enabled','chat_mode','chat_cooldown','chat_filter','chat_blocklist')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $mode = (string)($rows['chat_mode'] ?? 'global_area_private');
        if (!isset(self::MODES[$mode])) $mode = 'global_area_private';
        $cooldown = max(1, min(30, (int)($rows['chat_cooldown'] ?? 2)));
        self::$cache = [
            'enabled'    => ($rows['chat_enabled'] ?? '1') === '1',
            'mode'       => $mode,
            'scopes'     => self::MODES[$mode],
            'cooldown'   => $cooldown,
            'filter'     => ($rows['chat_filter'] ?? '1') === '1',
            'blocklist'  => array_values(array_filter(array_map('trim', explode(',', (string)($rows['chat_blocklist'] ?? ''))))),
        ];
        return self::$cache;
    }

    public static function saveSettings(int $actorId, array $input): array
    {
        $enabled = !empty($input['enabled']);
        $mode = (string)($input['mode'] ?? 'global_area_private');
        if (!isset(self::MODES[$mode])) {
            return ['status' => 'error', 'message' => 'Mode chat tidak dikenal.'];
        }
        $cooldown = max(1, min(30, (int)($input['cooldown'] ?? 2)));
        $filter = !empty($input['filter']);
        $blocklist = substr(trim((string)($input['blocklist'] ?? '')), 0, 2000);

        $pdo = Database::get();
        $stmt = $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')");
        foreach ([
            'chat_enabled' => $enabled ? '1' : '0',
            'chat_mode' => $mode,
            'chat_cooldown' => (string)$cooldown,
            'chat_filter' => $filter ? '1' : '0',
            'chat_blocklist' => $blocklist,
        ] as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        self::$cache = null; // Anywhere invalidation so the relay/settings GET refreshed instantly.
        AuditLog::log('chat_settings_save', ['enabled' => $enabled, 'mode' => $mode], $actorId);
        return ['status' => 'ok'];
    }

    private static function applyFilter(string $text, array $blocklist): string
    {
        foreach ($blocklist as $word) {
            if ($word === '' || mb_strlen($word) < 2) continue;
            $pattern = '/(?i)(?<![\w])' . preg_quote($word, '/') . '(?![\w])/u';
            $text = preg_replace($pattern, str_repeat('*', mb_strlen($word)), $text) ?? $text;
        }
        return $text;
    }

    /** Verifikasi scope_key ATAU aturan scope + identitas private. */
    private static function checkScope(int $userId, string $scope, string $scopeKey): array
    {
        $s = self::settings();
        if (!$s['enabled']) {
            return ['status' => 'error', 'message' => 'Chat sedang dinonaktifkan oleh admin sekolah.'];
        }
        if (!in_array($scope, self::SCOPES, true)) {
            return ['status' => 'error', 'message' => 'Scope chat tidak dikenal.'];
        }
        if (!in_array($scope, $s['scopes'], true)) {
            return ['status' => 'error', 'message' => 'Mode chat saat ini tidak mengizinkan kanal ini.'];
        }
        if ($scope === 'area') {
            if ($scopeKey === '' || Gameplay::locationName($scopeKey) === null) {
                return ['status' => 'error', 'message' => 'Lokasi area chat tidak sah.'];
            }
            return ['status' => 'ok'];
        }
        if ($scope === 'private') {
            $ids = array_map('intval', array_pad(explode('-', $scopeKey), 2, 0));
            sort($ids);
            $myId = (int)$userId;
            if ($ids[0] <= 0 || $ids[0] === $ids[1] || ($ids[0] !== $myId && $ids[1] !== $myId)) {
                return ['status' => 'error', 'message' => 'Kanal privat tidak sah.'];
            }
            $canonical = $ids[0] . '-' . $ids[1];
            return ['status' => 'ok', 'canonical_key' => $canonical];
        }
        return ['status' => 'ok'];
    }

    /** Kirim pesan (server-side). scopeKey: lokasi untuk area; 'a-b' untuk private; '' global. */
    public static function send(int $userId, string $scope, string $scopeKey, string $text, ?string $username = null, ?string $role = null): array
    {
        $text = trim(str_replace(["\r", "\n", "\0"], ' ', $text));
        if ($text === '') {
            return ['status' => 'error', 'message' => 'Pesan tidak boleh kosong.'];
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            return ['status' => 'error', 'message' => 'Pesan maksimal ' . self::MAX_TEXT . ' karakter.'];
        }

        $pdo = Database::get();
        if ($username === null) {
            $uStmt = $pdo->prepare('SELECT username, role FROM users WHERE id = ? AND deleted_at IS NULL');
            $uStmt->execute([$userId]);
            $u = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) return ['status' => 'error', 'message' => 'Pengguna tidak ditemukan.'];
            $username = (string)$u['username'];
            $role = (string)$u['role'];
            if ($role === 'tamu') {
                $fn = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
                $fn->execute([$userId]);
                $username = (string)($fn->fetchColumn() ?: $username); // tamu: nama asli, bukan tamu_xxx
            }
        }
        $username = substr((string)$username, 0, 32);
        $role = in_array((string)$role, ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru', 'pembina', 'pengurus', 'murid'], true)
            ? (string)$role : 'murid';

        $scopeVal = self::checkScope($userId, $scope, $scopeKey);
        if (($scopeVal['status'] ?? '') !== 'ok') {
            return $scopeVal;
        }
        if ($scope === 'private') {
            $scopeKey = (string)$scopeVal['canonical_key'];
        }

        // Cooldown per pengguna (staff dikecualikan dari throttle panjang).
        $s = self::settings();
        $isStaff = in_array($role, ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'], true);
        $cooldown = $isStaff ? 1 : $s['cooldown'];
        $last = $pdo->prepare('SELECT MAX(created_at) FROM game_chat_messages WHERE user_id = ?');
        $last->execute([$userId]);
        if (($lastTs = $last->fetchColumn()) !== null) {
            if (time() - strtotime((string)$lastTs) < $cooldown) {
                return ['status' => 'error', 'message' => 'Terlalu cepat. Tunggu ' . $cooldown . ' detik lagi.'];
            }
        }

        if ($s['filter'] && !empty($s['blocklist'])) {
            $text = self::applyFilter($text, $s['blocklist']);
        }

        $stmt = $pdo->prepare("INSERT INTO game_chat_messages (scope, scope_key, user_id, username, role, text, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'))");
        // Global per-room: kunci "srv-<id>" (chat kelas tidak tercampur room lain); '' = global lama.
        $globalKey = preg_match('/^srv-\d{1,9}$/', $scopeKey) ? $scopeKey : '';
        $stmt->execute([$scope, $scope !== 'global' ? $scopeKey : $globalKey, $userId, $username, $role, $text]);
        $msgId = $pdo->lastInsertId();
        DailyQuest::report($userId, 'chat', (string)$msgId); // misi harian "sapa teman"

        return ['status' => 'ok', 'id' => (int)$msgId, 'created_at' => date('Y-m-d H:i:s')];
    }

    /** Kunci chat global per-room bila user boleh melihat room tsb; null = ditolak. */
    public static function roomKeyFor(int $userId, int $serverId, string $role = ''): ?string
    {
        if ($serverId <= 0) return '';
        $st = Database::get()->prepare('SELECT 1 FROM game_servers s
            LEFT JOIN game_server_members m ON m.server_id = s.id AND m.user_id = ?
            WHERE s.id = ? AND s.is_active = 1 AND (s.access = "open" OR s.owner_id = ? OR m.user_id IS NOT NULL OR ? = "root")');
        $st->execute([$userId, $serverId, $userId, $role]);
        return $st->fetchColumn() ? 'srv-' . $serverId : null;
    }

    /** Riwayat pesan. Private: hanya peserta. Area: ID lokasi tervalidasi. */
    public static function fetch(int $userId, string $scope, string $scopeKey, int $beforeId = 0, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $scopeVal = self::checkScope($userId, $scope, $scopeKey);
        if (($scopeVal['status'] ?? '') !== 'ok') {
            return ['status' => 'error', 'message' => $scopeVal['message']];
        }
        if ($scope === 'private') {
            $scopeKey = (string)$scopeVal['canonical_key'];
        }

        $pdo = Database::get();
        $orderAsc = $beforeId > 0;
        $sql = 'SELECT id, scope, scope_key, user_id, username, role, text, created_at,
                (deleted_at IS NOT NULL) AS deleted
            FROM game_chat_messages WHERE scope = ? AND deleted_at IS NULL';
        $params = [$scope];
        if ($scope === 'global') {
            $sql .= ' AND scope_key = ?';
            $params[] = preg_match('/^srv-\d{1,9}$/', $scopeKey) ? $scopeKey : '';
        } else {
            $sql .= ' AND scope_key = ?';
            $params[] = $scope !== 'global' ? $scopeKey : $scopeKey;
        }
        if ($beforeId > 0) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        // Nama asli pengirim (chat menampilkan nama, bukan username teknis).
        $names = [];
        $uids = array_values(array_unique(array_map(fn($x) => (int)$x['user_id'], $rows)));
        if ($uids) {
            $in = implode(',', array_fill(0, count($uids), '?'));
            $st = $pdo->prepare("SELECT id, full_name FROM users WHERE id IN ($in)");
            $st->execute($uids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) { $names[(int)$u['id']] = (string)($u['full_name'] ?? ''); }
        }
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['user_id'] = (int)$r['user_id'];
            $r['full_name'] = $names[$r['user_id']] ?? '';
            $r['deleted'] = ($r['deleted'] === 1 || $r['deleted'] === true);
        }
        unset($r);
        return ['status' => 'ok', 'messages' => $rows];
    }

    /** Pesan deterministik terbaru sejak id untuk polling klien. */
    public static function fetchFor(int $userId, string $scope, string $scopeKey, int $sinceId = 0, int $limit = 50): array
    {
        $scopeVal = self::checkScope($userId, $scope, $scopeKey);
        if (($scopeVal['status'] ?? '') !== 'ok') {
            return $scopeVal;
        }
        if ($scope === 'private') {
            $scopeKey = (string)$scopeVal['canonical_key'];
        }
        $pdo = Database::get();
        // full_name ikut dikirim agar chat menampilkan nama asli (bukan username teknis).
        $stmt = $pdo->prepare('SELECT m.id, m.user_id, m.username, m.role, m.text, m.created_at, u.full_name
            FROM game_chat_messages m LEFT JOIN users u ON u.id = m.user_id
            WHERE m.scope = ? AND (m.scope_key = ? OR ? = "")
            AND m.id > ? AND m.deleted_at IS NULL ORDER BY m.id ASC LIMIT ' . (int)min(100, max(1, $limit)));
        $stmt->execute([$scope, $scopeKey, $scope !== 'global' ? $scopeKey : '', $sinceId]);
        return ['status' => 'ok', 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Moderasi staf: soft-delete pesan. */
    public static function moderate(int $staffId, int $messageId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare("UPDATE game_chat_messages SET deleted_at = datetime('now'), deleted_by = ?
            WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$staffId, $messageId]);
        if ($stmt->rowCount() === 0) {
            return ['status' => 'error', 'message' => 'Pesan tidak ditemukan atau sudah dihapus.'];
        }
        AuditLog::log('chat_moderate_delete', ['message_id' => $messageId], $staffId);
        return ['status' => 'ok'];
    }

    /** Pesan terbaru lintas-scope untuk antrean moderasi dashboard. */
    public static function adminRecent(int $limit = 60): array
    {
        $limit = max(1, min(200, $limit));
        // Termasuk pesan yang sudah dihapus: moderator tetap bisa melihat isi & siapa yang menghapus.
        $stmt = Database::get()->prepare('SELECT m.id, m.scope, m.scope_key, m.user_id, m.username, m.role, m.text, m.created_at, m.deleted_at,
                COALESCE(d.full_name, d.username) AS deleted_by_name
            FROM game_chat_messages m LEFT JOIN users d ON d.id = m.deleted_by ORDER BY m.id DESC LIMIT ' . $limit);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
