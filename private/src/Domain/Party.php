<?php
// Party (MABAR): grup main bareng maks. 15 orang di dashboard secara real time.
// - Kapten membuat party → dapat kode 6 huruf; mengundang teman (notifikasi + pop-up) atau bagi kode.
// - Anggota saling terlihat di panggung dashboard, chat & voice lewat WebSocket yang sama dengan
//   dunia game (room terpisah: serverId = ROOM_BASE + id, world = "party").
// - Kapten memilih tujuan (Dunia 3D / Pixel / server tertentu) → "Berangkat!" → semua anggota pindah.
// Satu pengguna hanya di satu party (UNIQUE party_members.user_id).
// - Visibilitas: "code" (hanya kode / QR / undangan) atau "public" (tampil di daftar Mabar Publik, siapa pun boleh masuk).
// - Undangan hanya untuk pengguna yang sedang ONLINE (Presence, dari database).
// - Misi Mabar (QUESTS): kapten memilih tujuan bersama; progres dihitung dari kejadian unik (party_quest_log),
//   selesai → semua anggota dapat XP + koin (idempoten).
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;

final class Party
{
    public const MAX = 15;
    public const ROOM_BASE = 1000000; // serverId WS untuk party = ROOM_BASE + party id
    private const CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Misi mabar. unit "ref" = hitung kejadian berbeda (gabungan semua anggota);
     * unit "user_ref" = tiap anggota boleh menyumbang untuk tiap objek berbeda.
     */
    public const QUESTS = [
        'tour' => ['icon' => '🏫', 'title' => 'Tur Sekolah', 'desc' => 'Kunjungi 6 lokasi berbeda di Dunia 3D / Pixel (gabungan semua anggota).', 'event' => 'visit', 'unit' => 'ref', 'target' => 6, 'xp' => 40, 'coins' => 15],
        'cat' => ['icon' => '🐱', 'title' => 'Sahabat Kucing', 'desc' => 'Temukan & elus kucing sekolah 5 kali (kucing atau anggota berbeda).', 'event' => 'cat', 'unit' => 'user_ref', 'target' => 5, 'xp' => 40, 'coins' => 15],
        'clean' => ['icon' => '🧹', 'title' => 'Jumat Bersih', 'desc' => 'Pungut 12 sampah yang berserakan di sekolah, buang ke tempatnya.', 'event' => 'trash', 'unit' => 'user_ref', 'target' => 12, 'xp' => 50, 'coins' => 20],
        'ghost' => ['icon' => '👻', 'title' => 'Pemburu Anomali', 'desc' => 'Malam hari: temukan 4 jenis anomali berbeda (Bayangan, Pocong, Kuntilanak, Mata Merah).', 'event' => 'anomaly', 'unit' => 'ref', 'target' => 4, 'xp' => 70, 'coins' => 30],
    ];
    public const ANOMALY_TYPES = ['bayangan', 'pocong', 'kunti', 'mata'];

    public static function idOf(int $userId): int
    {
        $st = Database::get()->prepare('SELECT party_id FROM party_members WHERE user_id = ?');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }

    /** Status party pengguna (null bila tidak di party). */
    public static function current(int $userId): ?array
    {
        $pid = self::idOf($userId);
        return $pid > 0 ? self::load($pid) : null;
    }

