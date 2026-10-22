<?php
// Inventaris pemain (murid, guru, admin — semua akun kecuali tamu) untuk Dunia 3D & Pixel.
// Barang dasar sama untuk semua orang; yang tersimpan per pengguna hanya isi Buku Catatan (5 halaman)
// dan gambar Pensil (sketsa PNG kecil). Tabel user_notes dibuat otomatis / migrasi 025_inventory_notes.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;

final class Inventory
{
    /** id => [ikon, nama, keterangan] — urutan = slot hotbar (tombol 1–6). */
    public const ITEMS = [
        'senter' => ['🔦', 'Senter', 'Nyalakan saat malam — sorot cahaya terang ke depan.'],
        'buku'   => ['📕', 'Buku Catatan', 'Baca buku saku sekolah & tulis catatanmu sendiri (5 halaman, tersimpan).'],
        'pensil' => ['✏️', 'Pensil', 'Gambar sketsa di buku gambar (tersimpan).'],
        'peta'   => ['🗺️', 'Peta Sekolah', 'Buka peta denah & navigasi.'],
        'botol'  => ['💧', 'Botol Minum', 'Minum → segar, lari lebih cepat 20 detik (jeda 1 menit).'],
        'kartu'  => ['🪪', 'Kartu Pelajar', 'Tunjukkan identitasmu: nama, kelas, level.'],
    ];
    public const PAGES = 5;
    private const TEXT_MAX = 2000;
    private const SKETCH_MAX = 90000; // panjang data URL PNG maks.

    private static function ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        Database::get()->exec('CREATE TABLE IF NOT EXISTS user_notes (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            page TEXT NOT NULL, body TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, page))');
        $done = true;
    }

    public static function forUser(array $user): array
    {
        self::ensure();
        $uid = (int)$user['id'];
        $st = Database::get()->prepare('SELECT page, body FROM user_notes WHERE user_id = ?');
        $st->execute([$uid]);
        $notes = array_fill(1, self::PAGES, '');
        $sketch = '';
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['page'] === 'sketch') {
                $sketch = (string)$r['body'];
            } elseif (ctype_digit((string)$r['page']) && (int)$r['page'] >= 1 && (int)$r['page'] <= self::PAGES) {
                $notes[(int)$r['page']] = (string)$r['body'];
            }
        }
        $items = [];
        foreach (self::ITEMS as $id => [$icon, $name, $desc]) {
            $items[] = ['id' => $id, 'icon' => $icon, 'name' => $name, 'desc' => $desc];
        }
        $cls = '';
        if ((int)($user['class_id'] ?? 0) > 0) {
            $c = Database::get()->prepare('SELECT name FROM classes WHERE id = ?');
            $c->execute([(int)$user['class_id']]);
            $cls = (string)($c->fetchColumn() ?: '');
        }
        $prog = Gameplay::getProgress($uid);
        return ['status' => 'ok', 'items' => $items, 'notes' => array_values($notes), 'sketch' => $sketch,
            'card' => ['name' => (string)($user['full_name'] ?: $user['username']), 'username' => (string)$user['username'], 'role' => (string)$user['role'],
                'class' => $cls, 'nis' => (string)($user['nis'] ?? ''), 'level' => (int)$prog['level'], 'photo' => ProfilePhoto::url($uid)]];
    }

    public static function save(array $user, array $in): array
    {
        self::ensure();
        $uid = (int)$user['id'];
        if (($in['action'] ?? '') === 'sketch') {
            $d = (string)($in['data'] ?? '');
            if ($d !== '' && (strlen($d) > self::SKETCH_MAX || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $d))) {
                return ['status' => 'error', 'message' => 'Gambar terlalu besar / tidak valid.'];
            }
            $page = 'sketch';
            $body = $d;
        } else {
            $p = (int)($in['page'] ?? 0);
            if ($p < 1 || $p > self::PAGES) {
                return ['status' => 'error', 'message' => 'Halaman tidak valid.'];
            }
            $page = (string)$p;
            $body = mb_substr(str_replace("\r", '', (string)($in['text'] ?? '')), 0, self::TEXT_MAX);
        }
        Database::get()->prepare('INSERT INTO user_notes (user_id, page, body, updated_at) VALUES (?, ?, ?, datetime("now"))
            ON CONFLICT(user_id, page) DO UPDATE SET body = excluded.body, updated_at = excluded.updated_at')->execute([$uid, $page, $body]);
        return ['status' => 'ok'];
    }
}
