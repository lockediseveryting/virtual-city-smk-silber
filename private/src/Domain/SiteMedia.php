<?php
// Media situs yang dikendalikan PEMILIK (Security\Owner = coffelatte314):
//  - Latar halaman login: video (mp4/webm) atau gambar (jpg/png/webp); audio bawaan video bisa dinyalakan/dimatikan.
//  - Musik latar per tempat: login, dashboard siswa, dashboard admin, Dunia 3D, Dunia Pixel (mp3/ogg/m4a/wav).
// Semua berkas yang pernah diunggah TETAP tersimpan di pustaka (public/assets/media) walau diganti ke bawaan;
// hanya terhapus bila pemilik menekan Hapus. Status di settings.site_media (JSON).
// Unggah dipecah per potongan ≤ 4 MB (batas upload PHP 2 MB / post 8 MB tidak perlu diubah).
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use VC\Security\Owner;

final class SiteMedia
{
    public const DIR = '/assets/media';
    public const MAX_BYTES = 120 * 1048576;
    public const CHUNK_MAX = 4 * 1048576;
    public const MUSIC_SLOTS = [
        'login' => '🔑 Halaman Login',
        'dashboard' => '🏠 Dashboard Siswa',
        'admin' => '🛡️ Dashboard Admin',
        'game' => '🚀 Dunia 3D',
        'pixel' => '🧱 Dunia Pixel',
    ];
    private const MIME = [
        'video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'], 'video/quicktime' => ['mp4', 'video'],
        'image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/webp' => ['webp', 'image'], 'image/gif' => ['gif', 'image'],
        'audio/mpeg' => ['mp3', 'audio'], 'audio/mp3' => ['mp3', 'audio'], 'audio/ogg' => ['ogg', 'audio'], 'audio/x-m4a' => ['m4a', 'audio'],
        'audio/mp4' => ['m4a', 'audio'], 'audio/wav' => ['wav', 'audio'], 'audio/x-wav' => ['wav', 'audio'], 'video/ogg' => ['ogg', 'audio'],
        'audio/x-mpeg' => ['mp3', 'audio'], 'audio/mpeg3' => ['mp3', 'audio'], 'audio/x-mp3' => ['mp3', 'audio'], 'audio/x-mpeg-3' => ['mp3', 'audio'],
        'application/ogg' => ['ogg', 'audio'], 'audio/x-vorbis+ogg' => ['ogg', 'audio'], 'audio/aac' => ['m4a', 'audio'], 'audio/x-aac' => ['m4a', 'audio'],
    ];

    /** Cadangan bila finfo tidak yakin (application/octet-stream): kenali dari isi awal berkas, bukan dari nama. */
    private static function sniffAudio(string $path): ?string
    {
        $h = (string)@file_get_contents($path, false, null, 0, 16);
        if (str_starts_with($h, 'ID3') || (strlen($h) > 1 && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0)) {
            return 'audio/mpeg';
        }
        if (str_starts_with($h, 'OggS')) {
            return 'audio/ogg';
        }
        if (substr($h, 4, 4) === 'ftyp') {
            return 'audio/mp4';
        }
        return null;
    }

    public static function dirPath(): string
    {
        return dirname(__DIR__, 3) . '/public' . self::DIR;
    }

    private static function tmpDir(): string
    {
        $d = dirname(__DIR__, 2) . '/uploads/media-tmp';
        if (!is_dir($d)) {
            @mkdir($d, 02770, true);
            @chmod($d, 02770); // mkdir terpotong umask (→ 2750)
        }
        return $d;
    }

    public static function state(): array
    {
        $s = null;
        try {
            $st = Database::get()->prepare('SELECT value FROM settings WHERE key = "site_media"');
            $st->execute();
            $v = $st->fetchColumn();
            $s = $v !== false ? json_decode((string)$v, true) : null;
        } catch (\Throwable $e) {
        }
        $s = is_array($s) ? $s : [];
        $s['items'] = array_values(array_filter((array)($s['items'] ?? []), 'is_array'));
        $s['login'] = array_merge(['active' => 'default', 'audio' => false], (array)($s['login'] ?? []));
        $s['music'] = array_merge(array_fill_keys(array_keys(self::MUSIC_SLOTS), 'default'), (array)($s['music'] ?? []));
        return $s;
    }

    private static function save(array $s): void
    {
        Database::get()->prepare('INSERT INTO settings (key, value, updated_at) VALUES ("site_media", ?, datetime("now"))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
            ->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
    }

    private static function item(array $s, string $id): ?array
    {
        foreach ($s['items'] as $it) {
            if (($it['id'] ?? '') === $id) {
                return $it;
            }
        }
        return null;
    }

    public static function url(array $it): string
    {
        return self::DIR . '/' . rawurlencode((string)$it['file']);
    }

    /** Konfigurasi publik (halaman login, vc-sound.js). Berkas yang hilang di disk diabaikan. */
    public static function publicConfig(): array
    {
        $s = self::state();
        $login = null;
        $li = $s['login']['active'] !== 'default' ? self::item($s, (string)$s['login']['active']) : null;
        if ($li && is_file(self::dirPath() . '/' . $li['file'])) {
            $login = ['type' => $li['kind'], 'url' => self::url($li), 'mime' => (string)($li['mime'] ?? '')];
        }
        $music = [];
        foreach (self::MUSIC_SLOTS as $slot => $_) {
            $mi = $s['music'][$slot] !== 'default' ? self::item($s, (string)$s['music'][$slot]) : null;
            $music[$slot] = ($mi && is_file(self::dirPath() . '/' . $mi['file'])) ? self::url($mi) : null;
        }
        return ['login' => $login, 'login_audio' => (bool)$s['login']['audio'], 'music' => $music];
    }

    /**
     * Unggah satu potongan berkas (badan request mentah). Potongan terakhir → validasi isi (finfo) → pustaka.
     * @param array{id:string,index:int,total:int,name:string,title:string} $meta
     */
    public static function uploadChunk(?array $user, array $meta, string $data): array
    {
        if (!Owner::is($user)) {
            return ['status' => 'error', 'message' => 'Hanya pemilik (coffelatte314) yang bisa mengunggah media.'];
        }
        $uid = preg_replace('/[^a-f0-9]/', '', strtolower((string)$meta['id'])) ?? '';
        $idx = (int)$meta['index'];
        $total = (int)$meta['total'];
        if (strlen($uid) < 16 || $idx < 0 || $total < 1 || $idx >= $total || $total > (int)ceil(self::MAX_BYTES / (1048576))) {
            return ['status' => 'error', 'message' => 'Data unggahan tidak valid.'];
        }
        if ($data === '' || strlen($data) > self::CHUNK_MAX) {
            return ['status' => 'error', 'message' => 'Potongan berkas kosong atau terlalu besar.'];
        }
        $part = self::tmpDir() . '/' . $uid . '.part';
        if ($idx === 0) {
            @unlink($part);
        } elseif (!is_file($part)) {
            return ['status' => 'error', 'message' => 'Unggahan terputus — ulangi dari awal.'];
        }
        if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) {
            return ['status' => 'error', 'message' => 'Gagal menyimpan potongan berkas.'];
        }
        clearstatcache(true, $part);
        if (filesize($part) > self::MAX_BYTES) {
            @unlink($part);
            return ['status' => 'error', 'message' => 'Berkas terlalu besar (maks. ' . (self::MAX_BYTES / 1048576) . ' MB).'];
        }
        if ($idx < $total - 1) {
            return ['status' => 'ok', 'next' => $idx + 1];
        }
        // potongan terakhir: periksa isi sebenarnya (bukan dari nama berkas)
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($part);
        if (!isset(self::MIME[$mime]) && $mime === 'application/octet-stream') {
            $mime = self::sniffAudio($part) ?? $mime;
        }
        if (!isset(self::MIME[$mime])) {
            @unlink($part);
            return ['status' => 'error', 'message' => 'Jenis berkas tidak didukung (' . $mime . '). Pakai MP4/WebM, JPG/PNG/WebP, atau MP3/OGG/M4A/WAV.'];
        }
        [$ext, $kind] = self::MIME[$mime];
        // audio .ogg terdeteksi "video/ogg" bila tanpa video; anggap audio. mp4 berisi audio saja (m4a) → audio bila nama .m4a
        if ($mime === 'video/mp4' && preg_match('/\.m4a$/i', (string)$meta['name'])) {
            [$ext, $kind] = ['m4a', 'audio'];
        }
        $dir = self::dirPath();
        if (!is_dir($dir) && !@mkdir($dir, 02775, true)) {
            @unlink($part);
            return ['status' => 'error', 'message' => 'Folder media tidak bisa dibuat.'];
        }
        $id = bin2hex(random_bytes(6));
        $file = 'm_' . $id . '.' . $ext;
        if (!@rename($part, $dir . '/' . $file)) {
            @unlink($part);
            return ['status' => 'error', 'message' => 'Folder media tidak bisa ditulis (jalankan fix_perms.sh).'];
        }
        @chmod($dir . '/' . $file, 0644);
        $title = mb_substr(trim(strip_tags((string)$meta['title'])), 0, 80) ?: mb_substr(pathinfo((string)$meta['name'], PATHINFO_FILENAME), 0, 80) ?: $file;
        $it = ['id' => $id, 'file' => $file, 'kind' => $kind, 'mime' => $mime, 'title' => $title, 'size' => filesize($dir . '/' . $file),
            'created_at' => gmdate('c'), 'by' => (int)$user['id']];
        $s = self::state();
        $s['items'][] = $it;
        self::save($s);
        AuditLog::log('media_upload', ['id' => $id, 'kind' => $kind, 'size' => $it['size']], (int)$user['id']);
        return ['status' => 'ok', 'done' => true, 'item' => $it, 'message' => '✅ "' . $title . '" tersimpan di pustaka.'];
    }

    /** Aksi pemilik: login (pakai latar) · login_audio · music (slot) · rename · delete. */
    public static function action(?array $user, array $in): array
    {
        if (!Owner::is($user)) {
            return ['status' => 'error', 'message' => 'Hanya pemilik (coffelatte314) yang bisa mengatur media.'];
        }
        $s = self::state();
        $act = (string)($in['action'] ?? '');
        $id = (string)($in['id'] ?? '');
        $it = $id !== '' && $id !== 'default' ? self::item($s, $id) : null;
        if ($id !== '' && $id !== 'default' && $it === null) {
            return ['status' => 'error', 'message' => 'Media tidak ditemukan.'];
        }
        switch ($act) {
            case 'login':
                if ($it && !in_array($it['kind'], ['video', 'image'], true)) {
                    return ['status' => 'error', 'message' => 'Latar login harus video atau gambar.'];
                }
                $s['login']['active'] = $it ? $it['id'] : 'default';
                $msg = $it ? 'Latar login diganti ke "' . $it['title'] . '".' : 'Latar login kembali ke bawaan (berkas lain tetap tersimpan).';
                break;
            case 'login_audio':
                $s['login']['audio'] = !empty($in['on']);
                $msg = $s['login']['audio'] ? '🔊 Suara bawaan video login dinyalakan (musik login otomatis diam).' : '🔇 Suara video login dimatikan.';
                break;
            case 'music':
                $slot = (string)($in['slot'] ?? '');
                if (!isset(self::MUSIC_SLOTS[$slot])) {
                    return ['status' => 'error', 'message' => 'Tempat musik tidak dikenal.'];
                }
                if ($it && $it['kind'] !== 'audio' && $it['kind'] !== 'video') {
                    return ['status' => 'error', 'message' => 'Pilih berkas audio (atau video untuk diambil suaranya).'];
                }
                $s['music'][$slot] = $it ? $it['id'] : 'default';
                $msg = self::MUSIC_SLOTS[$slot] . ': ' . ($it ? 'memutar "' . $it['title'] . '".' : 'kembali ke musik bawaan.');
                break;
            case 'rename':
                if (!$it) {
                    return ['status' => 'error', 'message' => 'Media tidak ditemukan.'];
                }
                $t = mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 80);
                if ($t === '') {
                    return ['status' => 'error', 'message' => 'Judul tidak boleh kosong.'];
                }
                foreach ($s['items'] as &$x) {
                    if ($x['id'] === $it['id']) {
                        $x['title'] = $t;
                    }
                }
                unset($x);
                $msg = 'Judul diubah.';
                break;
            case 'delete':
                if (!$it) {
                    return ['status' => 'error', 'message' => 'Media tidak ditemukan.'];
                }
                $s['items'] = array_values(array_filter($s['items'], static fn($x) => $x['id'] !== $it['id']));
                if ($s['login']['active'] === $it['id']) {
                    $s['login']['active'] = 'default';
                }
                foreach ($s['music'] as $k => $v) {
                    if ($v === $it['id']) {
                        $s['music'][$k] = 'default';
                    }
                }
                @unlink(self::dirPath() . '/' . basename((string)$it['file']));
                $msg = '"' . $it['title'] . '" dihapus permanen dari pustaka.';
                break;
            default:
                return ['status' => 'error', 'message' => 'Aksi tidak dikenal.'];
        }
        self::save($s);
        AuditLog::log('media_' . $act, ['id' => $id, 'slot' => (string)($in['slot'] ?? '')], (int)$user['id']);
        return ['status' => 'ok', 'message' => $msg, 'state' => self::state()];
    }
}