    public static function load(int $pid): ?array
    {
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT p.*, COALESCE(NULLIF(u.full_name, ""), u.username) AS captain_name FROM parties p JOIN users u ON u.id = p.captain_id WHERE p.id = ?');
        $st->execute([$pid]);
        $p = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$p) {
            return null;
        }
        $m = $pdo->prepare('SELECT u.id, COALESCE(NULLIF(u.full_name, ""), u.username) AS name, u.role, c.name AS class_name, prof.avatar_config
            FROM party_members pm JOIN users u ON u.id = pm.user_id
            LEFT JOIN classes c ON c.id = u.class_id LEFT JOIN user_profiles prof ON prof.user_id = u.id
            WHERE pm.party_id = ? ORDER BY pm.joined_at, u.id');
        $m->execute([$pid]);
        $rows = $m->fetchAll(\PDO::FETCH_ASSOC);
        $pres = Presence::statusFor(array_column($rows, 'id'));
        $members = [];
        foreach ($rows as $r) {
            $cfg = json_decode((string)($r['avatar_config'] ?? ''), true);
            $ps = $pres[(int)$r['id']] ?? ['online' => false, 'place' => '', 'ago' => null];
            $members[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'role' => (string)$r['role'], 'class_name' => (string)($r['class_name'] ?? ''),
                'avatar' => is_array($cfg) ? $cfg : null, 'captain' => (int)$r['id'] === (int)$p['captain_id'],
                'online' => $ps['online'], 'presence' => Presence::label($ps)];
        }
        return ['id' => $pid, 'code' => (string)$p['code'], 'name' => (string)$p['name'], 'captain_id' => (int)$p['captain_id'],
            'captain_name' => (string)$p['captain_name'], 'dest_url' => (string)$p['dest_url'], 'members' => $members, 'max' => self::MAX,
            'visibility' => (string)($p['visibility'] ?? 'code'), 'quest' => self::questView($pid, (string)($p['quest'] ?? ''))];
    }

