<?php
// Status online pengguna langsung dari database (tabel user_presence).
// - touch(): dipanggil di setiap request yang login; ditulis maks. 1× per 60 dtk per sesi (hemat tulis SQLite).
// - Online = terlihat ≤ ONLINE_SEC detik terakhir. Halaman game & dashboard mengirim permintaan berkala
//   (polling mabar 15 dtk, jadwal kumpul 45 dtk), jadi pemain yang sedang main tetap terhitung online.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;

final class Presence
{
    public const ONLINE_SEC = 180;

    /** Nama tempat yang ramah dari path halaman. */
    public static function placeOf(string $path): string
    {
        if ($path === '/' || $path === '/admin') {
            return 'Dashboard';
        }
        $map = ['/dunia' => 'Dunia 3D', '/pixel' => 'Dunia Pixel', '/obrolan' => 'Chat', '/profil' => 'Profil', '/jadwal' => 'Jadwal',
            '/tugas' => 'Tugas', '/kelas' => 'Kelas', '/misi' => 'Misi', '/toko' => 'Toko', '/api/party' => 'Mabar'];
        foreach ($map as $p => $name) {
            if (str_starts_with($path, $p)) {
                return $name;
            }
        }
        return '';
    }

    public static function touch(int $userId, string $path): void
    {
        if ($userId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $place = self::placeOf($path);
        $now = time();
        $last = (int)($_SESSION['vc_seen_at'] ?? 0);
        // Halaman (bukan API) dengan tempat baru → catat langsung agar "sedang di …" akurat.
        $changed = $place !== '' && $place !== ($_SESSION['vc_seen_place'] ?? '') && !str_starts_with($path, '/api/');
        if (!$changed && $now - $last < 60) {
            return;
        }
        $_SESSION['vc_seen_at'] = $now;
        if ($place !== '' && !str_starts_with($path, '/api/')) {
            $_SESSION['vc_seen_place'] = $place;
        }
        try {
            Database::get()->prepare('INSERT INTO user_presence (user_id, last_seen, place) VALUES (?, datetime("now"), ?)
                ON CONFLICT(user_id) DO UPDATE SET last_seen = excluded.last_seen, place = CASE WHEN excluded.place = "" THEN user_presence.place ELSE excluded.place END')
                ->execute([$userId, (string)($_SESSION['vc_seen_place'] ?? $place)]);
        } catch (\Throwable $e) {
            // tabel belum dimigrasi → abaikan
        }
    }

    /**
     * Status untuk sekumpulan pengguna.
     * @param int[] $ids
     * @return array<int,array{online:bool,place:string,ago:int|null}>
     */
    public static function statusFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['online' => false, 'place' => '', 'ago' => null];
        }
        if ($ids === []) {
            return $out;
        }
        $st = Database::get()->prepare('SELECT user_id, place, CAST(strftime("%s","now") - strftime("%s", last_seen) AS INTEGER) AS ago
            FROM user_presence WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $ago = max(0, (int)$r['ago']);
            $out[(int)$r['user_id']] = ['online' => $ago <= self::ONLINE_SEC, 'place' => (string)$r['place'], 'ago' => $ago];
        }
        return $out;
    }

    public static function isOnline(int $userId): bool
    {
        return self::statusFor([$userId])[$userId]['online'] ?? false;
    }

    /** Pengguna yang sedang online (untuk daftar "Teman online" di mabar). */
    public static function onlineUsers(int $excludeId, int $limit = 30): array
    {
        $st = Database::get()->prepare('SELECT u.id, u.username, u.full_name, u.role, c.name AS class_name, p.place
            FROM user_presence p JOIN users u ON u.id = p.user_id LEFT JOIN classes c ON c.id = u.class_id
            WHERE p.last_seen >= datetime("now", ?) AND u.id != ? AND u.deleted_at IS NULL AND u.status = "active" AND u.role != "tamu"
            ORDER BY p.last_seen DESC LIMIT ' . max(1, min(60, $limit)));
        $st->execute(['-' . self::ONLINE_SEC . ' seconds', $excludeId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['online'] = true;
        }
        return $rows;
    }

    /** Teks singkat "Online · di Dunia 3D" / "Terakhir 12 menit lalu". */
    public static function label(array $s): string
    {
        if ($s['online']) {
            return 'Online' . ($s['place'] !== '' ? ' · ' . $s['place'] : '');
        }
        if ($s['ago'] === null) {
            return 'Offline';
        }
        $a = (int)$s['ago'];
        if ($a < 3600) {
            return 'Terakhir ' . max(1, intdiv($a, 60)) . ' menit lalu';
        }
        if ($a < 86400) {
            return 'Terakhir ' . intdiv($a, 3600) . ' jam lalu';
        }
        return 'Terakhir ' . intdiv($a, 86400) . ' hari lalu';
    }
}
