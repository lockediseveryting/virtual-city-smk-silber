<?php
// BotMenus: UI/UX menu templates, keyboards, and content builders for Telegram Bot.
// Formatted with HTML parse_mode, concise layout (< 1000 chars for photo captions),
// and intuitive inline navigation.
declare(strict_types=1);

namespace VC\TgBot;

use VC\Ai\Providers;
use VC\Database;
use VC\Domain\Events;
use VC\Domain\Gameplay;
use VC\Domain\Learning;
use VC\Domain\Notification;
use VC\Domain\Schedule;
use VC\Security\Permissions;

final class BotMenus
{
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    public static function siteUrl(): string
    {
        return rtrim((string)\vc_env('SITE_URL', 'http://xox.smksilber.cloud'), '/');
    }

    // =========================================================================
    // 1. MENU UTAMA (MAIN MENU)
    // =========================================================================

    public static function mainMenuCaption(?array $user): string
    {
        $statusStr = $user !== null
            ? '✅ <b>' . self::esc((string)$user['full_name']) . '</b> (' . self::esc((string)$user['role']) . ')'
            : '🔒 <i>Belum Ditautkan</i>';

        return "🏫 <b>VIRTUAL CITY SMK SILBER</b>\n\n"
            . "Selamat datang di Virtual City! Jelajahi informasi sekolah, profil akun, koin, inventaris, aktivitas komunitas, dan fitur virtual campus melalui bot ini.\n\n"
            . "👤 <b>Status Akun:</b> {$statusStr}\n\n"
            . "Gunakan tombol di bawah untuk mulai menjelajah:";
    }

    public static function mainMenuKeyboard(?array $user): array
    {
        $rows = [
            [
                ['text' => '👤 Profil Saya', 'callback_data' => 'menu:profile'],
                ['text' => '🎮 Virtual City', 'callback_data' => 'menu:game'],
            ],
            [
                ['text' => '🪙 Koin Saya', 'callback_data' => 'menu:coins'],
                ['text' => '🎒 Inventaris', 'callback_data' => 'menu:inv'],
            ],
            [
                ['text' => '🛒 Toko Avatar', 'callback_data' => 'menu:shop'],
                ['text' => '🏫 Info Sekolah', 'callback_data' => 'menu:school'],
            ],
            [
                ['text' => '📅 Jadwal', 'callback_data' => 'menu:schedule'],
                ['text' => '📚 Tugas', 'callback_data' => 'menu:tasks'],
            ],
            [
                ['text' => '🎉 Event', 'callback_data' => 'menu:events'],
                ['text' => '🤖 AI Assistant', 'callback_data' => 'menu:ai'],
            ],
            [
                ['text' => '🔔 Notifikasi', 'callback_data' => 'menu:notif'],
                ['text' => '⚙️ Pengaturan', 'callback_data' => 'menu:settings'],
            ],
            [
                ['text' => ($user !== null ? '🔗 Info Akun' : '🔗 Hubungkan Akun'), 'callback_data' => 'menu:link'],
                ['text' => '❓ Bantuan', 'callback_data' => 'menu:help'],
            ],
        ];

        if ($user !== null && self::hasAdminAccess($user)) {
            $rows[] = [
                ['text' => '🛡️ Panel Administrator', 'callback_data' => 'menu:admin'],
            ];
        }

        return ['inline_keyboard' => $rows];
    }

    public static function hasAdminAccess(array $user): bool
    {
        return Permissions::isSuperuser($user)
            || Permissions::canFor($user, 'user.view')
            || Permissions::canFor($user, 'user.approve_registration')
            || Permissions::canFor($user, 'role.assign');
    }

    // =========================================================================
    // 2. PROFIL SAYA (PROFILE)
    // =========================================================================

    public static function profileMenuText(?array $user): string
    {
        if ($user === null) {
            return "👤 <b>Profil Saya</b>\n\n"
                . "🔒 <b>Akun Belum Ditautkan</b>\n\n"
                . "Tautkan akun Virtual City milikmu untuk melihat statistik, level, karakter, saldo koin, dan tugas sekolah.\n\n"
                . "Langkah menautkan:\n"
                . "1. Buka aplikasi web Virtual City\n"
                . "2. Masuk ke halaman <b>/telegram</b>\n"
                . "3. Klik <i>Buat Kode Tautan</i>\n"
                . "4. Kirim di bot: <code>/link KODE</code>";
        }

        $userId = (int)$user['id'];
        $prog = Gameplay::getProgress($userId);
        $avatar = Gameplay::getActiveAvatar($userId);

        return "👤 <b>Profil Pengguna</b>\n\n"
            . "• Nama: <b>" . self::esc((string)$user['full_name']) . "</b>\n"
            . "• Username: <code>" . self::esc((string)$user['username']) . "</code>\n"
            . "• Role: <b>" . self::esc((string)$user['role']) . "</b>\n"
            . "• Level: <b>" . (int)($prog['level'] ?? 1) . "</b> (XP: " . (int)($prog['total_xp'] ?? 0) . ")\n"
            . "• Saldo Koin: <b>" . (int)($prog['coins'] ?? 0) . "</b> 🪙\n"
            . "• Karakter: <b>" . self::esc((string)$avatar['name']) . "</b>\n"
            . "• Status Akun: <b>" . self::esc((string)$user['status']) . "</b>\n\n"
            . "Pilih menu profil di bawah:";
    }

