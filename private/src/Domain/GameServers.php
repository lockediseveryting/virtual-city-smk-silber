<?php
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\Auth;

final class GameServers
{
    public const STAFF_ROLES = ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru', 'pembina', 'pengurus'];

    public static function liveCounts(): array
    {
        $counts = [];
        $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
        $raw = @file_get_contents('http://127.0.0.1:8090/status', false, $ctx);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data['servers'] ?? null)) {
                foreach ($data['servers'] as $sid => $n) {
                    $counts[(int)$sid] = (int)$n;
                }
            }
        }
        return $counts;
    }

    public static function listFor(int $userId, string $role, ?string $world = null): array
    {
        $pdo = Database::get();
        $counts = self::liveCounts();
        $stmt = $pdo->prepare('SELECT server_id FROM game_server_members WHERE user_id = ?');
        $stmt->execute([$userId]);
        $memberIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $sql = 'SELECT s.id, s.name, s.description, s.type, s.class_id, s.owner_id, s.capacity, s.access,
                       s.invite_code, s.is_active, s.world, c.name AS class_name
                FROM game_servers s
                LEFT JOIN classes c ON c.id = s.class_id
                WHERE s.is_active = 1
                  AND (? IS NULL OR s.world = ?)
                  AND (s.type = "public" OR s.type = "event" OR s.access = "open" OR s.owner_id = ?
                       OR s.id IN (SELECT server_id FROM game_server_members WHERE user_id = ?)
                       OR (s.type = "class" AND s.class_id IN (SELECT class_id FROM class_members WHERE user_id = ?))
                       OR (s.type = "class" AND s.class_id IN (SELECT id FROM classes WHERE wali_kelas_user_id = ?)))
                ORDER BY s.type = "public" DESC, s.type = "event" DESC, s.id ASC';
        $stmt = $pdo->prepare($sql);
        $w = ($world === 'pixel' || $world === '3d') ? $world : null;
        $stmt->execute([$w, $w, $userId, $userId, $userId, $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $sid = (int)$r['id'];
            $online = $counts[$sid] ?? 0;
            $stmtM = $pdo->prepare('SELECT COUNT(*) FROM game_server_members WHERE server_id = ?');
            $stmtM->execute([$sid]);
            $out[] = [
                'id' => $sid,
                'name' => (string)$r['name'],
                'description' => (string)$r['description'],
                'type' => (string)$r['type'],
                'world' => self::worldOf($r),
                'class_name' => $r['class_name'] ?? null,
                'capacity' => (int)$r['capacity'],
                'access' => (string)$r['access'],
                'needs_code' => $r['access'] === 'invite' && (int)$r['owner_id'] !== $userId && !in_array($sid, $memberIds, true) && $role !== 'root',
                'is_owner' => (int)$r['owner_id'] === $userId,
                // kode hanya untuk pemilik / ROOT / kepala sekolah (untuk tombol Bagikan)
                'invite_code' => ((int)$r['owner_id'] === $userId || in_array($role, ['root', 'kepala_sekolah'], true)) ? (string)$r['invite_code'] : null,
                'member_count' => (int)$stmtM->fetchColumn(),
                'online' => $online,
                'status' => $online > 0 ? 'live' : 'idle',
            ];
        }
        return $out;
    }

    public static function join(int $userId, int $serverId, string $code = ''): array
    {
        $pdo = Database::get();
        $role = Auth::user()['role'] ?? 'murid';
        $stmt = $pdo->prepare('SELECT id, name, type, class_id, owner_id, capacity, access, invite_code, is_active, world
                               FROM game_servers WHERE id = ? AND is_active = 1');
        $stmt->execute([$serverId]);
        $s = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($s === false) {
            return ['status' => 'error', 'http' => 404, 'message' => 'Server tidak ditemukan.'];
        }
        if ((string)$s['access'] === 'invite') {
            $isMember = $pdo->prepare('SELECT 1 FROM game_server_members WHERE server_id = ? AND user_id = ?');
            $isMember->execute([$serverId, $userId]);
            if ((int)$s['owner_id'] !== $userId && $role !== 'root' && !$isMember->fetchColumn()) {
                if (trim($code) === '' || strcasecmp(trim($code), (string)$s['invite_code']) !== 0) {
                    return ['status' => 'error', 'http' => 403, 'message' => 'Kode undangan salah atau tidak diisi.'];
                }
            }
        }
        if ((string)$s['type'] === 'class' && (int)$s['class_id'] > 0) {
            $inClass = $pdo->prepare('SELECT 1 FROM class_members WHERE user_id = ? AND class_id = ?');
            $inClass->execute([$userId, (int)$s['class_id']]);
            $isOwner = (int)$s['owner_id'] === $userId;
            $waliStmt = $pdo->prepare('SELECT 1 FROM classes WHERE id = ? AND wali_kelas_user_id = ?');
            $waliStmt->execute([(int)$s['class_id'], $userId]);
            $isWali = (bool)$waliStmt->fetchColumn();
            $codeMatches = (trim($code) !== '' && strcasecmp(trim($code), (string)$s['invite_code']) === 0);
            // Tamu gabung-cepat: sudah jadi anggota room lewat kode di /gabung (tanpa masuk roster kelas).
            $guestMember = false;
            if ($role === 'tamu') {
                $gm = $pdo->prepare('SELECT 1 FROM game_server_members WHERE server_id = ? AND user_id = ?');
                $gm->execute([$serverId, $userId]);
                $guestMember = (bool)$gm->fetchColumn();
            }
            if (!$inClass->fetchColumn() && !$isOwner && !$isWali && $role !== 'root' && !$codeMatches && !$guestMember) {
                return ['status' => 'error', 'http' => 403, 'message' => 'Server kelas ini khusus anggota kelas.'];
            }
            if ($codeMatches && $role !== 'tamu' && !$inClass->fetchColumn()) { // tamu tidak masuk roster kelas
                $pdo->prepare('INSERT OR IGNORE INTO class_members (class_id, user_id) VALUES (?, ?)')->execute([(int)$s['class_id'], $userId]);
            }
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM game_server_members WHERE server_id = ?');
        $cnt->execute([$serverId]);
        if ((int)$cnt->fetchColumn() >= (int)$s['capacity']) {
            $already = $pdo->prepare('SELECT 1 FROM game_server_members WHERE server_id = ? AND user_id = ?');
            $already->execute([$serverId, $userId]);
            if (!$already->fetchColumn()) {
                return ['status' => 'error', 'http' => 409, 'message' => 'Server penuh (kapasitas ' . (int)$s['capacity'] . ').'];
            }
        }
        $pdo->prepare('INSERT OR IGNORE INTO game_server_members (server_id, user_id) VALUES (?, ?)')
            ->execute([$serverId, $userId]);
        return ['status' => 'ok', 'server_id' => $serverId, 'name' => (string)$s['name'], 'world' => self::worldOf($s)];
    }

    /** Satu server = satu dunia: '3d' (/dunia) atau 'pixel' (/pixel). */
    public static function worldOf(array $s): string
    {
        return (($s['world'] ?? '3d') === 'pixel') ? 'pixel' : '3d';
    }

    /** Halaman dunia yang benar untuk server ini. */
    public static function worldUrl(string $world, int $serverId): string
    {
        return ($world === 'pixel' ? '/pixel' : '/dunia') . '?server=' . $serverId;
    }

    /** Link bagikan server: pakai kode → /gabung?kode=…, terbuka → langsung ke dunianya. */
    public static function shareUrl(array $s): string
    {
        $code = (string)($s['invite_code'] ?? '');
        if (($s['access'] ?? '') === 'invite' && $code !== '') {
            return '/gabung?kode=' . rawurlencode($code);
        }
        return self::worldUrl(self::worldOf($s), (int)$s['id']);
    }

    /** Dunia milik server (null bila server tidak aktif / tidak ada). */
    public static function worldOfId(int $serverId): ?string
    {
        $st = Database::get()->prepare('SELECT world FROM game_servers WHERE id = ? AND is_active = 1');
        $st->execute([$serverId]);
        $w = $st->fetchColumn();
        return $w === false ? null : self::worldOf(['world' => $w]);
    }

    public static function joinByCode(int $userId, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['status' => 'error', 'http' => 400, 'message' => 'Kode kelas tidak boleh kosong.'];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM game_servers WHERE UPPER(invite_code) = UPPER(?) AND is_active = 1 LIMIT 1');
        $stmt->execute([$code]);
        $sid = $stmt->fetchColumn();
        if (!$sid) {
            return ['status' => 'error', 'http' => 404, 'message' => 'Server atau kelas dengan kode "' . htmlspecialchars($code, ENT_QUOTES) . '" tidak ditemukan.'];
        }
        return self::join($userId, (int)$sid, $code);
    }

    /**
     * Hapus (nonaktifkan) server game — root, kepala sekolah, atau guru pemilik server.
     * Server "public" utama sekolah tidak boleh dihapus. Soft-delete:
     * is_active=0 + bersihkan anggota + kick semua klien WS di room server tsb.
     */
    /**
     * Gabung cepat tanpa akun (arahan Pak Agus 14-10-2026): kode room + nama.
     * Membuat user sementara ber-role "tamu" (password tidak bisa dipakai login),
     * atau memakai ulang tamu dari sesi yang sama. Hanya room aktif ber-kode.
     */
    public static function guestJoin(string $code, string $name, ?int $existingGuestId = null): array
    {
        $code = strtoupper(trim($code));
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        if ($code === '' || !preg_match('/^[A-Z0-9]{3,12}$/', $code)) {
            return ['status' => 'error', 'http' => 400, 'message' => 'Kode room tidak valid.'];
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return ['status' => 'error', 'http' => 400, 'message' => 'Nama harus 2–40 karakter.'];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, name, capacity, world FROM game_servers WHERE UPPER(invite_code) = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$code]);
        $s = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($s === false) {
            return ['status' => 'error', 'http' => 404, 'message' => 'Room dengan kode "' . $code . '" tidak ditemukan atau sudah ditutup.'];
        }
        $sid = (int)$s['id'];

        $uid = null;
        if ($existingGuestId !== null) {
            $chk = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role = "tamu" AND deleted_at IS NULL');
            $chk->execute([$existingGuestId]);
            $uid = $chk->fetchColumn() ? $existingGuestId : null;
        }

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM game_server_members WHERE server_id = ? AND (? IS NULL OR user_id != ?)');
        $cnt->execute([$sid, $uid, $uid]);
        if ((int)$cnt->fetchColumn() >= (int)$s['capacity']) {
            return ['status' => 'error', 'http' => 409, 'message' => 'Room penuh (kapasitas ' . (int)$s['capacity'] . ').'];
        }

        if ($uid === null) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? '');
            $slug = trim(substr($slug, 0, 16), '_') ?: 'siswa';
            $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, status, created_at)
                           VALUES (?, ?, ?, "tamu", ?, "active", CURRENT_TIMESTAMP)')
                ->execute([bin2hex(random_bytes(16)), 'tamu_' . $slug . '_' . bin2hex(random_bytes(3)), '!guest-no-login', $name]);
            $uid = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$name, $uid]);
        }
        $pdo->prepare('INSERT OR IGNORE INTO game_server_members (server_id, user_id) VALUES (?, ?)')->execute([$sid, $uid]);
        return ['status' => 'ok', 'user_id' => $uid, 'server_id' => $sid, 'name' => (string)$s['name'], 'world' => self::worldOf($s)];
    }

    public static function deleteServer(int $serverId, array $user): array
    {
        $role = (string)($user['role'] ?? '');
        $userId = (int)($user['id'] ?? 0);
        $isRoot = in_array($role, ['root', 'kepala_sekolah'], true);

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, name, type, owner_id, is_active FROM game_servers WHERE id = ?');
        $stmt->execute([$serverId]);
        $s = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($s === false) {
            return ['status' => 'error', 'http' => 404, 'message' => 'Server tidak ditemukan.'];
        }
        if (!$isRoot && (int)$s['owner_id'] !== $userId) {
            return ['status' => 'error', 'http' => 403, 'message' => 'Hanya ROOT atau pembuat server yang dapat menghapus server ini.'];
        }
        if ((int)$s['is_active'] !== 1) {
            return ['status' => 'error', 'http' => 409, 'message' => 'Server sudah dihapus/nonaktif.'];
        }
        if ((string)$s['type'] === 'public' && $serverId === 1) {
            return ['status' => 'error', 'http' => 403, 'message' => 'Server publik utama sekolah tidak dapat dihapus.'];
        }
        $pdo->prepare('UPDATE game_servers SET is_active = 0 WHERE id = ?')->execute([$serverId]);
        $pdo->prepare('DELETE FROM game_server_members WHERE server_id = ?')->execute([$serverId]);
        // Beri tahu WS server agar kick semua klien room server ini (best-effort).
        $kicked = self::notifyWsServerDeleted($serverId);
        \VC\Security\AuditLog::log('game_server_delete', ['server_id' => $serverId, 'name' => (string)$s['name']], $userId, 'ok');
        return ['status' => 'ok', 'server_id' => $serverId, 'name' => (string)$s['name'], 'ws_kicked' => $kicked, 'message' => 'Server berhasil dihapus.'];
    }

    /**
     * Update (edit) server game — root, kepala sekolah, atau guru pemilik server.
     */
    public static function updateServer(int $serverId, array $in, array $user): array
    {
        $role = (string)($user['role'] ?? '');
        $userId = (int)($user['id'] ?? 0);
        $isRoot = in_array($role, ['root', 'kepala_sekolah'], true);

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, name, type, class_id, owner_id, capacity, access, invite_code, description, is_active FROM game_servers WHERE id = ?');
        $stmt->execute([$serverId]);
        $s = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($s === false) {
            return ['status' => 'error', 'http' => 404, 'message' => 'Server tidak ditemukan.'];
        }
        if (!$isRoot && (int)$s['owner_id'] !== $userId) {
            return ['status' => 'error', 'http' => 403, 'message' => 'Hanya ROOT atau pembuat server yang dapat mengedit server ini.'];
        }

        $name = trim((string)($in['name'] ?? $s['name']));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60) {
            return ['status' => 'error', 'http' => 400, 'message' => 'Nama server harus 3–60 karakter.'];
        }

        $capacity = max(2, min(150, (int)($in['capacity'] ?? $s['capacity'])));
        $classId = isset($in['class_id']) ? ((int)$in['class_id'] ?: null) : $s['class_id'];
        
        $access = ($in['access'] ?? $s['access']) === 'open' ? 'open' : 'invite';
        $reqType = (string)($in['type'] ?? $s['type']);
        $type = in_array($reqType, ['class', 'public', 'private', 'event'], true) ? $reqType : 'class';
        if (in_array($type, ['public', 'event'], true) && !$isRoot) {
            $type = 'class';
        }

        $desc = trim((string)($in['description'] ?? $s['description']));

        $code = (string)$s['invite_code'];
        if (!empty($in['regen_code'])) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $up = $pdo->prepare('UPDATE game_servers SET name = ?, description = ?, type = ?, class_id = ?, capacity = ?, access = ?, invite_code = ? WHERE id = ?');
        $up->execute([$name, $desc, $type, $classId, $capacity, $access, $code, $serverId]);

        \VC\Security\AuditLog::log('game_server_update', ['server_id' => $serverId, 'name' => $name], $userId, 'ok');

        return [
            'status' => 'ok',
            'server_id' => $serverId,
            'name' => $name,
            'type' => $type,
            'capacity' => $capacity,
            'access' => $access,
            'invite_code' => $code,
            'description' => $desc,
            'message' => 'Server berhasil diperbarui.'
        ];
    }

    /** POST /internal/server-deleted ke WS server (HMAC APP_KEY, skema sama chat relay). */
    private static function notifyWsServerDeleted(int $serverId): int
    {
        $secret = (string)getenv('APP_KEY');
        if ($secret === '') {
            // CLI tidak selalu mewarisi env web — baca dari app.env (pola sama websocket_server.js).
            $envFile = \VC_ROOT . '/private/config/app.env';
            if (is_readable($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
                    if (preg_match('/^APP_KEY=(.+)$/', $line, $m) && trim($m[1]) !== '') {
                        $secret = trim($m[1]);
                        break;
                    }
                }
            }
        }
        if ($secret === '') {
            $secret = 'virtual-city-default-key-silber-2026';
        }
        $payload = json_encode(['serverId' => $serverId]);
        $ts = (string)time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        $ctx = stream_context_create(['http' => [
            'timeout' => 2,
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-VC-Timestamp: {$ts}\r\nX-VC-Signature: {$sig}",
            'content' => $payload,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('http://127.0.0.1:8090/internal/server-deleted', false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return -1;
        }
        $res = json_decode($raw, true);
        return is_array($res) ? (int)($res['kicked'] ?? 0) : -1;
    }

    /** Daftar semua server untuk dashboard ROOT (termasuk nonaktif, dengan online count). */
    /**
     * Server/room yang boleh dikirimi tugas oleh guru: miliknya, server kelas yang ia ajar/walikan,
     * atau semua server aktif untuk staf dengan akses penuh.
     */
    public static function assignableFor(int $userId, bool $isStaffAll): array
    {
        $pdo = Database::get();
        $sql = 'SELECT s.id, s.name, s.invite_code, s.world, s.type, s.class_id, c.name AS class_name,
                       (SELECT COUNT(*) FROM game_server_members m WHERE m.server_id = s.id) AS member_count
                FROM game_servers s LEFT JOIN classes c ON c.id = s.class_id
                WHERE s.is_active = 1';
        $params = [];
        if (!$isStaffAll) {
            $sql .= ' AND (s.owner_id = ?
                      OR s.class_id IN (SELECT id FROM classes WHERE wali_kelas_user_id = ?)
                      OR s.class_id IN (SELECT class_id FROM schedule_entries WHERE teacher_user_id = ?))';
            $params = [$userId, $userId, $userId];
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY s.type = "public" ASC, s.id DESC');
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function canAssign(int $userId, int $serverId, bool $isStaffAll): ?array
    {
        foreach (self::assignableFor($userId, $isStaffAll) as $s) {
            if ((int)$s['id'] === $serverId) return $s;
        }
        return null;
    }

    /** ID anggota server (penerima tugas server). */
    public static function memberIds(int $serverId): array
    {
        $st = Database::get()->prepare('SELECT user_id FROM game_server_members WHERE server_id = ?');
        $st->execute([$serverId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function adminListAll(): array
    {
        $pdo = Database::get();
        $counts = self::liveCounts();
        $stmt = $pdo->prepare('SELECT s.id, s.name, s.description, s.type, s.class_id, s.owner_id, s.capacity,
                                      s.access, s.invite_code, s.is_active, s.world, u.full_name AS owner_name
                               FROM game_servers s
                               LEFT JOIN users u ON u.id = s.owner_id
                               ORDER BY s.is_active DESC, s.id ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $sid = (int)$r['id'];
            $stmtM = $pdo->prepare('SELECT COUNT(*) FROM game_server_members WHERE server_id = ?');
            $stmtM->execute([$sid]);
            $out[] = [
                'id' => $sid,
                'name' => (string)$r['name'],
                'type' => (string)$r['type'],
                'world' => self::worldOf($r),
                'access' => (string)$r['access'],
                'invite_code' => (string)$r['invite_code'],
                'capacity' => (int)$r['capacity'],
                'owner_name' => $r['owner_name'] ?? '-',
                'is_active' => (int)$r['is_active'] === 1,
                'member_count' => (int)$stmtM->fetchColumn(),
                'online' => $counts[$sid] ?? 0,
            ];
        }
        return $out;
    }

    public static function create(int $userId, array $in): array
    {
        $role = Auth::user()['role'] ?? null;
        if ($role === null) {
            $pdo = Database::get();
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $role = (string)($stmt->fetchColumn() ?: 'murid');
        }
        if (!in_array($role, self::STAFF_ROLES, true)) {
            return ['status' => 'error', 'http' => 403, 'message' => 'Hanya staf & guru yang dapat membuat server kelas.'];
        }
        $name = trim((string)($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60) {
            return ['status' => 'error', 'http' => 400, 'message' => 'Nama server 3–60 karakter.'];
        }
        $capacity = max(2, min(150, (int)($in['capacity'] ?? 30)));
        $classId = (int)($in['class_id'] ?? 0) ?: null;
        // Dulu guru hanya boleh menautkan rombel yang ia walikan → hampir semua guru mapel gagal
        // membuat server ("error") karena dropdown menawarkan semua rombel. Kini semua staf pengajar
        // boleh menautkan rombel aktif mana pun; server kelas tetap hanya untuk anggota rombel/kode.
        if ($classId !== null) {
            $pdo = Database::get();
            $stmt = $pdo->prepare('SELECT 1 FROM classes WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$classId]);
            if (!$stmt->fetchColumn()) {
                return ['status' => 'error', 'http' => 400, 'message' => 'Kelas tidak ditemukan.'];
            }
        }
        $access = ($in['access'] ?? 'invite') === 'open' ? 'open' : 'invite';
        $reqType = (string)($in['type'] ?? 'class');
        $type = in_array($reqType, ['class', 'public', 'private', 'event'], true) ? $reqType : 'class';
        if (in_array($type, ['public', 'event'], true) && $role !== 'root' && $role !== 'kepala_sekolah') {
            $type = 'class';
        }
        $world = (($in['world'] ?? '3d') === 'pixel') ? 'pixel' : '3d'; // dipilih saat dibuat, tidak bisa diganti
        $pdo = Database::get();
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $pdo->prepare('INSERT INTO game_servers (name, description, type, class_id, owner_id, capacity, access, invite_code, world)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, trim((string)($in['description'] ?? '')), $type, $classId, $userId, $capacity, $access, $code, $world]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT OR IGNORE INTO game_server_members (server_id, user_id) VALUES (?, ?)')
            ->execute([$sid, $userId]);
        \VC\Security\AuditLog::log('server_create', ['server_id' => $sid, 'class_id' => $classId, 'type' => $type, 'world' => $world], $userId);
        return ['status' => 'ok', 'server_id' => $sid, 'invite_code' => $code, 'name' => $name, 'world' => $world];
    }
}
