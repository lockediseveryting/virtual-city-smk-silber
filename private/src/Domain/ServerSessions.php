<?php
// Jadwal KUMPUL per server game: guru / ROOT menentukan jam pelajaran + ruangan; saat jamnya tiba,
// semua murid di server itu (3D & Pixel) mendapat panggilan "Kumpul di …" + navigasi ke ruangan,
// dan kehadiran tercatat begitu mereka sampai di ruangan.
// room_id = ID lokasi resmi (public/assets/map/pixel-side.json, mis. "ruang-8", "lab-tik-atas").
// Waktu memakai zona sekolah (Schedule::nowLocal), server berjalan UTC.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;

final class ServerSessions
{
    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
    private const EARLY_MIN = 10; // panggilan muncul 10 menit sebelum mulai

    /** Daftar lokasi resmi: id => [name, building, floor, campus(x,z), campusRef]. */
    public static function locations(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $file = \VC_PUBLIC . '/assets/map/pixel-side.json';
        $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        $cache = [];
        foreach (($d['locations'] ?? []) as $l) {
            $cache[(string)$l['id']] = ['name' => (string)$l['name'], 'building' => (string)($l['building'] ?? ''), 'floor' => (int)($l['floor'] ?? 1),
                'campusRef' => (string)($l['campusRef'] ?? ''), 'x' => (float)($l['campus']['x'] ?? 0), 'z' => (float)($l['campus']['z'] ?? 0)];
        }
        return $cache;
    }

    public static function canManage(array $user, int $serverId): bool
    {
        $role = (string)($user['role'] ?? '');
        if (in_array($role, ['root', 'kepala_sekolah', 'kepala_program'], true)) {
            return true;
        }
        if (!in_array($role, ['guru', 'wali_kelas'], true)) {
            return false;
        }
        return GameServers::canAssign((int)$user['id'], $serverId, false) !== null;
    }