    public static function profileMenuKeyboard(?array $user): array
    {
        if ($user === null) {
            return ['inline_keyboard' => [
                [['text' => '🔗 Cara Hubungkan Akun', 'callback_data' => 'menu:link']],
                [['text' => '🏠 Beranda', 'callback_data' => 'menu:home']],
            ]];
        }

        return ['inline_keyboard' => [
            [
                ['text' => '👤 Detail Akun', 'callback_data' => 'profile:view'],
                ['text' => '🎭 Karakter Terpilih', 'callback_data' => 'profile:char'],
            ],
            [
                ['text' => '📊 Status & Role', 'callback_data' => 'profile:status'],
                ['text' => '📜 Riwayat Aktivitas', 'callback_data' => 'profile:history'],
            ],
            [
                ['text' => '📱 Status Telegram', 'callback_data' => 'profile:tg'],
                ['text' => '🪙 Saldo Koin', 'callback_data' => 'menu:coins'],
            ],
            [
                ['text' => '🎒 Inventaris Saya', 'callback_data' => 'menu:inv'],
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    public static function profileDetailText(?array $user, string $sub): string
    {
        if ($user === null) {
            return "🔒 Silakan tautkan akun terlebih dahulu.";
        }
        $userId = (int)$user['id'];

        switch ($sub) {
            case 'view':
                $prog = Gameplay::getProgress($userId);
                $nis = !empty($user['nis']) ? self::esc((string)$user['nis']) : '-';
                return "👤 <b>Detail Akun Virtual City</b>\n\n"
                    . "• ID Pengguna: <code>#{$userId}</code>\n"
                    . "• Nama Lengkap: <b>" . self::esc((string)$user['full_name']) . "</b>\n"
                    . "• Username: <code>" . self::esc((string)$user['username']) . "</code>\n"
                    . "• NIS / NIP: <code>{$nis}</code>\n"
                    . "• Peran (Role): <b>" . self::esc((string)$user['role']) . "</b>\n"
                    . "• Status: <b>" . self::esc((string)$user['status']) . "</b>\n"
                    . "• Level Permainan: <b>" . (int)$prog['level'] . "</b>\n"
                    . "• Total XP: <b>" . (int)$prog['total_xp'] . "</b>\n"
                    . "• Saldo Koin: <b>" . (int)$prog['coins'] . "</b> 🪙\n"
                    . "• Streak Login: <b>" . (int)$prog['login_streak'] . " hari</b> berturut-turut";

            case 'char':
                $avatar = Gameplay::getActiveAvatar($userId);
                $cfg = $avatar['config'] ?? [];
                $outfit = self::esc((string)($cfg['outfit'] ?? 'casual'));
                $hair = self::esc((string)($cfg['hair'] ?? 'neat_short'));
                $accessory = self::esc((string)($cfg['accessory'] ?? 'none'));
                return "🎭 <b>Karakter & Gaya Terpilih</b>\n\n"
                    . "• Model Karakter: <b>" . self::esc((string)$avatar['name']) . "</b>\n"
                    . "• Slug Avatar: <code>" . self::esc((string)$avatar['slug']) . "</code>\n"
                    . "• Outfit: <b>{$outfit}</b>\n"
                    . "• Gaya Rambut: <b>{$hair}</b>\n"
                    . "• Aksesori: <b>{$accessory}</b>\n\n"
                    . "💡 <i>Kustomisasi pakaian dan aksesori dapat diatur di Toko Avatar atau melalui game web.</i>";

            case 'status':
                $mustChange = ((int)($user['must_change_password'] ?? 0) === 1) ? 'Ya (wajib ganti)' : 'Tidak (aman)';
                $created = self::esc(substr((string)($user['created_at'] ?? '-'), 0, 19));
                return "📊 <b>Status Akun & Keamanan</b>\n\n"
                    . "• Status Akun: 🟢 <b>" . self::esc((string)$user['status']) . "</b>\n"
                    . "• Hak Akses: <b>" . self::esc((string)$user['role']) . "</b>\n"
                    . "• Terdaftar Sejak: <b>{$created}</b>\n"
                    . "• Perlu Ganti Password: <b>{$mustChange}</b>\n"
                    . "• Verifikasi: <b>Terverifikasi Server Virtual City</b>";

            case 'history':
                $pdo = Database::get();
                $stmt = $pdo->prepare('SELECT type, amount, description, created_at FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 5');
                $stmt->execute([$userId]);
                $rows = $stmt->fetchAll();
                if ($rows === []) {
                    return "📜 <b>Riwayat Aktivitas & Transaksi</b>\n\nBelum ada riwayat transaksi tercatat.";
                }
                $lines = ["📜 <b>5 Aktivitas / Transaksi Terakhir</b>\n"];
                foreach ($rows as $r) {
                    $sign = (int)$r['amount'] >= 0 ? '+' : '';
                    $unit = $r['type'] === 'coins' ? 'Koin 🪙' : 'XP ⭐';
                    $lines[] = "• <b>{$sign}{$r['amount']} {$unit}</b> — " . self::esc((string)$r['description'])
                        . "\n  <small class='muted'>" . self::esc(substr((string)$r['created_at'], 0, 16)) . "</small>";
                }
                return implode("\n", $lines);

            case 'tg':
                $link = AccountLink::linkForUser($userId);
                $linkedAt = $link ? self::esc(substr((string)$link['linked_at'], 0, 16)) : '-';
                $tgUser = $link ? self::esc((string)($link['tg_username'] ?: 'Tidak ada username')) : '-';
                return "📱 <b>Status Tautan Telegram</b>\n\n"
                    . "• Status: ✅ <b>Terhubung Resmi</b>\n"
                    . "• Telegram ID: <code>" . ($link['tg_user_id'] ?? '-') . "</code>\n"
                    . "• Username Telegram: <b>{$tgUser}</b>\n"
                    . "• Waktu Ditautkan: <b>{$linkedAt}</b>\n\n"
                    . "Akun ini aman dan diotentikasi melalui kode satu kali dari web.";

            default:
                return "Informasi profil.";
        }
    }

    // =========================================================================
    // 3. VIRTUAL CITY (GAME)
    // =========================================================================

    public static function gameMenuText(): string
    {
        return "🎮 <b>VIRTUAL CITY SMK SILBER</b>\n\n"
            . "Virtual City SMK Silber adalah simulasi sekolah 3D digital twin sekolah. "
            . "Kamu dapat menjelajahi gedung, ruang kelas, lab komputer, lapangan, "
            . "bertemu guru dan siswa lain secara realtime, serta mengumpulkan XP dan koin.\n\n"
            . "🕹️ <b>Platform:</b> Browser Web (Desktop & Mobile PWA)\n"
            . "🌐 <b>Multiplayer:</b> Realtime WebSocket\n\n"
            . "Pilih panduan atau buka game langsung di bawah:";
    }

    public static function gameMenuKeyboard(): array
    {
        $site = self::siteUrl();
        return ['inline_keyboard' => [
            [
                ['text' => '🚀 Masuk Dunia 3D', 'url' => $site . '/dunia'],
                ['text' => '🧱 Masuk Pixel World', 'url' => $site . '/pixel'],
            ],
            [
                ['text' => '🗺️ Denah Sekolah 2D', 'url' => $site . '/peta'],
                ['text' => '🎯 Misi Sekolah', 'url' => $site . '/misi'],
            ],
            [
                ['text' => '📖 Tentang Game', 'callback_data' => 'game:about'],
                ['text' => '🕹️ Cara Bermain', 'callback_data' => 'game:howto'],
            ],
            [
                ['text' => '💻 Kontrol Desktop', 'callback_data' => 'game:desk'],
                ['text' => '📱 Kontrol Mobile', 'callback_data' => 'game:mob'],
            ],
            [
                ['text' => '🎭 Karakter Game', 'callback_data' => 'game:chars'],
                ['text' => '🌟 Fitur Unggulan', 'callback_data' => 'game:feats'],
            ],
            [
                ['text' => '🪙 Panduan Koin', 'callback_data' => 'game:cguide'],
                ['text' => '🎒 Panduan Inventaris', 'callback_data' => 'game:iguide'],
            ],
            [
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    public static function gameSectionText(string $sec): string
    {
        switch ($sec) {
            case 'about':
                return "📖 <b>Tentang Virtual City SMK Silber</b>\n\n"
                    . "Virtual City SMK Silber dibangun sebagai platform edukasi interaktif generasi baru. "
                    . "Siswa tidak hanya belajar secara konvensional, tetapi dapat merasakan suasana sekolah sekolah virtual "
                    . "lengkap dengan jadwal terintegrasi, tugas digital, dan mini-games kejuruan.";

            case 'howto':
                return "🕹️ <b>Cara Bermain</b>\n\n"
                    . "1. Buka Virtual City melalui web browser di HP atau Laptop.\n"
                    . "2. Login menggunakan akun siswa/guru SMK Silber.\n"
                    . "3. Pilih avatar karakter favoritmu.\n"
                    . "4. Masuki gerbang sekolah dan temui NPC untuk petunjuk.\n"
                    . "5. Datangi ruang kelas sesuai jadwal pelajaran hari ini.\n"
                    . "6. Kerjakan kuis materi untuk mendapatkan Koin dan XP!";

            case 'desk':
                return "💻 <b>Kontrol Keyboard & Mouse (Desktop)</b>\n\n"
                    . "• <b>W / A / S / D</b> atau <b>Arrow Keys</b>: Berjalan\n"
                    . "• <b>Spasi</b>: Melompat\n"
                    . "• <b>Shift</b>: Berlari cepat (Sprint)\n"
                    . "• <b>E</b> atau <b>Enter</b>: Interaksi dengan pintu / NPC\n"
                    . "• <b>M</b>: Buka peta sekolah 2D / 3D\n"
                    . "• <b>Tab</b>: Buka daftar pemain online";

            case 'mob':
                return "📱 <b>Kontrol Sentuh (Smartphone & Tablet)</b>\n\n"
                    . "• <b>Virtual Joystick Kiri</b>: Menggerakkan karakter.\n"
                    . "• <b>Swipe Layar Kanan</b>: Mengatur sudut pandang kamera.\n"
                    . "• <b>Tombol Aksi (Lompat / Interaksi)</b> di sisi kanan bawah layar.\n"
                    . "• Aplikasi dapat diinstall ke homescreen sebagai PWA (Progressive Web App).";

            case 'chars':
                return "🎭 <b>Pemilihan Karakter Avatar</b>\n\n"
                    . "Tersedia 6 preset karakter ikonik SMK Silber:\n"
                    . "1. <b>Siswa Casual</b> (Default)\n"
                    . "2. <b>Cyber Tech TJKT</b> (Spesialis Jaringan & Terminal)\n"
                    . "3. <b>RPL Coder</b> (Programmer & Software Developer)\n"
                    . "4. <b>DKV Creative</b> (Desainer Grafis & Kreator)\n"
                    . "5. <b>Silber Varsity</b> (Sporty & Komunitas)\n"
                    . "6. <b>Siswa Berhijab</b> (Elegan & Santun)";

            case 'feats':
                return "🌟 <b>Fitur Unggulan Virtual City</b>\n\n"
                    . "• <b>Multiplayer Realtime</b>: Bertemu siswa lain secara langsung.\n"
                    . "• <b>Digital Twin Sekolah</b>: Tata letak gedung asli SMK Silber.\n"
                    . "• <b>Integrasi LMS & Jadwal</b>: Jadwal dan tugas tersinkronisasi.\n"
                    . "• <b>Ekonomi Sekolah</b>: Dapatkan koin dari tugas dan kuis.\n"
                    . "• <b>Toko Kosmetik</b>: Kostum dan aksesori resmi sekolah.";

            case 'cguide':
                return "🪙 <b>Panduan Koin & Level</b>\n\n"
                    . "Koin adalah mata uang resmi dalam Virtual City.\n"
                    . "• Menyelesaikan kuis: +15 s.d +50 koin\n"
                    . "• Presensi event sekolah: +50 s.d +200 koin\n"
                    . "• Streak login harian: +10 koin bertambah per hari\n"
                    . "Koin dapat digunakan untuk membeli karakter dan pakaian di Toko Avatar.";

            case 'iguide':
                return "🎒 <b>Panduan Inventaris</b>\n\n"
                    . "Item kosmetik yang dibeli di Toko Avatar akan tersimpan permanen di Inventaris akunmu. "
                    . "Kamu bisa memasang (equip) atau melepas item kapan saja melalui bot atau pengaturan karakter di web.";

            default:
                return "Informasi Virtual City.";
        }
    }

    // =========================================================================
    // 4. KOIN DAN EKONOMI (COINS & ECONOMY)
    // =========================================================================

    public static function coinsMenuText(?array $user): string
    {
        if ($user === null) {
            return "🪙 <b>Koin & Ekonomi</b>\n\n🔒 Tautkan akunmu untuk melihat saldo koin dan riwayat transaksi.";
        }

        $userId = (int)$user['id'];
        $prog = Gameplay::getProgress($userId);

        return "🪙 <b>Dompet Koin & Progres Ekonomi</b>\n\n"
            . "• Saldo Koin: <b>" . (int)$prog['coins'] . "</b> 🪙\n"
            . "• Level Pemain: <b>" . (int)$prog['level'] . "</b>\n"
            . "• Total XP: <b>" . (int)$prog['total_xp'] . "</b> ⭐\n"
            . "• Streak Login: <b>" . (int)$prog['login_streak'] . " hari</b>\n\n"
            . "Koin didapatkan melalui prestasi belajar, kuis materi, dan kegiatan sekolah. Gunakan koinmu di Toko Avatar!";
    }

    public static function coinsMenuKeyboard(?array $user): array
    {
        if ($user === null) {
            return ['inline_keyboard' => [
                [['text' => '🔗 Tautkan Akun', 'callback_data' => 'menu:link']],
                [['text' => '🏠 Beranda', 'callback_data' => 'menu:home']],
            ]];
        }

        return ['inline_keyboard' => [
            [
                ['text' => '📜 Riwayat Transaksi', 'callback_data' => 'coins:hist'],
                ['text' => '💡 Cara Dapat Koin', 'callback_data' => 'coins:earn'],
            ],
            [
                ['text' => '🛒 Buka Toko Avatar', 'callback_data' => 'menu:shop'],
                ['text' => '📊 Aturan Ekonomi', 'callback_data' => 'coins:guide'],
            ],
            [
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    // =========================================================================
    // 5. INVENTARIS (INVENTORY)
    // =========================================================================

    public static function inventoryMenuText(?array $user): string
    {
        if ($user === null) {
            return "🎒 <b>Inventaris Saya</b>\n\n🔒 Tautkan akun terlebih dahulu untuk melihat koleksi item kamu.";
        }

        $userId = (int)$user['id'];
        try {
            $items = array_values(array_filter(Gameplay::getShopItems($userId), static fn($i) => (int)($i['is_owned'] ?? 0) === 1));
        } catch (\Throwable $e) {
            $items = [];
        }

        if ($items === []) {
            return "🎒 <b>Inventaris Saya</b>\n\n"
                . "Koleksi item kosmetik kamu masih kosong.\n\n"
                . "💡 Kunjungi <b>Toko Avatar</b> untuk menukarkan koinmu dengan kostum, jaket, dan aksesori keren!";
        }

        $lines = ["🎒 <b>Koleksi Inventaris (" . count($items) . " Item)</b>\n"];
        foreach ($items as $it) {
            $eq = ((int)($it['is_equipped'] ?? 0) === 1) ? ' ✨ <i>(Sedang Dipakai)</i>' : '';
            $cat = self::esc(ucfirst((string)($it['category'] ?? 'Item')));
            $lines[] = "• <b>" . self::esc((string)$it['name']) . "</b> [{$cat}]{$eq}";
        }

        return implode("\n", $lines);
    }

    public static function inventoryMenuKeyboard(?array $user): array
    {
        if ($user === null) {
            return ['inline_keyboard' => [
                [['text' => '🔗 Tautkan Akun', 'callback_data' => 'menu:link']],
                [['text' => '🏠 Beranda', 'callback_data' => 'menu:home']],
            ]];
        }

        return ['inline_keyboard' => [
            [
                ['text' => '🛒 Beli Item di Toko', 'callback_data' => 'menu:shop'],
                ['text' => '📘 Panduan Inventaris', 'callback_data' => 'game:iguide'],
            ],
            [
                ['text' => '🎭 Karakter Terpilih', 'callback_data' => 'profile:char'],
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    // =========================================================================
    // 6. TOKO AVATAR (SHOP)
    // =========================================================================

    public static function shopMenuText(?array $user, string $filterCat = 'all'): string
    {
        $userId = $user !== null ? (int)$user['id'] : 0;
        $coins = 0;
        if ($userId > 0) {
            $prog = Gameplay::getProgress($userId);
            $coins = (int)($prog['coins'] ?? 0);
        }

        try {
            $items = Gameplay::getShopItems($userId);
        } catch (\Throwable $e) {
            $items = [];
        }

        if ($filterCat !== 'all') {
            $items = array_values(array_filter($items, static fn($i) => ($i['category'] ?? '') === $filterCat));
        }

        $lines = [
            "🛒 <b>TOKO AVATAR SMK SILBER</b>",
            $userId > 0 ? "Saldo Koin Kamu: <b>{$coins}</b> 🪙\n" : "<i>(Tautkan akun untuk melakukan pembelian)</i>\n",
            "Kategori: <b>" . self::esc(strtoupper($filterCat)) . "</b>",
        ];

        if ($items === []) {
            $lines[] = "\nTidak ada item pada kategori ini.";
        } else {
            $lines[] = "\nDaftar Katalog:";
            $maxShow = min(8, count($items));
            for ($i = 0; $i < $maxShow; $i++) {
                $it = $items[$i];
                $owned = (int)($it['is_owned'] ?? 0) === 1 ? ' [Milikmu]' : (' - ' . (int)$it['price_coins'] . ' 🪙');
                $lines[] = "• <b>" . self::esc((string)$it['name']) . "</b>{$owned}";
            }
            if (count($items) > $maxShow) {
                $lines[] = "<i>… dan " . (count($items) - $maxShow) . " item lainnya.</i>";
            }
        }

        return implode("\n", $lines);
    }

    public static function shopMenuKeyboard(?array $user, string $filterCat = 'all'): array
    {
        $userId = $user !== null ? (int)$user['id'] : 0;
        $items = Gameplay::getShopItems($userId);
        if ($filterCat !== 'all') {
            $items = array_values(array_filter($items, static fn($i) => ($i['category'] ?? '') === $filterCat));
        }

        // Show buttons for top 6 items
        $itemRows = [];
        $slice = array_slice($items, 0, 6);
        foreach ($slice as $it) {
            $id = (int)$it['id'];
            $name = (string)$it['name'];
            $isOwned = (int)($it['is_owned'] ?? 0) === 1;
            $btnText = $isOwned ? "✅ {$name}" : "🛒 {$name} ({$it['price_coins']} 🪙)";
            $itemRows[] = [['text' => $btnText, 'callback_data' => "shop:item:{$id}"]];
        }

        $catRow = [
            ['text' => 'Semua', 'callback_data' => 'shop:cat:all'],
            ['text' => 'Karakter', 'callback_data' => 'shop:cat:character'],
            ['text' => 'Pakaian', 'callback_data' => 'shop:cat:outfit'],
            ['text' => 'Aksesori', 'callback_data' => 'shop:cat:accessory'],
        ];

        $navRow = [
            ['text' => '🎒 Inventaris', 'callback_data' => 'menu:inv'],
            ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
        ];

        return ['inline_keyboard' => array_merge($itemRows, [$catRow], [$navRow])];
    }

    public static function shopItemDetailText(?array $user, int $itemId): array
    {
        $userId = $user !== null ? (int)$user['id'] : 0;
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM shop_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $it = $stmt->fetch();

        if (!$it) {
            return ['text' => '⚠️ Item toko tidak ditemukan.', 'kb' => ['inline_keyboard' => [[['text' => '⬅️ Kembali ke Toko', 'callback_data' => 'menu:shop']]]]];
        }

        $coins = 0;
        $isOwned = false;
        if ($userId > 0) {
            $prog = Gameplay::getProgress($userId);
            $coins = (int)($prog['coins'] ?? 0);
            $chk = $pdo->prepare('SELECT 1 FROM user_items WHERE user_id = ? AND item_id = ?');
            $chk->execute([$userId, $itemId]);
            $isOwned = (bool)$chk->fetchColumn();
        }

        $price = (int)$it['price_coins'];
        $canAfford = ($coins >= $price);

        $text = "🛒 <b>Detail Item Toko</b>\n\n"
            . "• Nama: <b>" . self::esc((string)$it['name']) . "</b>\n"
            . "• Kategori: <b>" . self::esc(ucfirst((string)$it['category'])) . "</b>\n"
            . "• Harga: <b>{$price} Koin</b> 🪙\n"
            . "• Deskripsi: " . self::esc((string)($it['description'] ?: 'Item resmi Virtual City SMK Silber.')) . "\n\n"
            . ($userId > 0 ? "Saldo Koin Kamu: <b>{$coins}</b> 🪙\n" : "🔒 Tautkan akun untuk membeli item ini.\n")
            . "Status Kepemilikan: " . ($isOwned ? "✅ <b>Sudah Dimiliki</b>" : "❌ <b>Belum Dimiliki</b>");

        $kbRows = [];
        if ($userId > 0 && !$isOwned) {
            if ($canAfford) {
                $kbRows[] = [['text' => "🛍️ Beli Sekarang ({$price} 🪙)", 'callback_data' => "shop:buy:{$itemId}"]];
            } else {
                $kbRows[] = [['text' => "⚠️ Saldo Koin Tidak Cukup ({$coins}/{$price})", 'callback_data' => 'coins:earn']];
            }
        } elseif ($isOwned) {
            $kbRows[] = [['text' => "🎒 Lihat di Inventaris", 'callback_data' => 'menu:inv']];
        }

        $kbRows[] = [
            ['text' => '⬅️ Kembali ke Toko', 'callback_data' => 'menu:shop'],
            ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
        ];

        return ['text' => $text, 'kb' => ['inline_keyboard' => $kbRows]];
    }

    // =========================================================================
    // 7. INFORMASI SEKOLAH (SCHOOL INFO)
    // =========================================================================

    public static function schoolMenuText(): string
    {
        return "🏫 <b>INFORMASI RESMI SMK SILBER</b>\n\n"
            . "SMK Silber adalah Sekolah Menengah Kejuruan unggulan berbasis teknologi "
            . "dengan lingkungan belajar modern dan digital twin Virtual Campus.\n\n"
            . "Pilih informasi yang ingin kamu ketahui:";
    }

    public static function schoolMenuKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '🏫 Tentang SMK Silber', 'callback_data' => 'school:about'],
                ['text' => '💻 Konsentrasi Keahlian', 'callback_data' => 'school:progs'],
            ],
            [
                ['text' => '🏢 Fasilitas & Gedung', 'callback_data' => 'school:fac'],
                ['text' => '🌐 Virtual Campus', 'callback_data' => 'school:campus'],
            ],
            [
                ['text' => '❓ FAQ Sekolah', 'callback_data' => 'school:faq'],
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    public static function schoolSectionText(string $sec): string
    {
        $pdo = Database::get();

        switch ($sec) {
            case 'about':
                return "🏫 <b>Tentang SMK Silber</b>\n\n"
                    . "SMK Silber mendidik generasi muda berkarakter, berdaya saing global, "
                    . "dan mahir dalam bidang teknologi informasi serta bisnis modern.\n\n"
                    . "• <b>Visi:</b> Menjadi pusat keunggulan vokasi teknologi terdepan.\n"
                    . "• <b>Misi:</b> Menyelenggarakan pembelajaran berbasis proyek industri, inovasi digital, dan integritas profesional.";

            case 'progs':
                $progs = $pdo->query('SELECT code, name FROM programs ORDER BY id ASC')->fetchAll();
                $lines = ["💻 <b>Konsentrasi Keahlian / Jurusan di SMK Silber</b>\n"];
                foreach ($progs as $p) {
                    $lines[] = "• <b>" . self::esc((string)$p['code']) . "</b> — " . self::esc((string)$p['name']);
                }
                $lines[] = "\nSetiap program keahlian didukung oleh laboratorium praktikum berstandar industri.";
                return implode("\n", $lines);

            case 'fac':
                $rooms = $pdo->query('SELECT building, count(*) as count FROM rooms_physical GROUP BY building')->fetchAll();
                $lines = ["🏢 <b>Fasilitas Sekolah SMK Silber</b>\n"];
                foreach ($rooms as $r) {
                    $b = (string)$r['building'];
                    $label = match ($b) {
                        'A' => 'Gedung A (Ruang Kelas & Teori)',
                        'B' => 'Gedung B (Lab Komputer TJKT & RPL)',
                        'C' => 'Gedung C (Studio DKV & Lab Bisnis)',
                        'D' => 'Gedung D (Perpustakaan & Serbaguna)',
                        default => 'Area Sekolah ' . $b,
                    };
                    $lines[] = "• <b>{$label}</b>: " . (int)$r['count'] . " ruangan";
                }
                $lines[] = "• <b>Area Olahraga</b>: Lapangan Futsal, Basket, dan Upacara";
                return implode("\n", $lines);

            case 'campus':
                return "🌐 <b>Virtual Campus 3D</b>\n\n"
                    . "Sekolah Virtual SMK Silber menduplikasi seluruh denah gedung fisik ke dalam grafis 3D interaktif. "
                    . "Siswa dapat berinteraksi, menghadiri seminar daring di auditorium virtual, dan mengerjakan simulasi jaringan.";

            case 'faq':
                return "❓ <b>FAQ Sekolah & Virtual City</b>\n\n"
                    . "<b>T: Bagaimana cara melihat jadwal?</b>\n"
                    . "J: Tekan tombol 📅 Jadwal pada menu bot atau buka menu Jadwal di web.\n\n"
                    . "<b>T: Bagaimana cara mengumpulkan tugas?</b>\n"
                    . "J: Pengumpulan berkas tugas dilakukan di aplikasi web pada menu Pembelajaran.\n\n"
                    . "<b>T: Apa kegunaan Koin?</b>\n"
                    . "J: Koin digunakan untuk kustomisasi karakter di Toko Avatar.";

            default:
                return "Informasi Sekolah.";
        }
    }

    // =========================================================================
    // 8. JADWAL (SCHEDULE)
    // =========================================================================

    public static function scheduleMenuText(?array $user): string
    {
        if ($user === null) {
            return "📅 <b>Jadwal Pelajaran & Mengajar</b>\n\n🔒 Tautkan akunmu untuk melihat jadwal harian kelas atau jadwal mengajar.";
        }

        $role = (string)$user['role'];
        if ($role === 'murid' || $role === 'murid_akses') {
            $classId = (int)($user['class_id'] ?? 0);
            if ($classId <= 0) {
                return "📅 <b>Jadwal Pelajaran</b>\n\n⚠️ Kelasmu belum ditentukan di database sekolah. Hubungi wali kelas.";
            }
            $today = Schedule::getTodayScheduleForClass($classId);
            $lines = ["📅 <b>Jadwal Hari Ini: {$today['day_name']}</b> ({$today['date']})\n"];
            $count = 0;
            foreach ($today['schedule'] as $slot) {
                if (!empty($slot['is_break'])) {
                    $lines[] = "☕ <i>" . self::esc((string)$slot['label']) . "</i>";
                    continue;
                }
                $e = $slot['entry'] ?? null;
                if ($e === null) continue;
                $count++;
                $lines[] = sprintf("• Jam %d (%s): <b>%s</b>\n  Ruang: %s | Guru: %s",
                    (int)$slot['period_number'],
                    self::esc((string)($slot['start_time'] ?? '')),
                    self::esc((string)($e['subject_name'] ?? '-')),
                    self::esc((string)($e['room_name'] ?? 'Kelas')),
                    self::esc((string)($e['teacher_name'] ?? '-'))
                );
            }
            if ($count === 0) {
                $lines[] = "Tidak ada jadwal mata pelajaran hari ini. Selamat beristirahat! 🎉";
            }
            return implode("\n", $lines);
        }

        // Teacher / Staff schedule
        $teacherId = (int)$user['id'];
        $sched = Schedule::getTodayScheduleForTeacher($teacherId);
        $lines = ["📅 <b>Jadwal Mengajar Hari Ini: {$sched['day_name']}</b>\n"];
        if (empty($sched['entries'])) {
            $lines[] = "Tidak ada jadwal mengajar hari ini. 🎉";
        } else {
            foreach ($sched['entries'] as $e) {
                $lines[] = sprintf("• Jam %d: <b>%s</b> (Kelas %s)\n  Ruang: %s",
                    (int)$e['period_number'],
                    self::esc((string)($e['subject_name'] ?? '-')),
                    self::esc((string)($e['class_name'] ?? '-')),
                    self::esc((string)($e['room_name'] ?? 'Ruang'))
                );
            }
        }
        return implode("\n", $lines);
    }

    public static function scheduleMenuKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '📅 Hari Ini', 'callback_data' => 'sched:today'],
                ['text' => '🗓️ Pilih Hari', 'callback_data' => 'sched:days'],
            ],
            [
                ['text' => '📍 Lokasi Guru', 'callback_data' => 'sched:where'],
                ['text' => 'ℹ️ Panduan Jadwal', 'callback_data' => 'sched:guide'],
            ],
            [
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    // =========================================================================
    // 9. TUGAS (TASKS)
    // =========================================================================

    public static function tasksMenuText(?array $user, string $filter = 'active'): string
    {
        if ($user === null) {
            return "📚 <b>Tugas Sekolah</b>\n\n🔒 Tautkan akun terlebih dahulu untuk melihat tugas kelas kamu.";
        }

        $classId = (int)($user['class_id'] ?? 0);
        if ($classId <= 0) {
            return "📚 <b>Tugas Sekolah</b>\n\nAkun Anda bukan siswa atau kelas belum ditentukan.";
        }

        try {
            $all = Learning::assignmentsForClass($classId, (int)$user['id']);
        } catch (\Throwable $e) {
            $all = [];
        }

        if ($all === []) {
            return "📚 <b>Daftar Tugas Kelas</b>\n\n🎉 Tidak ada tugas aktif untuk kelasmu saat ini. Semua beres!";
        }

        $lines = ["📚 <b>Daftar Tugas (" . count($all) . " Tugas)</b>\n"];
        $i = 0;
        foreach ($all as $t) {
            if ($i++ >= 6) {
                $lines[] = "<i>… dan lainnya di aplikasi web.</i>";
                break;
            }
            $deadline = self::esc(substr((string)($t['deadline'] ?? '-'), 0, 16));
            $lines[] = "• <b>" . self::esc((string)$t['title']) . "</b>\n"
                . "  Mapel: " . self::esc((string)($t['subject_name'] ?? 'Pelajaran'))
                . " | Tenggat: <b>{$deadline}</b>";
        }

        return implode("\n", $lines);
    }

    public static function tasksMenuKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '⏳ Tenggat Terdekat', 'callback_data' => 'tasks:act'],
                ['text' => '✅ Tugas Selesai', 'callback_data' => 'tasks:done'],
            ],
            [
                ['text' => 'ℹ️ Panduan Pengumpulan', 'callback_data' => 'tasks:guide'],
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    // =========================================================================
    // 10. EVENT DAN AKTIVITAS (EVENTS)
    // =========================================================================

    public static function eventsMenuText(): string
    {
        try {
            $events = Events::getUpcomingEvents(5);
        } catch (\Throwable $e) {
            $events = [];
        }

        if ($events === []) {
            return "🎉 <b>Event & Aktivitas Sekolah</b>\n\nBelum ada event mendatang yang terdaftar saat ini.";
        }

        $lines = ["🎉 <b>Agenda Event Mendatang</b>\n"];
        foreach ($events as $e) {
            $start = self::esc(substr((string)($e['start_time'] ?? '-'), 0, 16));
            $lines[] = "• <b>" . self::esc((string)$e['title']) . "</b>\n"
                . "  Mulai: <b>{$start}</b> | Hadiah: +" . (int)($e['rewards_xp'] ?? 0) . " XP, +" . (int)($e['rewards_coins'] ?? 0) . " 🪙";
        }

        return implode("\n", $lines);
    }

    public static function eventsMenuKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '👥 Organisasi & Ekskul', 'callback_data' => 'events:orgs'],
                ['text' => '📜 Riwayat & Sertifikat', 'callback_data' => 'events:hist'],
            ],
            [
                ['text' => 'ℹ️ Panduan Partisipasi', 'callback_data' => 'events:guide'],
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    // =========================================================================
    // 11. AI ASSISTANT
    // =========================================================================

    public static function aiMenuText(): string
    {
        return "🤖 <b>AI ASSISTANT SMK SILBER</b>\n\n"
            . "Asisten AI cerdas multi-agen Virtual City siap membantumu belajar pemrograman, "
            . "memahami materi kejuruan, dan menjelajahi fitur sekolah.\n\n"
            . "💡 <b>Cara Bertanya Langsung:</b>\n"
            . "Kirim pesan di chat: <code>/ai Pertanyaanmu di sini</code>\n\n"
            . "Pilih menu AI di bawah:";
    }

    public static function aiMenuKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '📊 Status 3 Model AI', 'callback_data' => 'ai:status'],
                ['text' => '💻 Penjelasan IT & Koding', 'callback_data' => 'ai:it'],
            ],
            [
                ['text' => '📚 Bantuan Belajar', 'callback_data' => 'ai:study'],
                ['text' => '🎮 Bantuan Virtual City', 'callback_data' => 'ai:game'],
            ],
            [
                ['text' => '📜 Riwayat Job AI', 'callback_data' => 'ai:hist'],
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    public static function aiStatusText(): string
    {
        $statuses = Providers::allStatus();
        $lines = ["🤖 <b>Status Layanan 3 Agen AI Virtual City</b>\n"];

        foreach ($statuses as $role => $s) {
            $label = Providers::roleLabel($role);
            $st = $s['configured'] ? '🟢 <b>CONFIGURED</b>' : '🔴 <b>NOT CONFIGURED</b>';
            $prov = self::esc((string)($s['provider_name'] ?? $s['provider']));
            $model = self::esc((string)$s['model']);
            $lines[] = "• <b>{$label}</b>\n  Provider: <b>{$prov}</b>\n  Model: <code>{$model}</code>\n  Status: {$st}";
        }

        $lines[] = "\n<i>Setiap agen AI memiliki provider dan tanggung jawab independen.</i>";
        return implode("\n", $lines);
    }

    // =========================================================================
    // 12. NOTIFIKASI (NOTIFICATIONS)
    // =========================================================================

    public static function notifMenuText(?array $user): string
    {
        if ($user === null) {
            return "🔔 <b>Pusat Notifikasi</b>\n\n🔒 Tautkan akun untuk melihat notifikasi kamu.";
        }

        $userId = (int)$user['id'];
        $unread = Notification::unreadCount($userId);
        $list = Notification::forUser($userId, 5);

        $lines = ["🔔 <b>Pusat Notifikasi</b> (Belum dibaca: <b>{$unread}</b>)\n"];
        if ($list === []) {
            $lines[] = "Tidak ada notifikasi baru.";
        } else {
            foreach ($list as $n) {
                $icon = (int)$n['is_read'] === 0 ? '🔵' : '⚪';
                $lines[] = "{$icon} <b>" . self::esc((string)$n['title']) . "</b>\n  "
                    . self::esc((string)$n['body']) . "\n  <small class='muted'>" . self::esc(substr((string)$n['created_at'], 0, 16)) . "</small>";
            }
        }

        return implode("\n", $lines);
    }

    public static function notifMenuKeyboard(?array $user): array
    {
        if ($user === null) {
            return ['inline_keyboard' => [
                [['text' => '🔗 Tautkan Akun', 'callback_data' => 'menu:link']],
                [['text' => '🏠 Beranda', 'callback_data' => 'menu:home']],
            ]];
        }

        return ['inline_keyboard' => [
            [
                ['text' => '⚙️ Preferensi Kategori', 'callback_data' => 'notif:prefs'],
                ['text' => '🧹 Tandai Semua Dibaca', 'callback_data' => 'notif:readall'],
            ],
            [
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    public static function notifPrefsText(?array $user): string
    {
        if ($user === null) {
            return "🔒 Tautkan akun terlebih dahulu.";
        }
        return "⚙️ <b>Preferensi Notifikasi</b>\n\n"
            . "Atur kategori notifikasi yang ingin kamu terima di aplikasi.\n"
            . "Ketuk tombol untuk mengubah status aktif/nonaktif:";
    }

    public static function notifPrefsKeyboard(?array $user): array
    {
        if ($user === null) {
            return ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]];
        }

        $userId = (int)$user['id'];
        $prefs = Notification::prefs($userId);

        $rows = [];
        $cats = ['tugas' => 'Tugas', 'jadwal' => 'Jadwal', 'event' => 'Event', 'chat' => 'Chat', 'sosial' => 'Sosial'];
        foreach ($cats as $k => $label) {
            $active = $prefs[$k] ?? true;
            $icon = $active ? '✅' : '❌';
            $rows[] = [['text' => "{$icon} {$label}", 'callback_data' => "notif:tog:{$k}"]];
        }

        $rows[] = [
            ['text' => '⬅️ Kembali ke Notifikasi', 'callback_data' => 'menu:notif'],
            ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
        ];

        return ['inline_keyboard' => $rows];
    }

    // =========================================================================
    // 13. PENGATURAN (SETTINGS)
    // =========================================================================

    public static function settingsMenuText(?array $user): string
    {
        $statusStr = $user !== null
            ? '✅ Ditautkan ke <b>' . self::esc((string)$user['full_name']) . '</b>'
            : '🔒 Belum Ditautkan';

        return "⚙️ <b>PENGATURAN BOT & AKUN</b>\n\n"
            . "• Status Tautan: {$statusStr}\n"
            . "• Bahasa Bot: <b>Bahasa Indonesia</b>\n"
            . "• Privasi: <b>Enkripsi & Sesi Aman</b>\n\n"
            . "Pilih opsi pengaturan:";
    }

    public static function settingsMenuKeyboard(?array $user): array
    {
        $rows = [
            [
                ['text' => '📱 Status Akun Tertaut', 'callback_data' => 'profile:tg'],
                ['text' => '🔔 Preferensi Notifikasi', 'callback_data' => 'notif:prefs'],
            ],
            [
                ['text' => '🌐 Bahasa (ID)', 'callback_data' => 'set:lang'],
                ['text' => '🔒 Privasi & Keamanan', 'callback_data' => 'set:priv'],
            ],
        ];

        if ($user !== null) {
            $rows[] = [
                ['text' => '⚠️ Putuskan Tautan Akun', 'callback_data' => 'set:unlink'],
            ];
        }

        $rows[] = [
            ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
        ];

        return ['inline_keyboard' => $rows];
    }

    // =========================================================================
    // 14. BANTUAN (HELP)
    // =========================================================================

    public static function helpMenuText(): string
    {
        return "❓ <b>PUSAT BANTUAN VIRTUAL CITY BOT</b>\n\n"
            . "Bot ini membantu warga sekolah mengakses Virtual City SMK Silber kapan saja.\n\n"
            . "Perintah Cepat:\n"
            . "• <code>/start</code> — Menampilkan menu utama & banner\n"
            . "• <code>/menu</code> — Membuka kembali menu bot\n"
            . "• <code>/link KODE</code> — Menautkan akun dari web\n"
            . "• <code>/status</code> — Status profil akun\n"
            . "• <code>/jadwal</code> — Jadwal pelajaran hari ini\n"
            . "• <code>/tugas</code> — Daftar tugas kelas\n"
            . "• <code>/event</code> — Agenda event sekolah\n"
            . "• <code>/koin</code> — Saldo koin & level\n"
            . "• <code>/inventory</code> — Item yang kamu miliki\n"
            . "• <code>/ai PERTANYAAN</code> — Tanya AI Assistant\n"
            . "• <code>/help</code> — Bantuan lengkap";
    }

    public static function helpMenuKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '📖 Panduan Penggunaan', 'callback_data' => 'help:guide'],
                ['text' => '⌨️ Daftar Semua Command', 'callback_data' => 'help:cmds'],
            ],
            [
                ['text' => '🔗 Cara Menautkan Akun', 'callback_data' => 'menu:link'],
                ['text' => '❓ FAQ Bot', 'callback_data' => 'help:faq'],
            ],
            [
                ['text' => '🛡️ Status Sistem', 'callback_data' => 'help:stat'],
                ['text' => '🚨 Laporkan Masalah', 'callback_data' => 'help:rep'],
            ],
            [
                ['text' => '🏠 Kembali ke Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    // =========================================================================
    // 14b. TAUTKAN AKUN (ACCOUNT LINKING GUIDE)
    // =========================================================================

    public static function linkAccountText(): string
    {
        $site = self::siteUrl();
        return "🔗 <b>Menghubungkan Akun Virtual City SMK Silber</b>\n\n"
            . "Tautkan akun Telegram Anda dengan akun resmi Virtual City untuk melihat data profil, saldo koin, inventaris, jadwal kelas, tugas, dan notifikasi sekolah secara langsung.\n\n"
            . "📋 <b>Langkah-Langkah Menautkan Akun:</b>\n"
            . "1️⃣ Buka website Virtual City di browser:\n"
            . "    👉 <b><a href=\"{$site}/\">{$site}</a></b>\n"
            . "2️⃣ Masuk (Login) menggunakan akun Anda (atau daftar baru jika belum memiliki akun).\n"
            . "3️⃣ Masuk ke menu Telegram pada website:\n"
            . "    👉 <b><a href=\"{$site}/telegram\">{$site}/telegram</a></b>\n"
            . "4️⃣ Klik tombol <b>\"Buat Kode Tautan\"</b> pada layar website untuk mendapatkan 8 karakter kode unik (contoh: <code>K9W3R7PX</code>).\n"
            . "5️⃣ Kembali ke chat bot Telegram ini, lalu kirim perintah:\n"
            . "    <code>/link KODE</code>\n"
            . "    <i>Contoh:</i> <code>/link K9W3R7PX</code> (atau cukup ketik <code>K9W3R7PX</code> saja).\n\n"
            . "⏱️ <i>Kode hanya berlaku selama 10 menit dan sekali pakai demi keamanan akun Anda.</i>";
    }

    public static function linkAccountKeyboard(): array
    {
        $site = self::siteUrl();
        return ['inline_keyboard' => [
            [
                ['text' => '🌐 Buka /telegram di Website', 'url' => "{$site}/telegram"],
            ],
            [
                ['text' => '🏫 Buka Website Virtual City', 'url' => "{$site}/"],
            ],
            [
                ['text' => '🏠 Kembali ke Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }

    // =========================================================================
    // 15. ADMINISTRATOR MENU
    // =========================================================================

    public static function adminDashboardText(?array $user): string
    {
        $pdo = Database::get();
        $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn();
        $activeLinks = (int)$pdo->query('SELECT COUNT(*) FROM telegram_links')->fetchColumn();
        $pendingRegs = (int)$pdo->query('SELECT COUNT(*) FROM registrations WHERE status = "pending"')->fetchColumn();

        return "🛡️ <b>PANEL ADMINISTRATOR TELEGRAM</b>\n\n"
            . "Selamat datang, <b>" . self::esc((string)$user['full_name']) . "</b> (" . self::esc((string)$user['role']) . ").\n\n"
            . "📊 <b>Statistik Cepat:</b>\n"
            . "• Total Pengguna Aktif: <b>{$totalUsers}</b>\n"
            . "• Akun Telegram Tertaut: <b>{$activeLinks}</b>\n"
            . "• Pendaftaran Tertunda: <b>{$pendingRegs}</b>\n\n"
            . "Pilih menu manajemen:";
    }

    public static function adminDashboardKeyboard(?array $user): array
    {
        $rows = [
            [
                ['text' => '🤖 Status Bot & Webhook', 'callback_data' => 'adm:bot'],
                ['text' => '🗄️ Status Database', 'callback_data' => 'adm:db'],
            ],
            [
                ['text' => '🧠 Status 3 Model AI', 'callback_data' => 'adm:ai'],
                ['text' => '👥 Ringkasan Pengguna', 'callback_data' => 'adm:users:all:1'],
            ],
            [
                ['text' => '👥 Kelola Staff & Akun Web', 'callback_data' => 'adm:staff:menu'],
            ],
            [
                ['text' => '📝 Pendaftar Baru', 'callback_data' => 'adm:regs'],
                ['text' => '📜 Audit Log Singkat', 'callback_data' => 'adm:audit'],
            ],
        ];

        if (Permissions::isSuperuser($user)) {
            $rows[] = [
                ['text' => '🔓 Bersihkan Blokir IP', 'callback_data' => 'adm:unban_ask'],
            ];
        }

        $rows[] = [
            ['text' => '📘 Panduan Admin', 'callback_data' => 'adm:help'],
            ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
        ];

        return ['inline_keyboard' => $rows];
    }

    public static function staffManagementMenuText(): string
    {
        $pdo = Database::get();
        $totalStaff = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("root", "kepala_sekolah", "kepala_program", "guru", "wali_kelas", "pembina", "pengurus") AND deleted_at IS NULL')->fetchColumn();
        $linkedStaff = (int)$pdo->query('SELECT COUNT(*) FROM users u JOIN telegram_links tl ON u.id = tl.user_id WHERE u.role IN ("root", "kepala_sekolah", "kepala_program", "guru", "wali_kelas", "pembina", "pengurus") AND u.deleted_at IS NULL')->fetchColumn();

        return "👥 <b>MANAJEMEN STAF, GURU & AKUN WEB</b>\n\n"
            . "Kelola staf pengajar dan administrator SMK Silber secara langsung. Anda dapat menambahkan staf baru, membuat akun login web, menghapus/menonaktifkan, atau mereset sandi.\n\n"
            . "• Total Staf Aktif: <b>{$totalStaff}</b> akun\n"
            . "• Staf Terhubung Telegram: <b>{$linkedStaff}</b> akun\n\n"
            . "Pilih menu aksi:";
    }

    public static function staffManagementMenuKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '➕ Tambah Staff (/addstaff)', 'callback_data' => 'adm:staff:add_guide'],
                ['text' => '📋 Daftar Staff Aktif', 'callback_data' => 'adm:staff:list'],
            ],
            [
                ['text' => '🗑️ Hapus Staff (/delstaff)', 'callback_data' => 'adm:staff:del_guide'],
                ['text' => '🔑 Reset Password Web', 'callback_data' => 'adm:staff:reset_guide'],
            ],
            [
                ['text' => '🔄 Ubah Role Pengguna', 'callback_data' => 'adm:staff:role_guide'],
                ['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin'],
            ],
            [
                ['text' => '🏠 Kembali ke Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];
    }
}