    public static function create(array $user, string $name = '', string $visibility = 'code'): array
    {
        $uid = (int)$user['id'];
        if (($user['role'] ?? '') === 'tamu') {
            return ['status' => 'error', 'message' => 'Akun tamu belum bisa membuat mabar.'];
        }
        self::leave($uid);
        $pdo = Database::get();
        $name = mb_substr(trim(strip_tags($name)), 0, 40) ?: ('Mabar ' . explode(' ', (string)($user['full_name'] ?: $user['username']))[0]);
        for ($i = 0; $i < 8; $i++) {
            $code = '';
            for ($k = 0; $k < 6; $k++) {
                $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
            try {
                $pdo->prepare('INSERT INTO parties (code, captain_id, name, visibility) VALUES (?, ?, ?, ?)')
                    ->execute([$code, $uid, $name, $visibility === 'public' ? 'public' : 'code']);
                break;
            } catch (\PDOException $e) {
                $code = '';
            }
        }
        if ($code === '') {
            return ['status' => 'error', 'message' => 'Gagal membuat kode mabar, coba lagi.'];
        }
        $pid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO party_members (party_id, user_id) VALUES (?, ?)')->execute([$pid, $uid]);
        AuditLog::log('party_create', ['party_id' => $pid], $uid);
        return ['status' => 'ok', 'message' => 'Mabar dibuat! Kamu kaptennya.', 'party' => self::load($pid)];
    }

    /** Gabung pakai kode (atau id party dari undangan). */
    public static function join(array $user, string $code = '', int $partyId = 0): array
    {
        $uid = (int)$user['id'];
        if (($user['role'] ?? '') === 'tamu') {
            return ['status' => 'error', 'message' => 'Akun tamu belum bisa ikut mabar.'];
        }
        $pdo = Database::get();
        if ($partyId <= 0) {
            $st = $pdo->prepare('SELECT id FROM parties WHERE code = ?');
            $st->execute([strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '')]);
            $partyId = (int)$st->fetchColumn();
        } else {
            // tanpa kode: lewat undangan, atau party bersifat publik
            $st = $pdo->prepare('SELECT 1 FROM party_invites WHERE party_id = ? AND to_id = ?
                UNION SELECT 1 FROM parties WHERE id = ? AND visibility = "public"');
            $st->execute([$partyId, $uid, $partyId]);
            if (!$st->fetchColumn()) {
                return ['status' => 'error', 'message' => 'Mabar ini privat — minta kode / QR ke kaptennya, atau tunggu undangan.'];
            }
        }
        if ($partyId <= 0 || self::load($partyId) === null) {
            return ['status' => 'error', 'message' => 'Kode mabar tidak ditemukan.'];
        }
        if (self::idOf($uid) === $partyId) {
            return ['status' => 'ok', 'message' => 'Kamu sudah di mabar ini.', 'party' => self::load($partyId)];
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM party_members WHERE party_id = ?');
        $cnt->execute([$partyId]);
        if ((int)$cnt->fetchColumn() >= self::MAX) {
            return ['status' => 'error', 'message' => 'Mabar sudah penuh (maks. ' . self::MAX . ' orang).'];
        }
        self::leave($uid);
        $pdo->prepare('INSERT INTO party_members (party_id, user_id) VALUES (?, ?)')->execute([$partyId, $uid]);
        $pdo->prepare('DELETE FROM party_invites WHERE party_id = ? AND to_id = ?')->execute([$partyId, $uid]);
        self::signal($partyId, ['type' => 'party_update']);
        return ['status' => 'ok', 'message' => 'Berhasil gabung mabar!', 'party' => self::load($partyId)];
    }

    /** Keluar party. Kapten keluar → kapten pindah ke anggota terlama; party kosong → dihapus. */
    public static function leave(int $uid): void
    {
        $pid = self::idOf($uid);
        if ($pid <= 0) {
            return;
        }
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM party_members WHERE user_id = ?')->execute([$uid]);
        $next = $pdo->prepare('SELECT user_id FROM party_members WHERE party_id = ? ORDER BY joined_at, user_id LIMIT 1');
        $next->execute([$pid]);
        $nid = (int)$next->fetchColumn();
        if ($nid <= 0) {
            $pdo->prepare('DELETE FROM parties WHERE id = ?')->execute([$pid]);
            return;
        }
        $pdo->prepare('UPDATE parties SET captain_id = ? WHERE id = ? AND captain_id = ?')->execute([$nid, $pid, $uid]);
        self::signal($pid, ['type' => 'party_update']);
    }

    public static function kick(int $captainId, int $targetId): array
    {
        $p = self::current($captainId);
        if ($p === null || $p['captain_id'] !== $captainId) {
            return ['status' => 'error', 'message' => 'Hanya kapten yang bisa mengeluarkan anggota.'];
        }
        if ($targetId === $captainId || self::idOf($targetId) !== $p['id']) {
            return ['status' => 'error', 'message' => 'Anggota tidak ditemukan.'];
        }
        Database::get()->prepare('DELETE FROM party_members WHERE user_id = ?')->execute([$targetId]);
        self::signal($p['id'], ['type' => 'party_kick', 'id' => $targetId]);
        return ['status' => 'ok', 'message' => 'Anggota dikeluarkan.'];
    }

    /** Kapten mengundang pengguna lain: notifikasi lonceng + pop-up di dashboard target. */
    public static function invite(array $from, int $toId): array
    {
        $fid = (int)$from['id'];
        $p = self::current($fid);
        if ($p === null) {
            $res = self::create($from);
            if ($res['status'] !== 'ok') {
                return $res;
            }
            $p = $res['party'];
        }
        if ($p['captain_id'] !== $fid) {
            return ['status' => 'error', 'message' => 'Hanya kapten (' . $p['captain_name'] . ') yang bisa mengundang.'];
        }
        if (count($p['members']) >= self::MAX) {
            return ['status' => 'error', 'message' => 'Mabar sudah penuh (maks. ' . self::MAX . ').'];
        }
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND deleted_at IS NULL AND status = "active"');
        $st->execute([$toId]);
        $to = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$to || $toId === $fid) {
            return ['status' => 'error', 'message' => 'Pengguna tidak ditemukan.'];
        }
        if (self::idOf($toId) === $p['id']) {
            return ['status' => 'ok', 'message' => 'Dia sudah ada di mabar kamu.'];
        }
        if (!Presence::isOnline($toId)) {
            return ['status' => 'error', 'message' => 'Dia sedang offline — undangan hanya bisa dikirim ke yang online. Bagikan kode / link mabar saja.'];
        }
        $rl = $pdo->prepare('SELECT COUNT(*) FROM party_invites WHERE from_id = ? AND created_at > datetime("now", "-1 minute")');
        $rl->execute([$fid]);
        if ((int)$rl->fetchColumn() >= 10) {
            return ['status' => 'error', 'message' => 'Terlalu banyak undangan. Tunggu sebentar.'];
        }
        $pdo->prepare('INSERT OR REPLACE INTO party_invites (party_id, from_id, to_id, created_at) VALUES (?, ?, ?, datetime("now"))')->execute([$p['id'], $fid, $toId]);
        $fromName = (string)($from['full_name'] ?: $from['username']);
        Notification::push($toId, 'sosial', '🎮 ' . $fromName . ' mengajak kamu mabar!', 'Party "' . $p['name'] . '" · kode ' . $p['code'] . '. Buka dashboard untuk bergabung.', '/?mabar=' . $p['code'], 'party:' . $p['id'] . ':' . time());
        return ['status' => 'ok', 'message' => 'Undangan terkirim! Dia akan melihat pop-up ajakan mabar.'];
    }

    /** Undangan yang menunggu untuk pengguna ini (maks. 24 jam). */
    public static function invitesFor(int $uid): array
    {
        $st = Database::get()->prepare('SELECT i.party_id, p.code, p.name, COALESCE(NULLIF(u.full_name, ""), u.username) AS from_name,
                (SELECT COUNT(*) FROM party_members WHERE party_id = p.id) AS size
            FROM party_invites i JOIN parties p ON p.id = i.party_id JOIN users u ON u.id = i.from_id
            WHERE i.to_id = ? AND i.created_at > datetime("now", "-1 day") ORDER BY i.created_at DESC LIMIT 5');
        $st->execute([$uid]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function decline(int $uid, int $partyId): void
    {
        Database::get()->prepare('DELETE FROM party_invites WHERE to_id = ? AND party_id = ?')->execute([$uid, $partyId]);
    }

    /** Tujuan yang diizinkan: dunia 3D/Pixel (opsional server tertentu) atau halaman dashboard. */
    public static function normalizeDest(string $url): ?string
    {
        if (preg_match('#^/(dunia|pixel)(\?server=(\d{1,9}))?$#', $url, $m)) {
            if (!empty($m[3])) {
                $world = GameServers::worldOfId((int)$m[3]);
                if ($world === null) {
                    return null;
                }
                return GameServers::worldUrl($world, (int)$m[3]);
            }
            return '/' . $m[1];
        }
        return null;
    }

    /**
     * Anggota (bukan kapten) tidak boleh masuk dunia duluan / ke dunia lain: hanya setelah kapten
     * menekan "Berangkat" dan hanya ke dunia tujuan itu. Kembalikan party bila DIKUNCI, null bila boleh.
     */
    public static function lockFor(int $uid, string $world): ?array
    {
        $p = self::current($uid);
        if ($p === null || $p['captain_id'] === $uid) {
            return null;
        }
        $dest = $p['dest_url'];
        if ($dest !== '' && strpos($dest, '/' . ($world === 'pixel' ? 'pixel' : 'dunia')) === 0) {
            return null;
        }
        return $p;
    }

    /** Kapten: "Berangkat!" → semua anggota diarahkan ke tujuan yang sama. */
    public static function go(int $captainId, string $dest): array
    {
        $p = self::current($captainId);
        if ($p === null || $p['captain_id'] !== $captainId) {
            return ['status' => 'error', 'message' => 'Hanya kapten yang menentukan tujuan.'];
        }
        $url = self::normalizeDest($dest);
        if ($url === null) {
            return ['status' => 'error', 'message' => 'Tujuan tidak valid.'];
        }
        Database::get()->prepare('UPDATE parties SET dest_url = ? WHERE id = ?')->execute([$url, $p['id']]);
        self::signal($p['id'], ['type' => 'party_go', 'url' => $url, 'by' => $p['captain_name']]);
        AuditLog::log('party_go', ['party_id' => $p['id'], 'dest' => $url, 'members' => count($p['members'])], $captainId);
        return ['status' => 'ok', 'message' => 'Berangkat ke tujuan!', 'url' => $url];
    }

    /** Kapten: ubah mabar jadi publik (siapa pun bisa masuk) atau hanya kode. */
    public static function setVisibility(int $captainId, string $vis): array
    {
        $p = self::current($captainId);
        if ($p === null || $p['captain_id'] !== $captainId) {
            return ['status' => 'error', 'message' => 'Hanya kapten yang bisa mengubah pengaturan mabar.'];
        }
        $vis = $vis === 'public' ? 'public' : 'code';
        Database::get()->prepare('UPDATE parties SET visibility = ? WHERE id = ?')->execute([$vis, $p['id']]);
        self::signal($p['id'], ['type' => 'party_update']);
        return ['status' => 'ok', 'message' => $vis === 'public' ? '🌐 Mabar sekarang PUBLIK — tampil di daftar, siapa pun bisa masuk.' : '🔒 Mabar sekarang hanya lewat kode / QR / undangan.'];
    }

    /** Daftar mabar publik yang belum penuh (untuk tombol "Masuk" tanpa kode). */
    public static function publicList(int $limit = 12): array
    {
        $st = Database::get()->prepare('SELECT p.id, p.name, p.quest, COALESCE(NULLIF(u.full_name, ""), u.username) AS captain_name,
                (SELECT COUNT(*) FROM party_members WHERE party_id = p.id) AS size
            FROM parties p JOIN users u ON u.id = p.captain_id
            WHERE p.visibility = "public" ORDER BY p.created_at DESC LIMIT ' . max(1, min(30, $limit)));
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['size'] <= 0 || (int)$r['size'] >= self::MAX) {
                continue;
            }
            $q = self::QUESTS[(string)$r['quest']] ?? null;
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'captain_name' => (string)$r['captain_name'], 'size' => (int)$r['size'],
                'quest' => $q ? $q['icon'] . ' ' . $q['title'] : ''];
        }
        return $out;
    }

    public static function questList(): array
    {
        $out = [];
        foreach (self::QUESTS as $k => $q) {
            $out[] = ['key' => $k, 'icon' => $q['icon'], 'title' => $q['title'], 'desc' => $q['desc'], 'target' => $q['target'], 'xp' => $q['xp'], 'coins' => $q['coins']];
        }
        return $out;
    }

    private static function questCount(int $pid, string $key): int
    {
        $q = self::QUESTS[$key] ?? null;
        if ($q === null) {
            return 0;
        }
        $sql = $q['unit'] === 'ref'
            ? 'SELECT COUNT(DISTINCT ref) FROM party_quest_log WHERE party_id = ? AND quest = ?'
            : 'SELECT COUNT(*) FROM party_quest_log WHERE party_id = ? AND quest = ?';
        $st = Database::get()->prepare($sql);
        $st->execute([$pid, $key]);
        return (int)$st->fetchColumn();
    }

    public static function questView(int $pid, string $key): ?array
    {
        $q = self::QUESTS[$key] ?? null;
        if ($q === null) {
            return null;
        }
        $n = min($q['target'], self::questCount($pid, $key));
        return ['key' => $key, 'icon' => $q['icon'], 'title' => $q['title'], 'desc' => $q['desc'], 'event' => $q['event'],
            'progress' => $n, 'target' => $q['target'], 'done' => $n >= $q['target'], 'xp' => $q['xp'], 'coins' => $q['coins']];
    }

    /** Kapten memilih misi mabar (progres dimulai dari nol). key "" = tanpa misi. */
    public static function setQuest(int $captainId, string $key): array
    {
        $p = self::current($captainId);
        if ($p === null || $p['captain_id'] !== $captainId) {
            return ['status' => 'error', 'message' => 'Hanya kapten yang memilih misi mabar.'];
        }
        if ($key !== '' && !isset(self::QUESTS[$key])) {
            return ['status' => 'error', 'message' => 'Misi tidak dikenal.'];
        }
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM party_quest_log WHERE party_id = ? AND quest = ?')->execute([$p['id'], $key]);
        $pdo->prepare('UPDATE parties SET quest = ?, quest_started = datetime("now") WHERE id = ?')->execute([$key, $p['id']]);
        self::signal($p['id'], ['type' => 'party_update']);
        if ($key === '') {
            return ['status' => 'ok', 'message' => 'Misi mabar dibatalkan.'];
        }
        $q = self::QUESTS[$key];
        AuditLog::log('party_quest', ['party_id' => $p['id'], 'quest' => $key], $captainId);
        return ['status' => 'ok', 'message' => 'Misi dimulai: ' . $q['icon'] . ' ' . $q['title'] . '!'];
    }

    /**
     * Laporan kejadian dari dunia game (kunjungan, elus kucing, pungut sampah, anomali).
     * Tidak dalam party / misi berbeda → diam (status "idle"). Kejadian unik dicatat sekali.
     */
    public static function report(int $uid, string $event, string $ref): array
    {
        $ref = substr(preg_replace('/[^A-Za-z0-9:_-]/', '', $ref) ?? '', 0, 48);
        $pid = self::idOf($uid);
        if ($pid <= 0 || $ref === '') {
            return ['status' => 'idle'];
        }
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT quest, quest_started FROM parties WHERE id = ?');
        $st->execute([$pid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $key = (string)($row['quest'] ?? '');
        $q = self::QUESTS[$key] ?? null;
        if ($q === null || $q['event'] !== $event) {
            return ['status' => 'idle'];
        }
        if ($event === 'anomaly' && !in_array($ref, self::ANOMALY_TYPES, true)) {
            return ['status' => 'idle'];
        }
        $before = self::questCount($pid, $key);
        if ($before >= $q['target']) {
            return ['status' => 'ok', 'quest' => self::questView($pid, $key)];
        }
        // Batas wajar: maks. 1 kejadian / 2 detik per anggota.
        $rl = $pdo->prepare('SELECT COUNT(*) FROM party_quest_log WHERE party_id = ? AND user_id = ? AND created_at > datetime("now", "-2 seconds")');
        $rl->execute([$pid, $uid]);
        if ((int)$rl->fetchColumn() > 0) {
            return ['status' => 'ok', 'quest' => self::questView($pid, $key)];
        }
        $pdo->prepare('INSERT OR IGNORE INTO party_quest_log (party_id, quest, user_id, ref) VALUES (?, ?, ?, ?)')
            ->execute([$pid, $key, $q['unit'] === 'ref' ? 0 : $uid, $ref]);
        $after = self::questCount($pid, $key);
        $view = self::questView($pid, $key);
        if ($after !== $before) {
            self::signal($pid, ['type' => 'party_quest', 'quest' => $view, 'by' => $uid]);
        }
        if ($after >= $q['target'] && $before < $q['target']) {
            // Selesai → hadiah untuk semua anggota (idempoten per misi per sesi misi).
            $tag = $pid . ':' . $key . ':' . (string)($row['quest_started'] ?? '');
            $m = $pdo->prepare('SELECT user_id FROM party_members WHERE party_id = ?');
            $m->execute([$pid]);
            foreach ($m->fetchAll(\PDO::FETCH_COLUMN) as $mid) {
                try {
                    Gameplay::grantXP((int)$mid, $q['xp'], 'party_quest', $key, 'Misi mabar: ' . $q['title'], 'pq-xp:' . $tag . ':' . $mid);
                    Gameplay::grantCoins((int)$mid, $q['coins'], 'party_quest', $key, 'Misi mabar: ' . $q['title'], 'pq-c:' . $tag . ':' . $mid);
                    Notification::push((int)$mid, 'sosial', $q['icon'] . ' Misi mabar selesai: ' . $q['title'], '+' . $q['xp'] . ' XP · +' . $q['coins'] . ' koin untuk semua anggota.', '/?mabar=1', 'pq:' . $tag . ':' . $mid);
                } catch (\Throwable $e) {
                    error_log('[party] hadiah misi gagal: ' . $e->getMessage());
                }
            }
            AuditLog::log('party_quest_done', ['party_id' => $pid, 'quest' => $key], $uid);
            self::signal($pid, ['type' => 'party_quest', 'quest' => $view, 'done' => true]);
        }
        return ['status' => 'ok', 'quest' => $view, 'counted' => $after !== $before];
    }

    /** Kirim event ke semua anggota yang sedang terhubung (WS internal, HMAC). Gagal = diam (klien juga polling). */
    public static function signal(int $pid, array $payload): void
    {
        try {
            $body = json_encode(['serverId' => self::ROOM_BASE + $pid, 'payload' => $payload], JSON_UNESCAPED_UNICODE);
            $ts = (string)time();
            $sig = hash_hmac('sha256', $ts . '.' . $body, \vc_env('APP_KEY', 'virtual-city-default-key-silber-2026'));
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 1.5, 'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\nX-VC-Timestamp: $ts\r\nX-VC-Signature: $sig\r\n", 'content' => $body]]);
            @file_get_contents('http://127.0.0.1:' . (int)\vc_env('WS_PORT', '8090') . '/internal/party', false, $ctx);
        } catch (\Throwable $e) {
            // WS mati → klien tetap sinkron lewat polling /api/party
        }
    }
}