    public static function forServer(int $serverId): array
    {
        $st = Database::get()->prepare('SELECT s.*, COALESCE(u.full_name, u.username) AS creator FROM server_sessions s LEFT JOIN users u ON u.id = s.created_by
            WHERE s.server_id = ? ORDER BY COALESCE(s.on_date, "9999"), s.day_of_week, s.start_time');
        $st->execute([$serverId]);
        $locs = self::locations();
        return array_map(static function (array $r) use ($locs): array {
            $r['room_name'] = $locs[$r['room_id']]['name'] ?? $r['room_id'];
            $r['when'] = $r['on_date'] ? date('d-m-Y', strtotime((string)$r['on_date'])) : ('Setiap ' . (self::DAYS[(int)$r['day_of_week']] ?? '?'));
            return $r;
        }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function create(array $user, int $serverId, array $in): array
    {
        if (!self::canManage($user, $serverId) || GameServers::worldOfId($serverId) === null) {
            return ['status' => 'error', 'message' => 'Kamu tidak bisa mengatur jadwal server ini.'];
        }
        $title = mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 80);
        $room = (string)($in['room_id'] ?? '');
        $start = (string)($in['start_time'] ?? '');
        $end = (string)($in['end_time'] ?? '');
        $date = trim((string)($in['on_date'] ?? ''));
        $dow = (int)($in['day_of_week'] ?? 0);
        if ($title === '') {
            return ['status' => 'error', 'message' => 'Isi nama pelajaran / kegiatan.'];
        }
        if (!isset(self::locations()[$room])) {
            return ['status' => 'error', 'message' => 'Pilih ruangan kumpul.'];
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $start) {
            return ['status' => 'error', 'message' => 'Jam mulai/selesai tidak valid (selesai harus setelah mulai).'];
        }
        if ($date !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return ['status' => 'error', 'message' => 'Tanggal tidak valid.'];
            }
            $dow = 0;
        } elseif ($dow < 1 || $dow > 7) {
            return ['status' => 'error', 'message' => 'Pilih hari (mingguan) atau tanggal tertentu.'];
        }
        $pdo = Database::get();
        $pdo->prepare('INSERT INTO server_sessions (server_id, title, room_id, day_of_week, on_date, start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$serverId, $title, $room, $dow ?: null, $date ?: null, $start, $end, (int)$user['id']]);
        $id = (int)$pdo->lastInsertId();
        AuditLog::log('server_session_create', ['id' => $id, 'server_id' => $serverId, 'room' => $room], (int)$user['id']);
        // Beritahu anggota server lewat lonceng notifikasi.
        $when = $date !== '' ? date('d-m-Y', strtotime($date)) : 'setiap ' . self::DAYS[$dow];
        Notification::pushMany(GameServers::memberIds($serverId), 'jadwal', '📢 Jadwal kumpul: ' . $title,
            'Kumpul di ' . self::locations()[$room]['name'] . ', ' . $when . ' jam ' . $start . '–' . $end . '.',
            GameServers::worldUrl((string)GameServers::worldOfId($serverId), $serverId), 'gather:' . $id);
        return ['status' => 'ok', 'message' => 'Jadwal kumpul disimpan & anggota server diberi tahu.', 'id' => $id];
    }

    public static function delete(array $user, int $id): array
    {
        $st = Database::get()->prepare('SELECT server_id FROM server_sessions WHERE id = ?');
        $st->execute([$id]);
        $sid = (int)$st->fetchColumn();
        if ($sid <= 0 || !self::canManage($user, $sid)) {
            return ['status' => 'error', 'message' => 'Jadwal tidak ditemukan.'];
        }
        Database::get()->prepare('DELETE FROM server_sessions WHERE id = ?')->execute([$id]);
        AuditLog::log('server_session_delete', ['id' => $id, 'server_id' => $sid], (int)$user['id']);
        return ['status' => 'ok', 'message' => 'Jadwal dihapus.'];
    }

    /** Sesi yang sedang berlangsung / akan mulai ≤ 10 menit di server ini (null bila tidak ada). */
    public static function current(int $serverId): ?array
    {
        $today = Schedule::nowLocal('Y-m-d');
        $dow = (int)Schedule::nowLocal('N');
        $now = Schedule::nowLocal('H:i');
        $soon = date('H:i', strtotime($today . ' ' . $now) + self::EARLY_MIN * 60);
        $st = Database::get()->prepare('SELECT * FROM server_sessions WHERE server_id = ? AND (on_date = ? OR (on_date IS NULL AND day_of_week = ?))
            AND start_time <= ? AND end_time > ? ORDER BY start_time LIMIT 1');
        $st->execute([$serverId, $today, $dow, $soon, $now]);
        $s = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$s) {
            return null;
        }
        $loc = self::locations()[$s['room_id']] ?? null;
        return ['id' => (int)$s['id'], 'title' => (string)$s['title'], 'room_id' => (string)$s['room_id'], 'room_name' => $loc['name'] ?? $s['room_id'],
            'campusRef' => $loc['campusRef'] ?? '', 'x' => $loc['x'] ?? 0, 'z' => $loc['z'] ?? 0, 'floor' => $loc['floor'] ?? 1,
            'start' => (string)$s['start_time'], 'end' => (string)$s['end_time'], 'started' => $s['start_time'] <= $now, 'date' => $today];
    }

    /** Murid tiba di ruangan: catat hadir (sekali per hari). */
    public static function arrive(int $userId, int $sessionId, int $serverId): array
    {
        $cur = self::current($serverId);
        if ($cur === null || $cur['id'] !== $sessionId) {
            return ['status' => 'error', 'message' => 'Tidak ada jadwal kumpul aktif.'];
        }
        $st = Database::get()->prepare('INSERT OR IGNORE INTO server_session_attendance (session_id, user_id, on_date, arrived_at) VALUES (?, ?, ?, ?)');
        $st->execute([$sessionId, $userId, $cur['date'], Schedule::nowLocal('Y-m-d H:i:s')]);
        $late = $cur['started'] && Schedule::nowLocal('H:i') > date('H:i', strtotime($cur['date'] . ' ' . $cur['start']) + 5 * 60);
        return ['status' => 'ok', 'new' => $st->rowCount() > 0, 'late' => $late,
            'message' => $st->rowCount() > 0 ? ('✅ Kamu hadir di ' . $cur['room_name'] . ($late ? ' (terlambat)' : ' tepat waktu') . '!') : 'Kehadiran sudah tercatat.'];
    }

    /** Rekap kehadiran per tanggal untuk satu sesi. */
    public static function attendance(int $sessionId, string $date): array
    {
        $st = Database::get()->prepare('SELECT a.arrived_at, COALESCE(u.full_name, u.username) AS name, c.name AS class_name FROM server_session_attendance a
            JOIN users u ON u.id = a.user_id LEFT JOIN classes c ON c.id = u.class_id WHERE a.session_id = ? AND a.on_date = ? ORDER BY a.arrived_at');
        $st->execute([$sessionId, $date]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
