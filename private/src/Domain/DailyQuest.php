<?php
// Misi Harian: setiap hari (tanggal WIB) tiap pemain mendapat 3 misi ringan dari daftar di bawah
// — jelajah, sosial, seru — supaya selalu ada tujuan saat login. Progres dihitung DI SERVER dari
// kejadian nyata (kunjungan lokasi, chat, mabar, gacha, misi sekolah, membuka dunia 3D/Pixel,
// profil teman). Hanya emote / kucing / sampah yang dilaporkan klien (daftar putih + batas laju).
// Hadiah lewat ledger Gameplay dengan kunci idempoten (tidak bisa diklaim dua kali).
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;

final class DailyQuest
{
    /** id => [ikon, judul, event, target, xp, koin, petunjuk, aksi(url|js)] */
    private const POOL = [
        'play3d'  => ['🚀', 'Masuk ke Dunia 3D', 'play3d', 1, 30, 15, 'Buka Dunia 3D dari dashboard.', '/dunia'],
        'playpix' => ['🧱', 'Jalan-jalan di Dunia Pixel', 'playpix', 1, 30, 15, 'Buka Dunia Pixel dari dashboard.', '/pixel'],
        'visit3'  => ['🧭', 'Kunjungi 3 lokasi berbeda', 'visit', 3, 40, 20, 'Masuk ke ruangan/lokasi mana saja di 3D atau Pixel.', '/pixel'],
        'visit6'  => ['🗺️', 'Jelajahi 6 lokasi sekolah', 'visit', 6, 60, 30, 'Pakai 🧭 Navigasi untuk mencari lokasi baru.', '/dunia'],
        'chat2'   => ['💬', 'Sapa teman: kirim 2 chat di game', 'chat', 2, 35, 15, 'Tombol 💬 Chat di dunia 3D / Pixel.', '/pixel'],
        'emote3'  => ['😄', 'Pakai 3 emote berbeda', 'emote', 3, 30, 15, 'Tombol 😄 Emote di dashboard, 3D (G) atau Pixel (✨).', 'js:vcEmote.toggle()'],
        'cat2'    => ['🐱', 'Elus 2 kucing sekolah', 'cat', 2, 35, 20, 'Kucing berkeliaran di halaman — dekati lalu tekan E/AKSI.', '/pixel'],
        'trash3'  => ['🧹', 'Pungut 3 sampah', 'trash', 3, 40, 20, 'Sampah ada di halaman sekolah — dekati lalu tekan E/AKSI.', '/dunia'],
        'mabar'   => ['🎮', 'Main bareng (buat / ikut Mabar)', 'mabar', 1, 45, 25, 'Ajak teman yang sedang online.', 'js:vcParty.open()'],
        'gacha'   => ['🎰', 'Putar gacha harian', 'gacha', 1, 20, 0, 'Putaran pertama tiap 24 jam gratis!', 'js:vcGacha.open()'],
        'mission' => ['🎯', 'Selesaikan 1 misi sekolah', 'mission', 1, 50, 25, 'Lihat daftar di halaman Misi.', '/misi'],
        'profile' => ['🪪', 'Lihat profil seorang teman', 'profile', 1, 20, 10, 'Cari teman di halaman Profil.', '/profil'],
        'pc'      => ['💻', 'Pakai komputer di Lab (Dunia Pixel)', 'pc', 1, 35, 20, 'Masuk Lab TIK / Lab Jaringan, dekati komputer, tekan E.', '/pixel'],
    ];
    private const GROUPS = [['visit3', 'visit6', 'play3d', 'playpix', 'pc'], ['chat2', 'mabar', 'profile', 'emote3'], ['cat2', 'trash3', 'gacha', 'mission']];
    private const CLIENT_EVENTS = ['emote', 'cat', 'trash', 'pc'];
    private const BONUS_XP = 60, BONUS_COINS = 50;

