<?php
// Broadcast: pengumuman pop-up dari konsol admin ke dashboard pengguna (semua / murid / staf).
// Pengguna melihat broadcast aktif yang belum kedaluwarsa; status "sudah dibaca" disimpan
// di perangkat (localStorage), jadi tabel ini cukup satu dan ringan.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;

final class Broadcast
{
    public const STAFF = ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'];
    /** Hanya ROOT yang boleh membuat/menghapus broadcast (keputusan pemilik sistem, 15 Okt 2026). */
    public const MANAGERS = ['root'];
    private const AUDIENCES = ['all', 'murid', 'staf'];
    private const STUDENT_ROLES = ['murid', 'murid_akses', 'tamu', 'pengurus'];

    public static function canManage(array $user): bool
    {
        return in_array($user['role'] ?? '', self::MANAGERS, true);
    }

    private static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        Database::get()->exec('CREATE TABLE IF NOT EXISTS broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            link_url TEXT NOT NULL DEFAULT "",
            link_label TEXT NOT NULL DEFAULT "",
            audience TEXT NOT NULL DEFAULT "all",
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT)');
        $done = true;
    }

    /** Broadcast aktif untuk pengguna ini (terbaru dulu, maks. 5). */
    public static function activeFor(array $user): array
    {
        self::ensureTable();
        $isStaff = !in_array($user['role'] ?? '', self::STUDENT_ROLES, true);
        $st = Database::get()->prepare('SELECT b.id, b.title, b.message, b.link_url, b.link_label, b.created_at,
                COALESCE(u.full_name, u.username, "Admin") AS author
            FROM broadcasts b LEFT JOIN users u ON u.id = b.created_by
            WHERE (b.expires_at IS NULL OR b.expires_at > datetime("now"))
              AND b.audience IN ("all", ?)
            ORDER BY b.id DESC LIMIT 5');
        $st->execute([$isStaff ? 'staf' : 'murid']);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Semua broadcast (untuk konsol admin), termasuk yang sudah kedaluwarsa. */
    public static function all(): array
    {
        self::ensureTable();
        return Database::get()->query('SELECT b.*, COALESCE(u.full_name, u.username, "-") AS author,
                CASE WHEN b.expires_at IS NULL OR b.expires_at > datetime("now") THEN 1 ELSE 0 END AS is_active
            FROM broadcasts b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 50')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array{status:string,message?:string,id?:int} */
    public static function create(array $user, array $in): array
    {
        self::ensureTable();
        $title = trim((string)($in['title'] ?? ''));
        $message = trim((string)($in['message'] ?? ''));
        $linkUrl = trim((string)($in['link_url'] ?? ''));
        $linkLabel = trim((string)($in['link_label'] ?? ''));
        $audience = (string)($in['audience'] ?? 'all');
        $days = (int)($in['days'] ?? 7);

        if ($title === '' || $message === '') {
            return ['status' => 'error', 'message' => 'Judul dan isi pesan wajib diisi.'];
        }
        if (mb_strlen($title) > 80 || mb_strlen($message) > 600) {
            return ['status' => 'error', 'message' => 'Judul maks. 80 karakter, pesan maks. 600 karakter.'];
        }
        if ($linkUrl !== '' && !preg_match('#^(https://|/)[^\s<>"\']*$#i', $linkUrl)) {
            return ['status' => 'error', 'message' => 'Tautan harus diawali https:// atau / (halaman situs ini).'];
        }
        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = 'all';
        }
        $days = max(0, min(90, $days)); // 0 = tanpa batas waktu
        $expires = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;

        $pdo = Database::get();
        $pdo->prepare('INSERT INTO broadcasts (title, message, link_url, link_label, audience, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, $message, $linkUrl, mb_substr($linkLabel, 0, 40), $audience, (int)$user['id'], $expires]);
        $id = (int)$pdo->lastInsertId();
        $notified = self::notify($id, $title, $message, $linkUrl, $audience);
        AuditLog::log('broadcast_create', ['id' => $id, 'title' => $title, 'audience' => $audience, 'notified' => $notified], (int)$user['id']);
        return ['status' => 'ok', 'id' => $id, 'notified' => $notified];
    }

    /** Kirim juga ke lonceng notifikasi semua pengguna aktif sesuai sasaran (murid / staf / semua). */
    private static function notify(int $id, string $title, string $message, string $link, string $audience): int
    {
        $pdo = Database::get();
        $sql = 'SELECT id FROM users WHERE status = "active"';
        $ph = implode(',', array_fill(0, count(self::STUDENT_ROLES), '?'));
        if ($audience === 'murid') {
            $sql .= ' AND role IN (' . $ph . ')';
        } elseif ($audience === 'staf') {
            $sql .= ' AND role NOT IN (' . $ph . ')';
        }
        $st = $pdo->prepare($sql);
        $st->execute($audience === 'all' ? [] : self::STUDENT_ROLES);
        $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        $pdo->beginTransaction();
        try {
            $n = Notification::pushMany($ids, 'sistem', '📢 ' . $title, $message, $link !== '' ? $link : '/', 'broadcast:' . $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $n = 0;
        }
        return $n;
    }

    public static function delete(array $user, int $id): array
    {
        self::ensureTable();
        // Notifikasi broadcast ikut ditarik dari lonceng semua pengguna.
        Database::get()->prepare('DELETE FROM notifications WHERE dedupe_key LIKE ?')->execute(['%:broadcast:' . $id]);
        $st = Database::get()->prepare('DELETE FROM broadcasts WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            return ['status' => 'error', 'message' => 'Broadcast tidak ditemukan.'];
        }
        AuditLog::log('broadcast_delete', ['id' => $id], (int)$user['id']);
        return ['status' => 'ok'];
    }
}