    private static function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo = Database::get();
        $pdo->exec('CREATE TABLE IF NOT EXISTS daily_quest_log (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            day TEXT NOT NULL, quest TEXT NOT NULL, ref TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, day, quest, ref))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS daily_quest_claims (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            day TEXT NOT NULL, quest TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, day, quest))');
        $done = true;
    }

    public static function today(): string
    {
        return Schedule::nowLocal('Y-m-d');
    }

    /** 3 misi hari ini (satu dari tiap kelompok), tetap sepanjang hari untuk pemain yang sama. */
    public static function questsFor(int $userId, ?string $day = null): array
    {
        $day = $day ?? self::today();
        $out = [];
        foreach (self::GROUPS as $gi => $group) {
            $h = crc32($userId . '|' . $day . '|' . $gi);
            $out[] = $group[$h % count($group)];
        }
        return $out;
    }

    /** Catat kejadian; hanya menambah progres misi hari ini yang memakai event tsb. */
    public static function report(int $userId, string $event, string $ref = ''): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            self::ensureTables();
            $day = self::today();
            $ref = substr(preg_replace('/[^A-Za-z0-9:_-]/', '', $ref) ?? '', 0, 48);
            if ($ref === '') {
                $ref = 'x';
            }
            $pdo = Database::get();
            foreach (self::questsFor($userId, $day) as $q) {
                if (self::POOL[$q][2] !== $event) {
                    continue;
                }
                if (self::progress($userId, $day, $q) >= self::POOL[$q][3]) {
                    continue;
                }
                $pdo->prepare('INSERT OR IGNORE INTO daily_quest_log (user_id, day, quest, ref) VALUES (?, ?, ?, ?)')->execute([$userId, $day, $q, $ref]);
            }
        } catch (\Throwable $e) {
            // misi harian tidak boleh mematahkan fitur utama
        }
    }

    /** Laporan dari klien (emote/kucing/sampah/komputer) — daftar putih + maks. 1 kejadian / 2 detik. */
    public static function clientReport(int $userId, string $event, string $ref): array
    {
        if (!in_array($event, self::CLIENT_EVENTS, true)) {
            return ['status' => 'error', 'message' => 'Kejadian tidak dikenal.'];
        }
        self::ensureTables();
        $st = Database::get()->prepare('SELECT COUNT(*) FROM daily_quest_log WHERE user_id = ? AND created_at > datetime("now", "-2 seconds")');
        $st->execute([$userId]);
        if ((int)$st->fetchColumn() === 0) {
            self::report($userId, $event, $ref);
        }
        return self::status($userId);
    }

    private static function progress(int $userId, string $day, string $quest): int
    {
        $st = Database::get()->prepare('SELECT COUNT(*) FROM daily_quest_log WHERE user_id = ? AND day = ? AND quest = ?');
        $st->execute([$userId, $day, $quest]);
        return (int)$st->fetchColumn();
    }

    public static function status(int $userId): array
    {
        self::ensureTables();
        $day = self::today();
        $pdo = Database::get();
        $cl = $pdo->prepare('SELECT quest FROM daily_quest_claims WHERE user_id = ? AND day = ?');
        $cl->execute([$userId, $day]);
        $claimed = array_flip($cl->fetchAll(\PDO::FETCH_COLUMN));
        $list = [];
        $doneAll = true;
        foreach (self::questsFor($userId, $day) as $q) {
            [$icon, $title, , $target, $xp, $coins, $hint, $action] = self::POOL[$q];
            $p = min($target, self::progress($userId, $day, $q));
            $done = $p >= $target;
            if (!$done) {
                $doneAll = false;
            }
            $list[] = ['id' => $q, 'icon' => $icon, 'title' => $title, 'progress' => $p, 'target' => $target, 'done' => $done,
                'claimed' => isset($claimed[$q]), 'xp' => $xp, 'coins' => $coins, 'hint' => $hint, 'action' => $action];
        }
        $online = [];
        try {
            foreach (Presence::onlineUsers($userId, 6) as $o) {
                $online[] = ['id' => (int)$o['id'], 'name' => (string)($o['full_name'] ?? $o['name'] ?? ''), 'place' => (string)($o['place'] ?? '')];
            }
        } catch (\Throwable $e) {
            $online = [];
        }
        $prog = Gameplay::getProgress($userId);
        return [
            'status' => 'ok', 'day' => $day, 'quests' => $list,
            'bonus' => ['ready' => $doneAll, 'claimed' => isset($claimed['__bonus']), 'xp' => self::BONUS_XP, 'coins' => self::BONUS_COINS],
            'streak' => (int)($prog['login_streak'] ?? 0), 'online' => $online,
        ];
    }

    public static function claim(int $userId, string $quest): array
    {
        self::ensureTables();
        $day = self::today();
        $st = self::status($userId);
        if ($quest === '__bonus') {
            if (!$st['bonus']['ready']) {
                return ['status' => 'error', 'message' => 'Selesaikan ketiga misi hari ini dulu.'];
            }
            $xp = self::BONUS_XP; $coins = self::BONUS_COINS; $label = 'Peti bonus misi harian';
        } else {
            $q = null;
            foreach ($st['quests'] as $x) {
                if ($x['id'] === $quest) {
                    $q = $x;
                }
            }
            if ($q === null || !$q['done']) {
                return ['status' => 'error', 'message' => 'Misi belum selesai.'];
            }
            $xp = $q['xp']; $coins = $q['coins']; $label = 'Misi harian: ' . $q['title'];
        }
        $pdo = Database::get();
        $ins = $pdo->prepare('INSERT OR IGNORE INTO daily_quest_claims (user_id, day, quest) VALUES (?, ?, ?)');
        $ins->execute([$userId, $day, $quest]);
        if ($ins->rowCount() === 0) {
            return ['status' => 'error', 'message' => 'Hadiah sudah diambil.'];
        }
        $key = 'daily-' . $userId . '-' . $day . '-' . $quest;
        if ($xp > 0) {
            Gameplay::grantXP($userId, $xp, 'daily_quest', $quest, $label, $key . '-xp');
        }
        if ($coins > 0) {
            Gameplay::grantCoins($userId, $coins, 'daily_quest', $quest, $label, $key . '-c');
        }
        $out = self::status($userId);
        $out['reward'] = ['xp' => $xp, 'coins' => $coins, 'label' => $label];
        $out['coins'] = (int)Gameplay::getProgress($userId)['coins'];
        return $out;
    }
}
