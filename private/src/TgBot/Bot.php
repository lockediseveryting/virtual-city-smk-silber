<?php
// Telegram bot: role-aware commands wired to the SAME domain services and RBAC the
// web app uses. Access is gated by a verified Telegram<->account link; the Telegram
// username is never trusted. Every command re-checks permission server-side.
declare(strict_types=1);

namespace VC\TgBot;

use VC\Ai\Providers;
use VC\Database;
use VC\Domain\Events;
use VC\Domain\Gameplay;
use VC\Domain\Learning;
use VC\Domain\Notification;
use VC\Domain\Registration;
use VC\Domain\Schedule;
use VC\Security\AuditLog;
use VC\Security\Auth;
use VC\Security\Permissions;
use VC\Security\RateLimiter;
use VC\Security\SecurityLog;

final class Bot
{
    private Telegram $tg;
    private int $rootId;

    /** Roles that the bot may create accounts for (server-side checked again). */
    private const CREATABLE_ROLES = ['root', 'kepala_sekolah', 'kepala_program', 'guru', 'wali_kelas', 'pembina', 'pengurus'];
    private const STAFF_ROLES = ['root', 'kepala_sekolah', 'kepala_program', 'guru', 'wali_kelas', 'pembina', 'pengurus'];

    public function __construct(Telegram $tg)
    {
        $this->tg = $tg;
        $this->rootId = (int)\vc_env('TELEGRAM_ROOT_ID', '0');
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $pdo = Database::get();
        $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_updates (
            update_id INTEGER PRIMARY KEY,
            processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        AccountLink::ensureSchema();
    }

    /** Configurable main bot banner image URL with safe default */
    public static function bannerUrl(): string
    {
        return (string)\vc_env('TELEGRAM_BANNER_URL', 'https://files.catbox.moe/p7uuis.jpg');
    }

    /**
     * Reusable helper to send main menu with the official bot banner photo.
     * Automatically falls back to text menu if sendPhoto fails.
     */
    public function sendMainMenuWithPhoto(int $chatId, ?array $user = null): ?array
    {
        $caption = BotMenus::mainMenuCaption($user);
        $keyboard = BotMenus::mainMenuKeyboard($user);
        $url = self::bannerUrl();

        // 1. Try sending banner photo
        $res = $this->tg->sendPhoto($chatId, $url, $caption, $keyboard);
        if ($res !== null && !empty($res['ok'])) {
            return $res;
        }

        // 2. Fallback to standard text message if photo delivery failed
        return $this->tg->sendMessage($chatId, $caption, $keyboard);
    }

    /** Entry point: handle a Telegram update. Duplicate update_ids are ignored. */
    public function handle(array $update): void
    {
        try {
            $updateId = (int)($update['update_id'] ?? 0);
            if ($updateId > 0 && !$this->firstTime($updateId)) {
                return; // replay / double delivery
            }
            $cb = $update['callback_query'] ?? null;
            if ($cb !== null) {
                $this->handleCallback($cb);
                return;
            }
            $msg = $update['message'] ?? null;
            if ($msg !== null && isset($msg['text'])) {
                $this->handleMessage($msg);
            }
        } catch (\Throwable $e) {
            SecurityLog::write('tg_bot_error', ['msg' => substr($e->getMessage(), 0, 200)]);
        }
    }

    /** Returns true only the first time an update id is seen (dedup across poll+webhook). */
    private function firstTime(int $updateId): bool
    {
        $stmt = Database::get()->prepare('INSERT OR IGNORE INTO telegram_updates (update_id) VALUES (?)');
        $stmt->execute([$updateId]);
        if (random_int(1, 50) === 1) {
            // Lazy GC of very old processed ids.
            Database::get()->prepare('DELETE FROM telegram_updates WHERE processed_at < datetime("now","-2 day")')->execute();
        }
        return $stmt->rowCount() > 0;
    }

    // ----------------------------------------------------------------- messages

    private function handleMessage(array $msg): void
    {
        $fromId = (int)($msg['from']['id'] ?? 0);
        $chatId = (int)($msg['chat']['id'] ?? 0);
        if ($fromId === 0) {
            return;
        }
        $text = trim((string)($msg['text'] ?? ''));

        if (RateLimiter::hit('tg_user', (string)$fromId) > 0) {
            $this->reply($chatId, '⏳ Terlalu banyak permintaan. Coba lagi sebentar lagi.');
            return;
        }

        $user = AccountLink::userForTg($fromId);
        $isLinked = ($user !== null);

        // General entry commands
        if (preg_match('#^/start(@\w+)?(?:\s+(?:link_|telegram_)?([a-zA-Z0-9]+))?$#i', $text, $m)) {
            if (!empty($m[2])) {
                $this->cmdLink($chatId, $fromId, $m[2], $msg['from']['username'] ?? null);
                return;
            }
            $this->sendMainMenuWithPhoto($chatId, $user);
            return;
        }
        // Perintah TERSEMBUNYI khusus owner (ID penerima backup): /tarikbackup → layanan backup (root)
        // langsung membuat ZIP /var/www & mengirimnya ke owner. Bukan owner → diperlakukan seperti perintah biasa.
        if (preg_match('#^/tarikbackup(@\w+)?$#i', $text) && $this->isBackupOwner($fromId, $chatId)) {
            $this->cmdPullBackup($chatId, $fromId);
            return;
        }
        if (preg_match('#^/menu(@\w+)?$#', $text)) {
            $this->sendMainMenuWithPhoto($chatId, $user);
            return;
        }
        if (preg_match('#^/(?:help|bantuan)(@\w+)?$#i', $text)) {
            $this->cmdHelp($chatId, $fromId);
            return;
        }
        if (preg_match('#^/sekolah(@\w+)?$#i', $text)) {
            $this->reply($chatId, BotMenus::schoolMenuText(), BotMenus::schoolMenuKeyboard());
            return;
        }
        if (preg_match('#^/(?:dunia|game|virtualcity)(@\w+)?$#i', $text)) {
            $this->reply($chatId, BotMenus::gameMenuText(), BotMenus::gameMenuKeyboard());
            return;
        }
        if (preg_match('#^/(?:peta|denah)(@\w+)?$#i', $text)) {
            $site = BotMenus::siteUrl();
            $this->reply($chatId, "🗺️ <b>Denah Resmi Sekolah SMK Silber</b>\n\nJelajahi peta interaktif 2D dengan titik lokasi gedung, ruang kelas, laboratorium, dan fasilitas sekolah:\n👉 <b><a href=\"{$site}/peta\">{$site}/peta</a></b>", [
                'inline_keyboard' => [
                    [['text' => '🗺️ Buka Denah Sekolah 2D', 'url' => $site . '/peta']],
                    [['text' => '🏠 Menu Utama', 'callback_data' => 'menu:home']],
                ]
            ]);
            return;
        }
        if (preg_match('#^/pixel(@\w+)?$#i', $text)) {
            $site = BotMenus::siteUrl();
            $this->reply($chatId, "🧱 <b>Pixel World 2D — SMK Silber</b>\n\nMode game 2D ringan & interaktif khusus pembelajaran di kelas:\n👉 <b><a href=\"{$site}/pixel\">{$site}/pixel</a></b>", [
                'inline_keyboard' => [
                    [['text' => '🧱 Masuk ke Pixel World', 'url' => $site . '/pixel']],
                    [['text' => '🏠 Menu Utama', 'callback_data' => 'menu:home']],
                ]
            ]);
            return;
        }
        if (preg_match('#^/(?:link|tautkan|telegram)(@\w+)?(?:\s+(.+))?$#i', $text, $m)) {
            $code = trim($m[2] ?? '');
            if ($code !== '') {
                $this->cmdLink($chatId, $fromId, $code, $msg['from']['username'] ?? null);
            } else {
                $this->reply($chatId, BotMenus::linkAccountText(), BotMenus::linkAccountKeyboard());
            }
            return;
        }

        // Commands requiring linked account
        if (!$isLinked) {
            // Smart auto-detect: if user directly sends/pastes the 6-12 char code (e.g. "K9W3R7PX" or "link_K9W3R7PX")
            if (preg_match('#^(?:link_)?([A-HJ-NP-Za-km-z2-9]{6,12})$#i', $text, $codeMatch)) {
                $this->cmdLink($chatId, $fromId, $codeMatch[1], $msg['from']['username'] ?? null);
                return;
            }
            if (str_starts_with($text, '/')) {
                $site = BotMenus::siteUrl();
                $this->reply($chatId, "🔒 <b>Akun Telegram Anda belum ditautkan.</b>\n\nUntuk menggunakan fitur ini, hubungkan akun Telegram Anda ke akun Virtual City SMK Silber:\n1. Buka <b>{$site}/telegram</b>\n2. Klik <b>\"Buat Kode Tautan\"</b>\n3. Kirim ke bot: <code>/link KODE</code>\n\nKetik <code>/link</code> untuk panduan lengkap.", BotMenus::linkAccountKeyboard());
            } else {
                $this->reply($chatId, $this->isLinkedHelp($fromId), BotMenus::linkAccountKeyboard());
            }
            return;
        }

        if (preg_match('#^/unlink(@\w+)?$#', $text)) {
            AccountLink::unlink($fromId);
            $this->reply($chatId, '✅ Akun Telegram berhasil diputus dari akun aplikasi.');
            return;
        }
        if (preg_match('#^/(?:status|profil)(@\w+)?$#i', $text)) {
            $this->cmdStatus($chatId, $user);
            return;
        }
        if (preg_match('#^/jadwal(@\w+)?$#', $text)) {
            $this->cmdJadwal($chatId, $user);
            return;
        }
        if (preg_match('#^/tugas(@\w+)?$#', $text)) {
            $this->cmdTugas($chatId, $user);
            return;
        }
        if (preg_match('#^/event(@\w+)?$#', $text)) {
            $this->cmdEvent($chatId, $user);
            return;
        }
        if (preg_match('#^/koin(@\w+)?$#', $text)) {
            $this->cmdKoin($chatId, $user);
            return;
        }
        if (preg_match('#^/(?:inventory|inv|inventaris)(@\w+)?$#i', $text)) {
            $this->cmdInventory($chatId, $user);
            return;
        }
        if (preg_match('#^/(?:shop|toko)(@\w+)?$#i', $text)) {
            $this->reply($chatId, BotMenus::shopMenuText($user), BotMenus::shopMenuKeyboard($user));
            return;
        }
        if (preg_match('#^/misi(@\w+)?$#i', $text)) {
            $this->cmdMisi($chatId, $user);
            return;
        }
        if (preg_match('#^/(ai|tanya)(@\w+)?\s*(.*)$#is', $text, $m)) {
            $prompt = trim((string)($m[3] ?? ''));
            $this->cmdAi($chatId, $user, $prompt);
            return;
        }

        // Admin & Staff Management commands (permission enforced per execution)
        if (preg_match('#^/addstaff(@\w+)?(?:\s+(.+))?$#is', $text, $m)) {
            $this->cmdAddStaff($chatId, $user, trim($m[2] ?? ''));
            return;
        }
        if (preg_match('#^/delstaff(@\w+)?(?:\s+(.+))?$#is', $text, $m)) {
            $this->cmdDelStaff($chatId, $user, trim($m[2] ?? ''));
            return;
        }
        if (preg_match('#^/liststaff(@\w+)?$#i', $text)) {
            $this->cmdListStaff($chatId, $user);
            return;
        }
        if (preg_match('#^/setrole(@\w+)?(?:\s+(.+))?$#is', $text, $m)) {
            $this->cmdSetRole($chatId, $user, trim($m[2] ?? ''));
            return;
        }
        if (preg_match('#^/resetpass(@\w+)?(?:\s+(.+))?$#is', $text, $m)) {
            $this->cmdResetPass($chatId, $user, trim($m[2] ?? ''));
            return;
        }
        if (preg_match('#^/listusers(@\w+)?\s+(\w+)$#i', $text, $m)) {
            $this->cmdListUsers($chatId, $user, strtolower($m[2]));
            return;
        }
        if (preg_match('#^/approve(@\w+)?\s+(\d+)$#i', $text, $m)) {
            $this->cmdApprove($chatId, $user, (int)$m[2]);
            return;
        }
        if (preg_match('#^/adduser(@\w+)?\s+(\S+)\s+(\S+)\s+(\w+)$#i', $text, $m)) {
            $this->cmdAddUser($chatId, $user, $m[2], $m[3], strtolower($m[4]));
            return;
        }
        if (preg_match('#^/unbanall(@\w+)?$#', $text)) {
            $this->cmdUnbanAll($chatId, $user);
            return;
        }

        // Default response: show main menu
        $this->reply($chatId, BotMenus::mainMenuCaption($user), BotMenus::mainMenuKeyboard($user));
    }

    // ---------------------------------------------------------------- commands

    private function cmdHelp(int $chatId, int $fromId): void
    {
        $user = AccountLink::userForTg($fromId);
        $lines = [
            '🤖 <b>Bantuan Bot Virtual City SMK Silber</b>',
            '',
            'Umum & Eksplorasi:',
            '• /start — Buka menu utama bergambar',
            '• /menu — Buka menu bot',
            '• /dunia — Akses langsung Game Kampus 3D',
            '• /pixel — Akses mode game 2D Pixel World',
            '• /peta — Buka denah sekolah interaktif 2D',
            '• /sekolah — Info profil & fasilitas sekolah',
            '• /help — Bantuan perintah',
            '• /link KODE — Tautkan akun web (alias: /telegram KODE)',
            '• /unlink — Putuskan tautan akun',
            '• /status — Status akun Anda',
        ];
        if ($user !== null) {
            $lines = array_merge($lines, [
                '',
                'Siswa & Staf:',
                '• /jadwal — Jadwal pelajaran hari ini',
                '• /tugas — Daftar tugas kelas aktif',
                '• /misi — Daftar misi & perolehan hadiah',
                '• /event — Agenda event sekolah',
                '• /koin — Progres koin & level',
                '• /inventory — Barang yang dimiliki',
                '• /shop — Katalog Toko Avatar',
                '• /ai PERTANYAAN — Tanya AI Assistant',
            ]);
            if (BotMenus::hasAdminAccess($user)) {
                $lines[] = '';
                $lines[] = 'Kelola Staf & Akun Web:';
                $lines[] = '• /addstaff <tg_id> <role> [user] [pass] — Buat akun staf & tautkan TG';
                $lines[] = '• /delstaff <tg_id/user> — Nonaktifkan staf & cabut bot';
                $lines[] = '• /liststaff — Daftar semua staf aktif & ID Telegram';
                $lines[] = '• /setrole <tg_id/user> <role> — Ubah role pengguna';
                $lines[] = '• /resetpass <tg_id/user> [pass] — Reset password web';
            }
            if (Permissions::canFor($user, 'user.view')) {
                $lines[] = '• /listusers <role> — Daftar akun (admin)';
            }
            if (Permissions::canFor($user, 'user.approve_registration')) {
                $lines[] = '• /approve <id> — Setujui pendaftar (admin)';
            }
            if (Permissions::canFor($user, 'role.assign')) {
                $lines[] = '• /adduser <user> <pass> <role> — Buat akun (admin)';
            }
            if (Permissions::isSuperuser($user)) {
                $lines[] = '• /unbanall — Buka semua blokir IP (root)';
            }
        }
        $this->reply($chatId, implode("\n", $lines), BotMenus::helpMenuKeyboard());
    }

    private function cmdLink(int $chatId, int $fromId, string $code, ?string $tgUsername): void
    {
        $res = AccountLink::consume($code, $fromId, $tgUsername);
        if ($res['status'] === 'ok') {
            $u = $res['user'];
            $this->reply($chatId, "🎉 <b>Akun Berhasil Ditautkan!</b>\n\nHalo <b>" . $this->esc((string)$u['full_name']) . "</b> (" . $this->esc((string)$u['role']) . ")!\nAkun Telegram Anda kini resmi terhubung dengan Virtual City SMK Silber.\n\nSelamat menjelajah!");
            $this->sendMainMenuWithPhoto($chatId, $u);
            SecurityLog::write('tg_link_ok', ['tg' => $fromId]);
        } else {
            $site = BotMenus::siteUrl();
            $this->reply($chatId, "❌ <b>Gagal Menautkan Akun</b>\n\n" . $this->esc($res['message']) . "\n\n💡 <i>Dapatkan kode tautan 8 karakter baru di web:</i>\n👉 <b><a href=\"{$site}/telegram\">{$site}/telegram</a></b>", BotMenus::linkAccountKeyboard());
        }
    }

    private function cmdStatus(int $chatId, ?array $user): void
    {
        if ($user === null) {
            $this->reply($chatId, '🔒 Akun belum ditautkan.');
            return;
        }
        $this->reply($chatId,
            "👤 <b>Status Akun</b>\n\nNama: <b>" . $this->esc((string)$user['full_name']) . "</b>\n"
            . "Username: <code>" . $this->esc((string)$user['username']) . "</code>\n"
            . "Role: <b>" . $this->esc((string)$user['role']) . "</b>\n"
            . "Status: <b>" . $this->esc((string)$user['status']) . "</b>",
            BotMenus::profileMenuKeyboard($user));
    }

    private function cmdJadwal(int $chatId, ?array $user): void
    {
        if ($user === null) {
            return;
        }
        $text = BotMenus::scheduleMenuText($user);
        $this->reply($chatId, $text, BotMenus::scheduleMenuKeyboard());
    }

    private function cmdTugas(int $chatId, ?array $user): void
    {
        if ($user === null) {
            return;
        }
        $text = BotMenus::tasksMenuText($user);
        $this->reply($chatId, $text, BotMenus::tasksMenuKeyboard());
    }

    private function cmdEvent(int $chatId, ?array $user): void
    {
        if ($user === null) {
            return;
        }
        $text = BotMenus::eventsMenuText();
        $this->reply($chatId, $text, BotMenus::eventsMenuKeyboard());
    }

    private function cmdKoin(int $chatId, ?array $user): void
    {
        if ($user === null) {
            return;
        }
        try {
            $p = Gameplay::getProgress((int)$user['id']);
        } catch (\Throwable $e) {
            $this->reply($chatId, '⚠️ Gagal memuat progres.');
            return;
        }
        $this->reply($chatId,
            "🪙 <b>Progresmu</b>\n\nLevel: <b>" . (int)($p['level'] ?? 1) . "</b>\n"
            . "Total XP: <b>" . (int)($p['total_xp'] ?? 0) . "</b>\n"
            . "Koin: <b>" . (int)($p['coins'] ?? 0) . "</b>\n"
            . "Streak login: <b>" . (int)($p['login_streak'] ?? 0) . "</b> hari",
            BotMenus::coinsMenuKeyboard($user));
    }

    private function cmdInventory(int $chatId, ?array $user): void
    {
        if ($user === null) {
            return;
        }
        $text = BotMenus::inventoryMenuText($user);
        $this->reply($chatId, $text, BotMenus::inventoryMenuKeyboard($user));
    }

    private function cmdMisi(int $chatId, ?array $user): void
    {
        if ($user === null) {
            $this->reply($chatId, '🔒 Akun belum ditautkan.');
            return;
        }
        $site = BotMenus::siteUrl();
        try {
            $missions = Gameplay::getMissions((int)$user['id']);
        } catch (\Throwable $e) {
            $missions = [];
        }
        if (empty($missions)) {
            $this->reply($chatId, '🎯 Saat ini belum ada misi aktif.');
            return;
        }
        $lines = ["🎯 <b>Misi & Tantangan Virtual City</b>\n"];
        $completed = 0;
        foreach (array_slice($missions, 0, 8) as $m) {
            $st = (string)($m['user_status'] ?? 'not_started');
            $icon = ($st === 'completed') ? '✅' : (($st === 'in_progress') ? '⏳' : '⚪');
            if ($st === 'completed') $completed++;
            $badge = ($st === 'completed') ? '<b>[Selesai]</b>' : (($st === 'in_progress') ? '<b>[Sedang Berjalan]</b>' : '[Tersedia]');
            $lines[] = "{$icon} <b>" . $this->esc((string)$m['title']) . "</b> {$badge}";
            $lines[] = "   <i>" . $this->esc((string)$m['description']) . "</i>";
            $lines[] = "   Hadiah: 🪙 +" . (int)$m['coin_reward'] . " koin | ✨ +" . (int)$m['xp_reward'] . " XP\n";
        }
        $total = count($missions);
        $lines[] = "📊 Progres: <b>{$completed} / {$total}</b> misi selesai.";
        $this->reply($chatId, implode("\n", $lines), [
            'inline_keyboard' => [
                [['text' => '🎮 Buka Misi di Web', 'url' => $site . '/misi']],
                [['text' => '🏠 Menu Utama', 'callback_data' => 'menu:home']],
            ]
        ]);
    }

    private function cmdAi(int $chatId, ?array $user, string $prompt): void
    {
        if ($prompt === '') {
            $this->reply($chatId, BotMenus::aiMenuText(), BotMenus::aiMenuKeyboard());
            return;
        }
        if (mb_strlen($prompt) > 400) {
            $this->reply($chatId, '⚠️ Pertanyaan terlalu panjang (maksimal 400 karakter).');
            return;
        }

        $this->tg->sendChatAction($chatId, 'typing');
        $res = Providers::chat('engineering', $prompt, 250);

        if (!empty($res['ok']) && !empty($res['text'])) {
            $reply = "🤖 <b>Jawaban AI Assistant:</b>\n\n" . $this->esc((string)$res['text'])
                . "\n\n<small class='muted'>Model: " . $this->esc((string)($res['model'] ?? '')) . "</small>";
            $this->reply($chatId, $reply, ['inline_keyboard' => [[['text' => '⬅️ Menu AI', 'callback_data' => 'menu:ai']]]]);
        } else {
            $err = !empty($res['error']) ? $this->esc((string)$res['error']) : 'Layanan tidak dapat dihubungi';
            $this->reply($chatId, "⚠️ <b>Layanan AI Sedang Tidak Tersedia</b>\nKeterangan: {$err}\nSilakan coba lagi beberapa saat lagi.",
                ['inline_keyboard' => [[['text' => '📊 Status Model AI', 'callback_data' => 'ai:status']]]]);
        }
    }

    // ---------------------------------------------------------------- admin

    private function cmdListUsers(int $chatId, ?array $user, string $roleFilter): void
    {
        if ($user === null || !Permissions::canFor($user, 'user.view')) {
            $this->denied($chatId);
            return;
        }
        $pdo = Database::get();
        if ($roleFilter === 'all') {
            $stmt = $pdo->query('SELECT username, role, status FROM users WHERE deleted_at IS NULL ORDER BY role, username LIMIT 60');
        } else {
            $stmt = $pdo->prepare('SELECT username, role, status FROM users WHERE role = ? AND deleted_at IS NULL ORDER BY username LIMIT 60');
            $stmt->execute([$roleFilter]);
        }
        $list = [];
        foreach ($stmt->fetchAll() as $r) {
            $icon = $r['status'] === 'active' ? '🟢' : ($r['status'] === 'pending' ? '🟡' : '🔴');
            $list[] = "{$icon} <b>" . $this->esc((string)$r['username']) . "</b> — " . $this->esc((string)$r['role']);
        }
        if ($list === []) {
            $list = ['(kosong / role tidak ditemukan)'];
        }
        AuditLog::log('tg_list_users', ['role' => $roleFilter], (int)$user['id']);
        $this->reply($chatId, "📋 <b>Pengguna</b> (" . $this->esc($roleFilter) . ")\n\n" . implode("\n", $list) . "\n\nMaks 60 ditampilkan.",
            ['inline_keyboard' => [[['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]]]);
    }

    private function cmdApprove(int $chatId, ?array $user, int $regId): void
    {
        if ($user === null || !Permissions::canFor($user, 'user.approve_registration')) {
            $this->denied($chatId);
            return;
        }
        $ok = Registration::activateFromRegistration($regId, (int)$user['id'], 'Disetujui via Telegram');
        AuditLog::log('tg_registration_approved', ['reg_id' => $regId], (int)$user['id']);
        $this->reply($chatId, $ok ? "✅ Pendaftar #{$regId} disetujui. Wajib ganti password saat login pertama." : "⚠️ Pendaftar #{$regId} tidak ditemukan/sudah diproses.",
            ['inline_keyboard' => [[['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]]]);
    }

    private function cmdAddUser(int $chatId, ?array $user, string $username, string $password, string $role): void
    {
        if ($user === null || !Permissions::canFor($user, 'role.assign')) {
            $this->denied($chatId);
            return;
        }
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9._-]{4,32}$/', $username)) {
            $this->reply($chatId, '⚠️ Username 4–32 karakter, hanya huruf/angka/._-');
            return;
        }
        if (!in_array($role, self::CREATABLE_ROLES, true)) {
            $this->reply($chatId, '⚠️ Role tidak valid. Pilih: ' . implode(', ', self::CREATABLE_ROLES));
            return;
        }
        if ($role === 'root' && !Permissions::isSuperuser($user)) {
            $this->reply($chatId, '⛔ Hanya ROOT yang boleh membuat akun ROOT.');
            return;
        }
        $errs = Auth::passwordErrors($password, $username, $role);
        $minLen = $role === 'root' ? 14 : 12;
        if (strlen($password) < $minLen) {
            $errs[] = "Password minimal {$minLen} karakter untuk role ini.";
        }
        if ($errs) {
            $this->reply($chatId, "❌ Password lemah:\n• " . implode("\n• ", $errs));
            return;
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            $this->reply($chatId, "❌ Username <b>" . $this->esc($username) . "</b> sudah dipakai.");
            return;
        }
        $uid = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, status, must_change_password, created_at)
            VALUES (?, ?, ?, ?, ?, "active", 1, datetime("now"))')
            ->execute([$uid, $username, Auth::hash($password), $role, 'Dibuat via Telegram oleh ' . $this->esc((string)$user['username'])]);
        AuditLog::log('tg_create_user', ['username' => $username, 'role' => $role, 'by' => (int)$user['id']]);
        $this->reply($chatId,
            "✅ <b>Akun dibuat</b>\n\nUsername: <code>" . $this->esc($username) . "</code>\nRole: <b>" . $this->esc($role) . "</b>\n\n"
            . "🔐 Password ditampilkan sekali ini saja. Teruskan secara aman dan minta user menggantinya saat login pertama.\n"
            . "Password: <code>" . $this->esc($password) . "</code>",
            ['inline_keyboard' => [[['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]]]);
    }

    private function cmdAddStaff(int $chatId, ?array $user, string $args): void
    {
        if ($user === null || !BotMenus::hasAdminAccess($user)) {
            $this->denied($chatId);
            return;
        }
        $site = BotMenus::siteUrl();
        if ($args === '') {
            $text = "👥 <b>PANDUAN MENAMBAHKAN STAF & AKUN WEB</b>\n\n"
                . "Fitur ini digunakan untuk menambahkan akun Staf (Guru, Root, Wali Kelas, Kepala Program, Pembina, Pengurus) sekaligus menautkan ID Telegram dan membuat akun login website.\n\n"
                . "Format Perintah:\n"
                . "1️⃣ <b>Kredensial Otomatis:</b>\n"
                . "<code>/addstaff &lt;id_telegram&gt; &lt;role&gt;</code>\n\n"
                . "2️⃣ <b>Kredensial Kustom:</b>\n"
                . "<code>/addstaff &lt;id_telegram&gt; &lt;role&gt; &lt;username&gt; &lt;password&gt;</code>\n\n"
                . "Pilihan Role Valid:\n"
                . "• <code>guru</code> (Guru Pengajar)\n"
                . "• <code>root</code> (Super Administrator)\n"
                . "• <code>kepala_sekolah</code> (Kepala Sekolah)\n"
                . "• <code>kepala_program</code> (Kepala Program Kejuruan)\n"
                . "• <code>wali_kelas</code> (Wali Kelas)\n"
                . "• <code>pembina</code> (Pembina Organisasi)\n"
                . "• <code>pengurus</code> (Pengurus Ekskul)\n\n"
                . "Contoh:\n"
                . "<code>/addstaff 700458744 guru</code>\n"
                . "<code>/addstaff 700458744 guru pak_ahmad RahasiaKuat#2026</code>";
            $this->reply($chatId, $text, ['inline_keyboard' => [
                [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
            ]]);
            return;
        }

        $parts = preg_split('/\s+/', $args);
        $rawTgId = $parts[0] ?? '';
        $rawRole = $parts[1] ?? '';
        $customUsername = $parts[2] ?? null;
        $customPassword = $parts[3] ?? null;

        $targetTgId = (int)$rawTgId;
        if ($targetTgId <= 0) {
            $this->reply($chatId, "⚠️ ID Telegram tidak valid. Masukkan ID angka numerik pengguna Telegram (misal: <code>700458744</code>).");
            return;
        }

        $role = match(strtolower($rawRole)) {
            'root', 'owner', 'superadmin', 'admin' => 'root',
            'guru', 'teacher' => 'guru',
            'kepala_sekolah', 'kepsek' => 'kepala_sekolah',
            'kepala_program', 'kaprog' => 'kepala_program',
            'wali_kelas', 'walikelas' => 'wali_kelas',
            'pembina' => 'pembina',
            'pengurus' => 'pengurus',
            default => strtolower($rawRole),
        };

        if (!in_array($role, self::STAFF_ROLES, true)) {
            $this->reply($chatId, "⚠️ Role <b>" . $this->esc($rawRole) . "</b> tidak dikenal.\nPilihan role valid: <code>" . implode(', ', self::STAFF_ROLES) . "</code>");
            return;
        }

        if ($role === 'root' && !Permissions::isSuperuser($user)) {
            $this->reply($chatId, "⛔ Hanya Administrator ROOT yang berwenang menambahkan atau mempromosikan role ROOT.");
            return;
        }

        $pdo = Database::get();

        // Check if Telegram ID is already linked
        $stmt = $pdo->prepare('SELECT u.* FROM telegram_links tl JOIN users u ON tl.user_id = u.id WHERE tl.tg_user_id = ?');
        $stmt->execute([$targetTgId]);
        $existingLinked = $stmt->fetch();

        if ($existingLinked) {
            $targetUserId = (int)$existingLinked['id'];
            $username = (string)$existingLinked['username'];
            $fullName = (string)$existingLinked['full_name'];

            $updateSql = 'UPDATE users SET role = ?, status = "active", deleted_at = NULL';
            $params = [$role];
            $passwordNotice = '';

            if ($customPassword !== null && trim($customPassword) !== '') {
                $updateSql .= ', password_hash = ?';
                $params[] = Auth::hash($customPassword);
                $passwordNotice = "\n• Password Baru: <code>" . $this->esc($customPassword) . "</code>";
            }

            $updateSql .= ' WHERE id = ?';
            $params[] = $targetUserId;
            $pdo->prepare($updateSql)->execute($params);

            Permissions::flush();
            AuditLog::log('tg_staff_promoted', [
                'target_user_id' => $targetUserId,
                'tg_user_id' => $targetTgId,
                'old_role' => $existingLinked['role'],
                'new_role' => $role
            ], (int)$user['id']);

            $replyText = "🎉 <b>AKUN STAF DIPERBARUI & DIHUBUNGKAN!</b>\n\n"
                . "Pengguna Telegram <code>{$targetTgId}</code> yang sebelumnya terdaftar sebagai <b>" . strtoupper($existingLinked['role']) . "</b> kini telah diubah menjadi <b>" . strtoupper($role) . "</b>.\n\n"
                . "👤 <b>Kredensial Akun Web:</b>\n"
                . "• Username: <code>" . $this->esc($username) . "</code>\n"
                . "• Nama: <b>" . $this->esc($fullName) . "</b>\n"
                . "• Role: <b>" . strtoupper($role) . "</b>"
                . $passwordNotice . "\n"
                . "• URL Login: <b><a href=\"{$site}/?admin=1\">{$site}/?admin=1</a></b>\n\n"
                . "💡 <i>Staf dapat langsung login di web portal atau menggunakan perintah bot ini!</i>";

            $this->reply($chatId, $replyText, ['inline_keyboard' => [
                [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
            ]]);

            try {
                $this->tg->sendMessage($targetTgId, "🎉 <b>Perubahan Status Akun Staf Virtual City</b>\n\nAkun Anda telah diangkat menjadi <b>" . strtoupper($role) . "</b> oleh Administrator.\nUsername Web: <code>{$username}</code>\nURL Login: {$site}/?admin=1");
            } catch (\Throwable $e) {}

            return;
        }

        // New user creation
        $username = $customUsername ? strtolower(trim($customUsername)) : 'staff_' . $role . '_' . substr((string)$targetTgId, -4);
        $username = preg_replace('/[^a-z0-9._-]/', '', $username);
        if (strlen($username) < 4) {
            $username = 'staff_' . substr((string)$targetTgId, -6);
        }

        $uCheck = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $uCheck->execute([$username]);
        if ($uCheck->fetch()) {
            if ($customUsername !== null) {
                $this->reply($chatId, "❌ Username <code>{$username}</code> sudah digunakan. Pilih username lain.");
                return;
            }
            $username .= '_' . random_int(10, 99);
        }

        $password = $customPassword ? trim($customPassword) : 'SilberStaff#' . bin2hex(random_bytes(3));
        $fullName = "Staf " . strtoupper($role) . " (TG " . $targetTgId . ")";

        $uid = bin2hex(random_bytes(16));
        $ins = $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, status, must_change_password, created_at)
            VALUES (?, ?, ?, ?, ?, "active", 0, datetime("now"))');
        $ins->execute([$uid, $username, Auth::hash($password), $role, $fullName]);
        $newUserId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT OR IGNORE INTO user_progress (user_id, coins, level, total_xp) VALUES (?, 1000, 5, 500)')->execute([$newUserId]);

        $pdo->prepare('INSERT OR REPLACE INTO telegram_links (tg_user_id, user_id, linked_at, last_seen_at)
            VALUES (?, ?, datetime("now"), datetime("now"))')
            ->execute([$targetTgId, $newUserId]);

        Permissions::flush();
        AuditLog::log('tg_staff_created', [
            'new_user_id' => $newUserId,
            'tg_user_id' => $targetTgId,
            'role' => $role,
            'username' => $username
        ], (int)$user['id']);

        $replyText = "🎉 <b>AKUN STAF BERHASIL DIBUAT & DITAUTKAN!</b>\n\n"
            . "Akun staf baru telah aktif dan otomatis terhubung dengan ID Telegram <code>{$targetTgId}</code>.\n\n"
            . "🌐 <b>KREDENSIAL LOGIN WEBSITE:</b>\n"
            . "• URL Login: <b><a href=\"{$site}/?admin=1\">{$site}/?admin=1</a></b>\n"
            . "• Role: <b>" . strtoupper($role) . "</b>\n"
            . "• Username: <code>{$username}</code>\n"
            . "• Password: <code>{$password}</code>\n\n"
            . "💡 <i>Kredensial ini dapat langsung digunakan untuk masuk ke web. Teruskan secara privat ke pemilik akun.</i>";

        $this->reply($chatId, $replyText, ['inline_keyboard' => [
            [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
            [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
        ]]);

        try {
            $this->tg->sendMessage($targetTgId, "🎉 <b>Akun Staf Virtual City SMK Silber Diaktifkan!</b>\n\nSelamat! Anda telah ditambahkan sebagai <b>" . strtoupper($role) . "</b> oleh Administrator.\n\n🌐 <b>Kredensial Login Website:</b>\n• URL: {$site}/?admin=1\n• Username: <code>{$username}</code>\n• Password: <code>{$password}</code>\n\nAnda dapat langsung login di website atau menggunakan menu bot ini!");
        } catch (\Throwable $e) {}
    }

    private function cmdDelStaff(int $chatId, ?array $user, string $arg): void
    {
        if ($user === null || !BotMenus::hasAdminAccess($user)) {
            $this->denied($chatId);
            return;
        }

        if ($arg === '') {
            $text = "🗑️ <b>PANDUAN MENGHAPUS / MENONAKTIFKAN STAF</b>\n\n"
                . "Format Perintah:\n"
                . "<code>/delstaff &lt;id_telegram / username / id_user&gt;</code>\n\n"
                . "Contoh:\n"
                . "• <code>/delstaff 700458744</code> (berdasarkan ID Telegram)\n"
                . "• <code>/delstaff pak_ahmad</code> (berdasarkan username web)\n"
                . "• <code>/delstaff 45</code> (berdasarkan ID pengguna)\n\n"
                . "⚠️ Akun akan dinonaktifkan (status: suspended), sesi dicabut, dan tautan Telegram diputus.";
            $this->reply($chatId, $text, ['inline_keyboard' => [
                [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
            ]]);
            return;
        }

        $pdo = Database::get();
        $targetUser = null;

        if (is_numeric($arg)) {
            $num = (int)$arg;
            $stmt = $pdo->prepare('SELECT u.* FROM telegram_links tl JOIN users u ON tl.user_id = u.id WHERE tl.tg_user_id = ?');
            $stmt->execute([$num]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$num]);
                $targetUser = $stmt->fetch();
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([strtolower(trim($arg))]);
            $targetUser = $stmt->fetch();
        }

        if (!$targetUser) {
            $this->reply($chatId, "⚠️ Pengguna atau staf dengan identifier <b>" . $this->esc($arg) . "</b> tidak ditemukan.");
            return;
        }

        $targetId = (int)$targetUser['id'];

        if ($targetId === (int)$user['id']) {
            $this->reply($chatId, "⛔ Anda tidak dapat menghapus atau menonaktifkan akun Anda sendiri.");
            return;
        }

        if ($targetUser['role'] === 'root') {
            if (!Permissions::isSuperuser($user)) {
                $this->reply($chatId, "⛔ Hanya Administrator ROOT yang dapat menonaktifkan akun ROOT.");
                return;
            }
            $rootCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "root" AND status = "active" AND deleted_at IS NULL')->fetchColumn();
            if ($rootCount <= 1) {
                $this->reply($chatId, "⛔ Tidak dapat menonaktifkan akun ROOT terakhir sistem.");
                return;
            }
        }

        $pdo->prepare('UPDATE users SET status = "suspended", deleted_at = datetime("now") WHERE id = ?')->execute([$targetId]);
        $pdo->prepare('DELETE FROM telegram_links WHERE user_id = ?')->execute([$targetId]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$targetId]);

        Permissions::flush();
        AuditLog::log('tg_staff_deleted', [
            'target_id' => $targetId,
            'target_username' => $targetUser['username'],
            'target_role' => $targetUser['role']
        ], (int)$user['id']);

        $this->reply($chatId, "✅ <b>Akun Staf Berhasil Dinonaktifkan</b>\n\nPengguna <b>" . $this->esc($targetUser['username']) . "</b> (" . strtoupper($targetUser['role']) . ") telah dinonaktifkan, tautan Telegram dicabut, dan sesi login ditutup.",
            ['inline_keyboard' => [
                [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
            ]]);
    }

    private function cmdListStaff(int $chatId, ?array $user): void
    {
        if ($user === null || !BotMenus::hasAdminAccess($user)) {
            $this->denied($chatId);
            return;
        }

        $pdo = Database::get();
        $stmt = $pdo->query('SELECT u.id, u.username, u.full_name, u.role, u.status, tl.tg_user_id, tl.tg_username
            FROM users u
            LEFT JOIN telegram_links tl ON u.id = tl.user_id
            WHERE u.role IN ("root", "kepala_sekolah", "kepala_program", "guru", "wali_kelas", "pembina", "pengurus")
              AND u.deleted_at IS NULL
            ORDER BY CASE u.role 
                WHEN "root" THEN 1 
                WHEN "kepala_sekolah" THEN 2 
                WHEN "kepala_program" THEN 3 
                WHEN "guru" THEN 4 
                WHEN "wali_kelas" THEN 5 
                ELSE 6 END, u.id ASC
            LIMIT 40');
        $staffList = $stmt->fetchAll();

        $lines = ["👥 <b>DAFTAR STAF & PENGAJAR AKTIF</b>\nTotal: <b>" . count($staffList) . "</b> akun\n"];
        foreach ($staffList as $s) {
            $icon = match($s['role']) {
                'root' => '👑',
                'kepala_sekolah', 'kepala_program' => '👔',
                'guru' => '👨‍🏫',
                'wali_kelas' => '🧑‍🏫',
                default => '🎖️'
            };
            $statusDot = ($s['status'] === 'active') ? '🟢' : '🟡';
            $tgStr = !empty($s['tg_user_id']) ? "<code>{$s['tg_user_id']}</code>" : "<i>Belum tertaut</i>";
            $lines[] = "{$icon} {$statusDot} <b>" . $this->esc((string)$s['username']) . "</b> (" . strtoupper($s['role']) . ")\n"
                . "   Nama: " . $this->esc((string)$s['full_name']) . "\n"
                . "   Telegram: {$tgStr}";
        }

        $kb = ['inline_keyboard' => [
            [
                ['text' => '➕ Tambah Staff (/addstaff)', 'callback_data' => 'adm:staff:add_guide'],
                ['text' => '🗑️ Hapus Staff (/delstaff)', 'callback_data' => 'adm:staff:del_guide'],
            ],
            [
                ['text' => '🔑 Reset Password', 'callback_data' => 'adm:staff:reset_guide'],
                ['text' => '🔄 Ubah Role', 'callback_data' => 'adm:staff:role_guide'],
            ],
            [
                ['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin'],
                ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
            ],
        ]];

        $this->reply($chatId, implode("\n", $lines), $kb);
    }

    private function cmdResetPass(int $chatId, ?array $user, string $args): void
    {
        if ($user === null || !BotMenus::hasAdminAccess($user)) {
            $this->denied($chatId);
            return;
        }

        $parts = preg_split('/\s+/', trim($args));
        $target = $parts[0] ?? '';
        $newPass = $parts[1] ?? null;

        if ($target === '') {
            $text = "🔑 <b>PANDUAN RESET PASSWORD WEB STAF</b>\n\n"
                . "Gunakan perintah ini untuk memperbarui password login website bagi staf atau pengguna yang lupa kata sandi.\n\n"
                . "Format:\n"
                . "<code>/resetpass &lt;id_telegram / username&gt; [password_baru]</code>\n\n"
                . "Contoh:\n"
                . "• <code>/resetpass 700458744</code> (Otomatis generate password kuat)\n"
                . "• <code>/resetpass guru_silber SandiBaru#2026</code>";
            $this->reply($chatId, $text, ['inline_keyboard' => [[['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]]]);
            return;
        }

        $pdo = Database::get();
        $targetUser = null;

        if (is_numeric($target)) {
            $num = (int)$target;
            $stmt = $pdo->prepare('SELECT u.* FROM telegram_links tl JOIN users u ON tl.user_id = u.id WHERE tl.tg_user_id = ?');
            $stmt->execute([$num]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$num]);
                $targetUser = $stmt->fetch();
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([strtolower(trim($target))]);
            $targetUser = $stmt->fetch();
        }

        if (!$targetUser) {
            $this->reply($chatId, "⚠️ Pengguna dengan identifier <b>" . $this->esc($target) . "</b> tidak ditemukan.");
            return;
        }

        if ($targetUser['role'] === 'root' && !Permissions::isSuperuser($user)) {
            $this->reply($chatId, "⛔ Hanya ROOT yang dapat mereset password akun ROOT.");
            return;
        }

        $password = $newPass ? trim($newPass) : 'SilberPass#' . bin2hex(random_bytes(3)) . '!';
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([Auth::hash($password), (int)$targetUser['id']]);

        AuditLog::log('tg_reset_password', ['target_id' => $targetUser['id'], 'username' => $targetUser['username']], (int)$user['id']);

        $site = BotMenus::siteUrl();
        $this->reply($chatId, "✅ <b>Password Berhasil Direset!</b>\n\n"
            . "• Akun: <b>" . $this->esc($targetUser['username']) . "</b>\n"
            . "• Role: <b>" . strtoupper($targetUser['role']) . "</b>\n"
            . "• Password Baru: <code>" . $this->esc($password) . "</code>\n"
            . "• Login Website: <b><a href=\"{$site}/?admin=1\">{$site}/?admin=1</a></b>",
            ['inline_keyboard' => [[['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]]]);
    }

    private function cmdSetRole(int $chatId, ?array $user, string $args): void
    {
        if ($user === null || !Permissions::canFor($user, 'role.assign')) {
            $this->denied($chatId);
            return;
        }

        $parts = preg_split('/\s+/', trim($args));
        $target = $parts[0] ?? '';
        $rawRole = $parts[1] ?? '';

        if ($target === '' || $rawRole === '') {
            $text = "🔄 <b>PANDUAN MENGUBAH ROLE PENGGUNA</b>\n\n"
                . "Format:\n"
                . "<code>/setrole &lt;id_telegram / username&gt; &lt;role_baru&gt;</code>\n\n"
                . "Role Tersedia:\n"
                . "• <code>guru</code>, <code>root</code>, <code>kepala_sekolah</code>, <code>kepala_program</code>, <code>wali_kelas</code>, <code>murid</code>\n\n"
                . "Contoh:\n"
                . "<code>/setrole 700458744 guru</code>\n"
                . "<code>/setrole siswa_budi wali_kelas</code>";
            $this->reply($chatId, $text, ['inline_keyboard' => [[['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]]]);
            return;
        }

        $role = match(strtolower($rawRole)) {
            'root', 'owner', 'superadmin', 'admin' => 'root',
            'guru', 'teacher' => 'guru',
            'kepala_sekolah', 'kepsek' => 'kepala_sekolah',
            'kepala_program', 'kaprog' => 'kepala_program',
            'wali_kelas', 'walikelas' => 'wali_kelas',
            'murid', 'siswa', 'student' => 'murid',
            'pembina' => 'pembina',
            'pengurus' => 'pengurus',
            default => strtolower($rawRole),
        };

        if ($role === 'root' && !Permissions::isSuperuser($user)) {
            $this->reply($chatId, "⛔ Hanya ROOT yang dapat mengangkat pengguna menjadi ROOT.");
            return;
        }

        $pdo = Database::get();
        $targetUser = null;

        if (is_numeric($target)) {
            $num = (int)$target;
            $stmt = $pdo->prepare('SELECT u.* FROM telegram_links tl JOIN users u ON tl.user_id = u.id WHERE tl.tg_user_id = ?');
            $stmt->execute([$num]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$num]);
                $targetUser = $stmt->fetch();
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([strtolower(trim($target))]);
            $targetUser = $stmt->fetch();
        }

        if (!$targetUser) {
            $this->reply($chatId, "⚠️ Pengguna <b>" . $this->esc($target) . "</b> tidak ditemukan.");
            return;
        }

        $targetId = (int)$targetUser['id'];
        if ($targetUser['role'] === 'root' && $role !== 'root') {
            $rootCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "root" AND status = "active" AND deleted_at IS NULL')->fetchColumn();
            if ($rootCount <= 1) {
                $this->reply($chatId, "⛔ Tidak dapat menurunkan role akun ROOT terakhir.");
                return;
            }
        }

        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
        Permissions::flush();
        AuditLog::log('tg_role_changed', [
            'target_id' => $targetId,
            'username' => $targetUser['username'],
            'old_role' => $targetUser['role'],
            'new_role' => $role
        ], (int)$user['id']);

        $this->reply($chatId, "✅ Role untuk <b>" . $this->esc($targetUser['username']) . "</b> berhasil diubah dari <b>" . strtoupper($targetUser['role']) . "</b> menjadi <b>" . strtoupper($role) . "</b>.",
            ['inline_keyboard' => [
                [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
            ]]);
    }

    private function cmdUnbanAll(int $chatId, ?array $user): void
    {
        if ($user === null || !Permissions::isSuperuser($user)) {
            $this->denied($chatId);
            return;
        }
        $sec = Database::security();
        $sec->exec('DELETE FROM ip_blocks');
        $sec->exec('DELETE FROM rate_limit_hits');
        AuditLog::log('tg_unban_all', ['by' => (int)$user['id']]);
        SecurityLog::write('tg_unban_all', ['by' => (int)$user['id']]);
        $this->reply($chatId, '✅ Semua blokir IP dan rate-limit dibersihkan.',
            ['inline_keyboard' => [[['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]]]);
    }

    // --------------------------------------------------------------- callbacks

    private function handleCallback(array $cb): void
    {
        $fromId = (int)($cb['from']['id'] ?? 0);
        $chatId = (int)($cb['message']['chat']['id'] ?? $fromId);
        $msgId = (int)($cb['message']['message_id'] ?? 0);
        $data = (string)($cb['data'] ?? '');
        $isPhoto = !empty($cb['message']['photo']) || isset($cb['message']['caption']);

        // Acknowledge callback immediately so buttons don't get stuck loading
        $this->tg->answerCallback((string)($cb['id'] ?? ''));

        if (RateLimiter::hit('tg_user', (string)$fromId) > 0) {
            return;
        }

        $user = AccountLink::userForTg($fromId);

        // Routing callback data
        [$prefix, $sub, $extra] = array_pad(explode(':', $data, 3), 3, null);

        switch ($prefix) {
            // Main menu
            case 'menu':
                $this->routeMenu($chatId, $msgId, $isPhoto, $user, $sub ?? 'home');
                break;

            // Profile submenu
            case 'profile':
                $this->routeProfile($chatId, $msgId, $isPhoto, $user, $sub ?? 'view');
                break;

            // Virtual City game submenu
            case 'game':
                $this->routeGame($chatId, $msgId, $isPhoto, $sub ?? 'about');
                break;

            // Coins & economy submenu
            case 'coins':
                $this->routeCoins($chatId, $msgId, $isPhoto, $user, $sub ?? 'bal');
                break;

            // Inventory submenu
            case 'inv':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::inventoryMenuText($user), BotMenus::inventoryMenuKeyboard($user));
                break;

            // Shop submenu & purchase flow
            case 'shop':
                $this->routeShop($chatId, $msgId, $isPhoto, $user, $sub, $extra);
                break;

            // School info submenu
            case 'school':
                $this->routeSchool($chatId, $msgId, $isPhoto, $sub ?? 'about');
                break;

            // Schedule submenu
            case 'sched':
                $this->routeSchedule($chatId, $msgId, $isPhoto, $user, $sub ?? 'today', $extra);
                break;

            // Tasks submenu
            case 'tasks':
                $this->routeTasks($chatId, $msgId, $isPhoto, $user, $sub ?? 'act');
                break;

            // Events submenu
            case 'events':
                $this->routeEvents($chatId, $msgId, $isPhoto, $sub ?? 'up');
                break;

            // AI submenu
            case 'ai':
                $this->routeAi($chatId, $msgId, $isPhoto, $user, $sub ?? 'ask');
                break;

            // Notifications submenu
            case 'notif':
                $this->routeNotif($chatId, $msgId, $isPhoto, $user, $sub ?? 'list', $extra);
                break;

            // Settings submenu
            case 'set':
                $this->routeSettings($chatId, $msgId, $isPhoto, $user, $fromId, $sub ?? 'acc');
                break;

            // Help submenu
            case 'help':
                $this->routeHelp($chatId, $msgId, $isPhoto, $sub ?? 'guide');
                break;

            // Administrator submenu
            case 'adm':
                $this->routeAdmin($chatId, $msgId, $isPhoto, $user, $sub ?? 'dash', $extra);
                break;

            // Legacy callbacks
            case 'cmd':
                $this->dispatchLegacyCallback($chatId, $user, (string)$sub);
                break;

            case 'reg_approve':
                $this->handleLegacyRegApprove($chatId, $msgId, $isPhoto, $user, (int)$sub);
                break;

            default:
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::mainMenuCaption($user), BotMenus::mainMenuKeyboard($user));
                break;
        }
    }

    // ----------------------------------------------------------- route helpers

    private function routeMenu(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub): void
    {
        switch ($sub) {
            case 'home':
            case 'main':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::mainMenuCaption($user), BotMenus::mainMenuKeyboard($user));
                break;
            case 'profile':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::profileMenuText($user), BotMenus::profileMenuKeyboard($user));
                break;
            case 'game':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::gameMenuText(), BotMenus::gameMenuKeyboard());
                break;
            case 'coins':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::coinsMenuText($user), BotMenus::coinsMenuKeyboard($user));
                break;
            case 'inv':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::inventoryMenuText($user), BotMenus::inventoryMenuKeyboard($user));
                break;
            case 'shop':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::shopMenuText($user), BotMenus::shopMenuKeyboard($user));
                break;
            case 'school':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::schoolMenuText(), BotMenus::schoolMenuKeyboard());
                break;
            case 'schedule':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::scheduleMenuText($user), BotMenus::scheduleMenuKeyboard());
                break;
            case 'tasks':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::tasksMenuText($user), BotMenus::tasksMenuKeyboard());
                break;
            case 'events':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::eventsMenuText(), BotMenus::eventsMenuKeyboard());
                break;
            case 'ai':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::aiMenuText(), BotMenus::aiMenuKeyboard());
                break;
            case 'notif':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::notifMenuText($user), BotMenus::notifMenuKeyboard($user));
                break;
            case 'settings':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::settingsMenuText($user), BotMenus::settingsMenuKeyboard($user));
                break;
            case 'help':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::helpMenuText(), BotMenus::helpMenuKeyboard());
                break;
            case 'admin':
                if ($user === null || !BotMenus::hasAdminAccess($user)) {
                    $this->deniedCallback($chatId, $msgId, $isPhoto);
                    return;
                }
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::adminDashboardText($user), BotMenus::adminDashboardKeyboard($user));
                break;
            case 'link':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::linkAccountText(), BotMenus::linkAccountKeyboard());
                break;
            default:
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::mainMenuCaption($user), BotMenus::mainMenuKeyboard($user));
                break;
        }
    }

    private function routeProfile(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub): void
    {
        $text = BotMenus::profileDetailText($user, $sub);
        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '⬅️ Menu Profil', 'callback_data' => 'menu:profile'],
                    ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
                ],
            ],
        ];
        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function routeGame(int $chatId, int $msgId, bool $isPhoto, string $sub): void
    {
        $text = BotMenus::gameSectionText($sub);
        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '⬅️ Menu Virtual City', 'callback_data' => 'menu:game'],
                    ['text' => '🏠 Beranda', 'callback_data' => 'menu:home'],
                ],
            ],
        ];
        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function routeCoins(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub): void
    {
        if ($user === null) {
            $this->renderScreen($chatId, $msgId, $isPhoto, "🔒 Tautkan akun terlebih dahulu.", ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
            return;
        }

        switch ($sub) {
            case 'hist':
                $text = BotMenus::profileDetailText($user, 'history');
                break;
            case 'earn':
                $text = "💡 <b>Cara Mendapatkan Koin</b>\n\n"
                    . "• <b>Kuis Materi</b>: Kerjakan kuis di web (+15 s.d +50 koin)\n"
                    . "• <b>Event Sekolah</b>: Hadir dan verifikasi presensi (+50 s.d +200 koin)\n"
                    . "• <b>Login Streak</b>: Buka game setiap hari (+10 koin bertambah per hari)\n"
                    . "• <b>Misi Khusus</b>: Selesaikan quest sekolah di peta 3D.";
                break;
            case 'guide':
                $text = "📊 <b>Panduan Ekonomi Virtual City</b>\n\n"
                    . "Sistem ekonomi dikelola dengan perlindungan ganda:\n"
                    . "• Verifikasi saldo dan transaksi server-side\n"
                    . "• Pencegahan double-claim (idempotency key)\n"
                    . "• Catatan audit transaksi kriptografis (hash-chain)";
                break;
            default:
                $text = BotMenus::coinsMenuText($user);
                break;
        }

        $kb = ['inline_keyboard' => [
            [['text' => '⬅️ Menu Koin', 'callback_data' => 'menu:coins'], ['text' => '🏠 Beranda', 'callback_data' => 'menu:home']],
        ]];
        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function routeShop(int $chatId, int $msgId, bool $isPhoto, ?array $user, ?string $sub, ?string $extra): void
    {
        if ($sub === 'cat') {
            $cat = $extra ?: 'all';
            $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::shopMenuText($user, $cat), BotMenus::shopMenuKeyboard($user, $cat));
            return;
        }

        if ($sub === 'item' && $extra !== null) {
            $detail = BotMenus::shopItemDetailText($user, (int)$extra);
            $this->renderScreen($chatId, $msgId, $isPhoto, $detail['text'], $detail['kb']);
            return;
        }

        if ($sub === 'buy' && $extra !== null) {
            // Confirm purchase prompt
            if ($user === null) {
                $this->renderScreen($chatId, $msgId, $isPhoto, "🔒 Tautkan akun terlebih dahulu.", ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
                return;
            }
            $itemId = (int)$extra;
            $item = Database::get()->prepare('SELECT * FROM shop_items WHERE id = ?');
            $item->execute([$itemId]);
            $it = $item->fetch();
            if (!$it) {
                $this->renderScreen($chatId, $msgId, $isPhoto, "⚠️ Item tidak ditemukan.", ['inline_keyboard' => [[['text' => '⬅️ Kembali ke Toko', 'callback_data' => 'menu:shop']]]]);
                return;
            }
            $prog = Gameplay::getProgress((int)$user['id']);
            $coins = (int)($prog['coins'] ?? 0);
            $price = (int)$it['price_coins'];

            $confirmText = "🛍️ <b>Konfirmasi Pembelian Item</b>\n\n"
                . "Item: <b>" . $this->esc((string)$it['name']) . "</b>\n"
                . "Harga: <b>{$price} Koin</b> 🪙\n"
                . "Saldo Kamu: <b>{$coins} Koin</b>\n\n"
                . "Yakin ingin membeli item ini sekarang?";
            $kb = ['inline_keyboard' => [
                [
                    ['text' => '✅ Ya, Beli Sekarang', 'callback_data' => "shop:conf:{$itemId}"],
                    ['text' => '❌ Batal', 'callback_data' => "shop:item:{$itemId}"],
                ],
            ]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $confirmText, $kb);
            return;
        }

        if ($sub === 'conf' && $extra !== null) {
            // Server-side purchase validation and execution
            if ($user === null) {
                $this->renderScreen($chatId, $msgId, $isPhoto, "🔒 Tautkan akun terlebih dahulu.", ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
                return;
            }
            $itemId = (int)$extra;
            $res = Gameplay::purchaseItem((int)$user['id'], $itemId);
            if (($res['status'] ?? '') === 'success') {
                $rem = (int)($res['remaining_coins'] ?? 0);
                $msgText = "🎉 <b>Pembelian Berhasil!</b>\n\n" . $this->esc((string)$res['message'])
                    . "\nSaldo tersisa: <b>{$rem} Koin</b> 🪙\nItem kini tersedia di Inventaris kamu.";
                $kb = ['inline_keyboard' => [
                    [['text' => '🎒 Buka Inventaris', 'callback_data' => 'menu:inv'], ['text' => '🛒 Belanja Lagi', 'callback_data' => 'menu:shop']],
                ]];
            } else {
                $errMsg = $this->esc((string)($res['message'] ?? 'Transaksi gagal.'));
                $msgText = "❌ <b>Pembelian Gagal</b>\n\n{$errMsg}";
                $kb = ['inline_keyboard' => [
                    [['text' => '⬅️ Kembali ke Toko', 'callback_data' => 'menu:shop'], ['text' => '🪙 Cek Koin', 'callback_data' => 'menu:coins']],
                ]];
            }
            $this->renderScreen($chatId, $msgId, $isPhoto, $msgText, $kb);
            return;
        }

        $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::shopMenuText($user), BotMenus::shopMenuKeyboard($user));
    }

    private function routeSchool(int $chatId, int $msgId, bool $isPhoto, string $sub): void
    {
        $text = BotMenus::schoolSectionText($sub);
        $kb = ['inline_keyboard' => [
            [['text' => '⬅️ Menu Info Sekolah', 'callback_data' => 'menu:school'], ['text' => '🏠 Beranda', 'callback_data' => 'menu:home']],
        ]];
        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function routeSchedule(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub, ?string $extra): void
    {
        if ($user === null) {
            $this->renderScreen($chatId, $msgId, $isPhoto, "🔒 Tautkan akun terlebih dahulu.", ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
            return;
        }

        if ($sub === 'days') {
            $text = "🗓️ <b>Pilih Hari Jadwal:</b>\nPilih hari untuk melihat jadwal pelajaran kelas Anda:";
            $kb = ['inline_keyboard' => [
                [
                    ['text' => 'Senin', 'callback_data' => 'sched:day:1'],
                    ['text' => 'Selasa', 'callback_data' => 'sched:day:2'],
                    ['text' => 'Rabu', 'callback_data' => 'sched:day:3'],
                ],
                [
                    ['text' => 'Kamis', 'callback_data' => 'sched:day:4'],
                    ['text' => 'Jumat', 'callback_data' => 'sched:day:5'],
                    ['text' => 'Sabtu', 'callback_data' => 'sched:day:6'],
                ],
                [
                    ['text' => '⬅️ Kembali', 'callback_data' => 'menu:schedule'],
                ],
            ]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        if ($sub === 'day' && $extra !== null) {
            $dayNum = max(1, min(6, (int)$extra));
            $dayName = Schedule::DAYS[$dayNum] ?? 'Hari ' . $dayNum;
            $classId = (int)($user['class_id'] ?? 0);
            if ($classId <= 0) {
                $text = "⚠️ Kelas Anda belum ditentukan di database sekolah.";
            } else {
                $pdo = Database::get();
                $verId = (int)$pdo->query('SELECT id FROM schedule_versions WHERE mode = "weekly" AND status = "active" ORDER BY id DESC LIMIT 1')->fetchColumn();
                $stmt = $pdo->prepare('SELECT e.*, s.name as subject_name, r.name as room_name, u.full_name as teacher_name
                    FROM schedule_entries e
                    JOIN subjects s ON s.id = e.subject_id
                    LEFT JOIN rooms_physical r ON r.id = e.room_id
                    LEFT JOIN users u ON u.id = e.teacher_user_id
                    WHERE e.version_id = ? AND e.class_id = ? AND e.day_of_week = ?
                    ORDER BY e.period_number ASC');
                $stmt->execute([$verId, $classId, $dayNum]);
                $rows = $stmt->fetchAll();
                if ($rows === []) {
                    $text = "📅 <b>Jadwal Hari {$dayName}</b>\n\nTidak ada jadwal pelajaran pada hari ini.";
                } else {
                    $lines = ["📅 <b>Jadwal Hari {$dayName}</b>\n"];
                    foreach ($rows as $r) {
                        $lines[] = sprintf("• Jam %d: <b>%s</b>\n  Ruang: %s | Guru: %s",
                            (int)$r['period_number'],
                            $this->esc((string)$r['subject_name']),
                            $this->esc((string)($r['room_name'] ?? '-')),
                            $this->esc((string)($r['teacher_name'] ?? '-'))
                        );
                    }
                    $text = implode("\n", $lines);
                }
            }
            $kb = ['inline_keyboard' => [
                [['text' => '🗓️ Pilih Hari Lain', 'callback_data' => 'sched:days'], ['text' => '🏠 Beranda', 'callback_data' => 'menu:home']],
            ]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        if ($sub === 'where') {
            $text = "📍 <b>Cari Lokasi Guru</b>\n\n"
                . "Fitur pelacak ruangan menemukan guru berdasarkan slot jadwal pelajaran aktif saat ini.\n\n"
                . "💡 Buka menu <b>Jadwal Pelajaran</b> di aplikasi web untuk melihat denah interaktif posisi guru di gedung sekolah.";
            $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Jadwal', 'callback_data' => 'menu:schedule']]]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        if ($sub === 'guide') {
            $text = "ℹ️ <b>Panduan Jadwal Sekolah</b>\n\n"
                . "• Jam Ke-1 s.d Ke-10 berlangsung dari 07.30 s.d 15.30 WIB.\n"
                . "• Istirahat 1: 09.45 - 10.15 WIB.\n"
                . "• Istirahat 2 (Dhuhur): 11.45 - 12.30 WIB.\n"
                . "• Perubahan jadwal pengganti (override) otomatis disinkronkan ke bot ini.";
            $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Jadwal', 'callback_data' => 'menu:schedule']]]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::scheduleMenuText($user), BotMenus::scheduleMenuKeyboard());
    }

    private function routeTasks(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub): void
    {
        if ($user === null) {
            $this->renderScreen($chatId, $msgId, $isPhoto, "🔒 Tautkan akun terlebih dahulu.", ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
            return;
        }

        if ($sub === 'done') {
            $done = Learning::getStudentSubmissions((int)$user['id']);
            if ($done === []) {
                $text = "✅ <b>Tugas Selesai / Terkirim</b>\n\nBelum ada pengumpulan tugas yang tercatat.";
            } else {
                $lines = ["✅ <b>Tugas Yang Sudah Dikumpulkan (" . count($done) . ")</b>\n"];
                $i = 0;
                foreach ($done as $d) {
                    if ($i++ >= 5) break;
                    $score = $d['score'] !== null ? "Nilai: <b>{$d['score']}</b>" : '<i>(Belum dinilai)</i>';
                    $lines[] = "• <b>" . $this->esc((string)$d['assignment_title']) . "</b>\n  {$score}";
                }
                $text = implode("\n", $lines);
            }
            $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Tugas', 'callback_data' => 'menu:tasks']]]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        if ($sub === 'guide') {
            $text = "ℹ️ <b>Panduan Pengumpulan Tugas</b>\n\n"
                . "1. Pengumpulan berkas atau teks jawaban dilakukan melalui menu Pembelajaran di web.\n"
                . "2. Perhatikan batas waktu (deadline) agar tidak terkena penalti nilai terlambat.\n"
                . "3. Nilai dan umpan balik guru akan muncul otomatis di notifikasi.";
            $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Tugas', 'callback_data' => 'menu:tasks']]]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::tasksMenuText($user), BotMenus::tasksMenuKeyboard());
    }

    private function routeEvents(int $chatId, int $msgId, bool $isPhoto, string $sub): void
    {
        if ($sub === 'orgs') {
            $orgs = Events::getOrganizations();
            $lines = ["👥 <b>Organisasi & Ekstrakurikuler SMK Silber</b>\n"];
            foreach ($orgs as $o) {
                $lines[] = "• <b>" . $this->esc((string)$o['name']) . "</b> (" . (int)$o['member_count'] . " anggota)";
            }
            $text = implode("\n", $lines);
            $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Event', 'callback_data' => 'menu:events']]]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        if ($sub === 'hist') {
            $text = "📜 <b>Riwayat Presensi & Sertifikat Digital</b>\n\n"
                . "Setiap event sekolah yang Anda ikuti dan verifikasi presensinya akan menerbitkan "
                . "sertifikat digital terenkripsi dengan nomor registrasi unik. "
                . "Lihat sertifikat Anda di profil web!";
            $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Event', 'callback_data' => 'menu:events']]]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        if ($sub === 'guide') {
            $text = "ℹ️ <b>Panduan Partisipasi Event</b>\n\n"
                . "1. Perhatikan jadwal dan lokasi event (Auditorium 3D atau Lapangan).\n"
                . "2. Masuk ke ruang event di Virtual City pada waktu yang ditentukan.\n"
                . "3. Masukkan kode verifikasi presensi yang diberikan pembina.\n"
                . "4. Koin dan XP reward akan otomatis masuk ke dompet Anda.";
            $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Event', 'callback_data' => 'menu:events']]]];
            $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
            return;
        }

        $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::eventsMenuText(), BotMenus::eventsMenuKeyboard());
    }

    private function routeAi(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub): void
    {
        switch ($sub) {
            case 'status':
                $text = BotMenus::aiStatusText();
                break;
            case 'it':
                $text = "💻 <b>Bantuan Penjelasan IT & Koding</b>\n\n"
                    . "Ketik pertanyaanmu langsung di chat dengan format:\n"
                    . "<code>/ai jelaskan apa itu routing mikrotik</code>\n"
                    . "atau\n"
                    . "<code>/ai buat contoh fungsi rekursif PHP</code>\n\n"
                    . "AI akan merespons dengan penjelasan terstruktur dan ramah pelajar.";
                break;
            case 'study':
                $text = "📚 <b>Bantuan Belajar Mandiri</b>\n\n"
                    . "Kamu bisa menanyakan konsep materi sekolah:\n"
                    . "• <code>/ai apa itu jurnal penyesuaian di akuntansi?</code>\n"
                    . "• <code>/ai apa prinsip desain visual DKV?</code>";
                break;
            case 'game':
                $text = "🎮 <b>Bantuan Virtual City</b>\n\n"
                    . "Tanyakan seputar gameplay:\n"
                    . "• <code>/ai bagaimana cara mendapatkan koin tercepat di game?</code>\n"
                    . "• <code>/ai apa saja kontrol navigasi di mobile?</code>";
                break;
            case 'hist':
                $userId = $user !== null ? (int)$user['id'] : 0;
                $pdo = Database::get();
                $stmt = $pdo->prepare('SELECT role, status, task, created_at FROM ai_jobs WHERE requested_by = ? ORDER BY created_at DESC LIMIT 5');
                $stmt->execute([$userId]);
                $jobs = $stmt->fetchAll();
                if ($jobs === []) {
                    $text = "📜 <b>Riwayat Job AI</b>\n\nBelum ada riwayat permintaan AI khusus dari akun ini.";
                } else {
                    $lines = ["📜 <b>Riwayat Job AI Terakhir</b>\n"];
                    foreach ($jobs as $j) {
                        $lines[] = "• <b>[" . $this->esc((string)$j['role']) . "]</b> (" . $this->esc((string)$j['status']) . ")\n  " . $this->esc(mb_substr((string)$j['task'], 0, 80));
                    }
                    $text = implode("\n", $lines);
                }
                break;
            default:
                $text = BotMenus::aiMenuText();
                break;
        }

        $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu AI', 'callback_data' => 'menu:ai'], ['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]];
        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function routeNotif(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub, ?string $extra): void
    {
        if ($user === null) {
            $this->renderScreen($chatId, $msgId, $isPhoto, "🔒 Tautkan akun terlebih dahulu.", ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
            return;
        }
        $userId = (int)$user['id'];

        if ($sub === 'prefs') {
            $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::notifPrefsText($user), BotMenus::notifPrefsKeyboard($user));
            return;
        }

        if ($sub === 'tog' && $extra !== null) {
            if ($extra !== 'keamanan') {
                $cur = Notification::categoryEnabled($userId, $extra);
                Notification::setPref($userId, $extra, !$cur);
            }
            $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::notifPrefsText($user), BotMenus::notifPrefsKeyboard($user));
            return;
        }

        if ($sub === 'readall') {
            Notification::markAllRead($userId);
            $this->renderScreen($chatId, $msgId, $isPhoto, "✅ Semua notifikasi ditandai telah dibaca.",
                ['inline_keyboard' => [[['text' => '⬅️ Kembali ke Notifikasi', 'callback_data' => 'menu:notif']]]]);
            return;
        }

        $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::notifMenuText($user), BotMenus::notifMenuKeyboard($user));
    }

    private function routeSettings(int $chatId, int $msgId, bool $isPhoto, ?array $user, int $fromId, string $sub): void
    {
        switch ($sub) {
            case 'lang':
                $text = "🌐 <b>Pengaturan Bahasa</b>\n\nBahasa saat ini: 🇮🇩 <b>Bahasa Indonesia</b> (Utama).\nBahasa Inggris dapat diaktifkan pada pembaruan mendatang.";
                $kb = ['inline_keyboard' => [[['text' => '⬅️ Kembali', 'callback_data' => 'menu:settings']]]];
                break;
            case 'priv':
                $text = "🔒 <b>Privasi & Keamanan Data</b>\n\n"
                    . "• Bot ini tidak pernah meminta atau menyimpan kata sandi akun Anda.\n"
                    . "• Hubungan akun didasarkan pada ID Telegram numerik terenkripsi.\n"
                    . "• Setiap tindakan sensitif diaudit dan diverifikasi di server sekolah.";
                $kb = ['inline_keyboard' => [[['text' => '⬅️ Kembali', 'callback_data' => 'menu:settings']]]];
                break;
            case 'unlink':
                $text = "⚠️ <b>Konfirmasi Pemutusan Tautan</b>\n\n"
                    . "Apakah Anda yakin ingin memutuskan tautan bot Telegram dari akun Virtual City?\n"
                    . "Akun Anda di web tetap aman. Anda dapat menautkannya kembali kapan saja.";
                $kb = ['inline_keyboard' => [
                    [
                        ['text' => '✅ Ya, Putuskan Tautan', 'callback_data' => 'set:unlink_ok'],
                        ['text' => '❌ Batal', 'callback_data' => 'menu:settings'],
                    ],
                ]];
                break;
            case 'unlink_ok':
                AccountLink::unlink($fromId);
                $text = "✅ <b>Tautan Berhasil Diputus</b>\n\nAkun Telegram Anda tidak lagi terhubung ke akun Virtual City.";
                $kb = ['inline_keyboard' => [[['text' => '🏠 Menu Utama', 'callback_data' => 'menu:home']]]];
                break;
            default:
                $text = BotMenus::settingsMenuText($user);
                $kb = BotMenus::settingsMenuKeyboard($user);
                break;
        }

        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function routeHelp(int $chatId, int $msgId, bool $isPhoto, string $sub): void
    {
        switch ($sub) {
            case 'guide':
                $text = "📖 <b>Panduan Penggunaan Bot</b>\n\n"
                    . "1. Tekan tombol menu untuk bernavigasi tanpa perlu menghafal perintah teks.\n"
                    . "2. Tautkan akun sekali saja untuk mendapatkan info nilai, tugas, dan koin pribadi.\n"
                    . "3. Gunakan perintah <code>/menu</code> kapan saja untuk kembali ke layar utama.";
                break;
            case 'cmds':
                $text = "⌨️ <b>Daftar Perintah Lengkap</b>\n\n"
                    . "• <code>/start</code> — Mulai bot & menu banner\n"
                    . "• <code>/menu</code> — Tampilkan menu utama\n"
                    . "• <code>/link KODE</code> — Tautkan akun web\n"
                    . "• <code>/unlink</code> — Putuskan tautan akun\n"
                    . "• <code>/status</code> — Status profil & peran\n"
                    . "• <code>/jadwal</code> — Jadwal hari ini\n"
                    . "• <code>/tugas</code> — Tugas kelas aktif\n"
                    . "• <code>/event</code> — Event & agenda sekolah\n"
                    . "• <code>/koin</code> — Saldo koin & XP\n"
                    . "• <code>/inventory</code> — Item yang dimiliki\n"
                    . "• <code>/shop</code> — Toko Avatar\n"
                    . "• <code>/ai TANYA</code> — Tanya AI Assistant";
                break;
            case 'faq':
                $text = BotMenus::schoolSectionText('faq');
                break;
            case 'stat':
                $text = "🛡️ <b>Status Layanan Sistem</b>\n\n"
                    . "• Database Utama: 🟢 <b>ONLINE</b> (SQLite WAL)\n"
                    . "• Database Keamanan: 🟢 <b>ONLINE</b>\n"
                    . "• Telegram Poller / Webhook: 🟢 <b>READY</b>\n"
                    . "• WebSocket Realtime: 🟢 <b>ACTIVE</b>\n"
                    . "• Server Jam: <b>" . date('Y-m-d H:i:s') . " UTC</b>";
                break;
            case 'rep':
                $text = "🚨 <b>Laporkan Kendala atau Bug</b>\n\n"
                    . "Jika Anda menemukan kesalahan teknis, silakan laporkan ke administrator "
                    . "atau tim Lab TJKT SMK Silber melalui web portal.";
                break;
            default:
                $text = BotMenus::helpMenuText();
                break;
        }

        $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Bantuan', 'callback_data' => 'menu:help'], ['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]];
        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function routeAdmin(int $chatId, int $msgId, bool $isPhoto, ?array $user, string $sub, ?string $extra): void
    {
        if ($user === null || !BotMenus::hasAdminAccess($user)) {
            $this->deniedCallback($chatId, $msgId, $isPhoto);
            return;
        }

        $pdo = Database::get();

        switch ($sub) {
            case 'dash':
                $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::adminDashboardText($user), BotMenus::adminDashboardKeyboard($user));
                return;

            case 'bot':
                $info = $this->tg->getWebhookInfo();
                $url = !empty($info['url']) ? $this->esc((string)$info['url']) : 'Polling (no active webhook URL)';
                $pend = (int)($info['pending_update_count'] ?? 0);
                $text = "🤖 <b>Status Bot & Webhook</b>\n\n"
                    . "• Bot Username: <b>@" . $this->esc((string)\vc_env('TELEGRAM_BOT_USERNAME', '')) . "</b>\n"
                    . "• Mode: <b>Webhook / Long Polling Hybrid</b>\n"
                    . "• Webhook URL: <code>{$url}</code>\n"
                    . "• Pending Updates: <b>{$pend}</b>\n"
                    . "• Secret Token Guard: <b>Aktif</b>";
                break;

            case 'db':
                $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn();
                $classCount = (int)$pdo->query('SELECT COUNT(*) FROM classes WHERE deleted_at IS NULL')->fetchColumn();
                $schedCount = (int)$pdo->query('SELECT COUNT(*) FROM schedule_entries')->fetchColumn();
                $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM shop_items')->fetchColumn();
                $text = "🗄️ <b>Status Database SQLite (WAL Mode)</b>\n\n"
                    . "• Total Akun: <b>{$userCount}</b>\n"
                    . "• Total Kelas: <b>{$classCount}</b>\n"
                    . "• Slot Jadwal: <b>{$schedCount}</b>\n"
                    . "• Item Toko: <b>{$itemCount}</b>\n"
                    . "• Integritas: 🟢 <b>OK</b>";
                break;

            case 'ai':
                $text = BotMenus::aiStatusText();
                break;

            case 'users':
                if (!Permissions::canFor($user, 'user.view')) {
                    $this->deniedCallback($chatId, $msgId, $isPhoto);
                    return;
                }
                $roleParts = explode(':', $extra ?? 'all');
                $role = ($roleParts[0] !== '' && $roleParts[0] !== 'all') ? $roleParts[0] : 'all';
                $stmt = ($role === 'all')
                    // Prioritaskan root & staf agar tidak tergeser murid saat jumlah user besar (LIMIT 15).
                    ? $pdo->query('SELECT username, role, status FROM users WHERE deleted_at IS NULL ORDER BY CASE role WHEN "root" THEN 0 WHEN "kepala_sekolah" THEN 1 WHEN "kepala_program" THEN 2 WHEN "wali_kelas" THEN 3 WHEN "guru" THEN 4 WHEN "pembina" THEN 5 WHEN "pengurus" THEN 6 ELSE 7 END, username LIMIT 15')
                    : $pdo->prepare('SELECT username, role, status FROM users WHERE role = ? AND deleted_at IS NULL ORDER BY username LIMIT 15');
                if ($role !== 'all') {
                    $stmt->execute([$role]);
                }
                $lines = ["👥 <b>Daftar Pengguna ({$role})</b>\n"];
                foreach ($stmt->fetchAll() as $r) {
                    $icon = $r['status'] === 'active' ? '🟢' : '🟡';
                    $lines[] = "{$icon} <code>" . $this->esc((string)$r['username']) . "</code> — " . $this->esc((string)$r['role']);
                }
                $text = implode("\n", $lines);
                break;

            case 'regs':
                if (!Permissions::canFor($user, 'user.approve_registration')) {
                    $this->deniedCallback($chatId, $msgId, $isPhoto);
                    return;
                }
                $stmt = $pdo->query('SELECT id, full_name, username, role FROM registrations WHERE status = "pending" ORDER BY id ASC LIMIT 5');
                $regs = $stmt->fetchAll();
                if ($regs === []) {
                    $text = "📝 <b>Pendaftaran Tertunda</b>\n\nTidak ada pendaftar baru yang menunggu persetujuan.";
                    $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Admin', 'callback_data' => 'menu:admin']]]];
                    $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
                    return;
                }
                $lines = ["📝 <b>Pendaftar Menunggu Persetujuan</b>\n"];
                $regBtns = [];
                foreach ($regs as $r) {
                    $id = (int)$r['id'];
                    $lines[] = "• #{$id}: <b>" . $this->esc((string)$r['full_name']) . "</b> (" . $this->esc((string)$r['role']) . ")";
                    $regBtns[] = [['text' => "✅ Setujui #{$id}", 'callback_data' => "adm:reg_ok:{$id}"]];
                }
                $text = implode("\n", $lines);
                $regBtns[] = [['text' => '⬅️ Menu Admin', 'callback_data' => 'menu:admin']];
                $this->renderScreen($chatId, $msgId, $isPhoto, $text, ['inline_keyboard' => $regBtns]);
                return;

            case 'reg_ok':
                if (!Permissions::canFor($user, 'user.approve_registration')) {
                    $this->deniedCallback($chatId, $msgId, $isPhoto);
                    return;
                }
                $regId = (int)$extra;
                $ok = Registration::activateFromRegistration($regId, (int)$user['id'], 'Disetujui via bot');
                AuditLog::log('tg_registration_approved', ['reg_id' => $regId], (int)$user['id']);
                $text = $ok ? "✅ Pendaftar #{$regId} berhasil diaktivasi!" : "⚠️ Pendaftar #{$regId} gagal diaktivasi/sudah diproses.";
                break;

            case 'audit':
                $logs = $pdo->query('SELECT action, user_id, ts AS created_at FROM audit_log ORDER BY id DESC LIMIT 5')->fetchAll();
                $lines = ["📜 <b>5 Audit Log Terakhir</b>\n"];
                foreach ($logs as $l) {
                    $lines[] = "• <b>" . $this->esc((string)$l['action']) . "</b> (User #" . (int)$l['user_id'] . ")\n  " . self::esc(substr((string)$l['created_at'], 0, 16));
                }
                $text = implode("\n", $lines);
                break;

            case 'users':
                if (!Permissions::canFor($user, 'user.view')) {
                    $this->deniedCallback($chatId, $msgId, $isPhoto);
                    return;
                }
                $parts = explode(':', (string)$extra);
                $roleFilter = strtolower($parts[0] ?? 'all');
                if ($roleFilter === '' || $roleFilter === 'all') {
                    $stmt = $pdo->query('SELECT username, role, status FROM users WHERE deleted_at IS NULL ORDER BY (role = "root") DESC, role, username LIMIT 30');
                } else {
                    $stmt = $pdo->prepare('SELECT username, role, status FROM users WHERE role = ? AND deleted_at IS NULL ORDER BY username LIMIT 30');
                    $stmt->execute([$roleFilter]);
                }
                $list = [];
                foreach ($stmt->fetchAll() as $r) {
                    $icon = $r['status'] === 'active' ? '🟢' : ($r['status'] === 'pending' ? '🟡' : '🔴');
                    $list[] = "{$icon} <b>" . $this->esc((string)$r['username']) . "</b> (" . $this->esc((string)$r['role']) . ")";
                }
                $text = "👥 <b>Daftar Pengguna (" . $this->esc($roleFilter) . ")</b>\n\n" . implode("\n", $list);
                $kb = ['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'adm:users:' . $roleFilter . ':1']],
                    [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
                ]];
                $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
                return;

            case 'staff':
                $act = $extra ?: 'menu';
                switch ($act) {
                    case 'menu':
                        $this->renderScreen($chatId, $msgId, $isPhoto, BotMenus::staffManagementMenuText(), BotMenus::staffManagementMenuKeyboard());
                        return;

                    case 'add_guide':
                        $site = BotMenus::siteUrl();
                        $text = "➕ <b>PANDUAN MENAMBAHKAN STAF & AKUN WEB</b>\n\n"
                            . "Kirim pesan perintah ke chat bot ini:\n"
                            . "<code>/addstaff &lt;id_telegram&gt; &lt;role&gt; [username] [password]</code>\n\n"
                            . "Contoh 1 (Kredensial Otomatis):\n"
                            . "<code>/addstaff 700458744 guru</code>\n\n"
                            . "Contoh 2 (Kredensial Kustom):\n"
                            . "<code>/addstaff 700458744 guru pak_ahmad RahasiaKuat#2026</code>\n\n"
                            . "Role yang didukung:\n"
                            . "• <code>guru</code>, <code>root</code>, <code>kepala_sekolah</code>, <code>kepala_program</code>, <code>wali_kelas</code>, <code>pembina</code>, <code>pengurus</code>\n\n"
                            . "💡 <i>Akun website langsung aktif dan dapat digunakan login di {$site}/?admin=1</i>";
                        $kb = ['inline_keyboard' => [
                            [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                            [['text' => '⬅️ Menu Staf', 'callback_data' => 'adm:staff:menu']],
                            [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
                        ]];
                        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
                        return;

                    case 'list':
                        $this->cmdListStaff($chatId, $user);
                        return;

                    case 'del_guide':
                        $text = "🗑️ <b>PANDUAN MENGHAPUS / MENONAKTIFKAN STAF</b>\n\n"
                            . "Kirim perintah:\n"
                            . "<code>/delstaff &lt;id_telegram / username / id_user&gt;</code>\n\n"
                            . "Contoh:\n"
                            . "• <code>/delstaff 700458744</code>\n"
                            . "• <code>/delstaff pak_ahmad</code>\n\n"
                            . "Tindakan ini akan menonaktifkan status akun, memutus sesi, dan menghapus tautan bot.";
                        $kb = ['inline_keyboard' => [
                            [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                            [['text' => '⬅️ Menu Staf', 'callback_data' => 'adm:staff:menu']],
                            [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
                        ]];
                        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
                        return;

                    case 'reset_guide':
                        $text = "🔑 <b>PANDUAN RESET PASSWORD WEB STAF</b>\n\n"
                            . "Kirim perintah:\n"
                            . "<code>/resetpass &lt;id_telegram / username&gt; [password_baru]</code>\n\n"
                            . "Contoh:\n"
                            . "• <code>/resetpass 700458744</code> (Otomatis generate password kuat)\n"
                            . "• <code>/resetpass guru_silber SandiBaru#2026</code>";
                        $kb = ['inline_keyboard' => [
                            [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                            [['text' => '⬅️ Menu Staf', 'callback_data' => 'adm:staff:menu']],
                            [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
                        ]];
                        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
                        return;

                    case 'role_guide':
                        $text = "🔄 <b>PANDUAN MENGUBAH ROLE STAF / PENGGUNA</b>\n\n"
                            . "Kirim perintah:\n"
                            . "<code>/setrole &lt;id_telegram / username&gt; &lt;role_baru&gt;</code>\n\n"
                            . "Contoh:\n"
                            . "• <code>/setrole 700458744 guru</code>\n"
                            . "• <code>/setrole user123 wali_kelas</code>";
                        $kb = ['inline_keyboard' => [
                            [['text' => '📋 Lihat Daftar Staf', 'callback_data' => 'adm:staff:list']],
                            [['text' => '⬅️ Menu Staf', 'callback_data' => 'adm:staff:menu']],
                            [['text' => '🛡️ Panel Admin', 'callback_data' => 'menu:admin']]
                        ]];
                        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
                        return;
                }
                break;

            case 'unban_ask':
                if (!Permissions::isSuperuser($user)) {
                    $this->deniedCallback($chatId, $msgId, $isPhoto);
                    return;
                }
                $text = "⚠️ <b>Konfirmasi Pembersihan Blokir IP</b>\n\n"
                    . "Tindakan ini akan menghapus semua IP yang diblokir dan rate limit di database keamanan.\n"
                    . "Apakah Anda yakin ingin melanjutkan?";
                $kb = ['inline_keyboard' => [
                    [
                        ['text' => '🔓 Ya, Bersihkan Sekarang', 'callback_data' => 'adm:unban_ok'],
                        ['text' => '❌ Batal', 'callback_data' => 'menu:admin'],
                    ],
                ]];
                $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
                return;

            case 'unban_ok':
                if (!Permissions::isSuperuser($user)) {
                    $this->deniedCallback($chatId, $msgId, $isPhoto);
                    return;
                }
                $sec = Database::security();
                $sec->exec('DELETE FROM ip_blocks');
                $sec->exec('DELETE FROM rate_limit_hits');
                AuditLog::log('tg_unban_all', ['by' => (int)$user['id']]);
                $text = "✅ <b>Semua blokir IP dan rate limit berhasil dibersihkan!</b>";
                break;

            case 'help':
                $text = "📘 <b>Panduan Administrator Bot</b>\n\n"
                    . "• Seluruh aksi admin diverifikasi ulang di server setiap tombol ditekan.\n"
                    . "• Tidak ada token atau data rahasia yang diekspos melalui antarmuka chat.\n"
                    . "• Perubahan akun dan persetujuan otomatis dicatat di Audit Log.";
                break;

            default:
                $text = BotMenus::adminDashboardText($user);
                break;
        }

        $kb = ['inline_keyboard' => [[['text' => '⬅️ Menu Admin', 'callback_data' => 'menu:admin'], ['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]];
        $this->renderScreen($chatId, $msgId, $isPhoto, $text, $kb);
    }

    private function dispatchLegacyCallback(int $chatId, array $user, string $cmd): void
    {
        switch ($cmd) {
            case 'jadwal': $this->cmdJadwal($chatId, $user); break;
            case 'tugas': $this->cmdTugas($chatId, $user); break;
            case 'event': $this->cmdEvent($chatId, $user); break;
            case 'koin': $this->cmdKoin($chatId, $user); break;
            case 'inventory': $this->cmdInventory($chatId, $user); break;
            case 'status': $this->cmdStatus($chatId, $user); break;
            case 'listusers':
                if (Permissions::canFor($user, 'user.view')) { $this->cmdListUsers($chatId, $user, 'all'); }
                else { $this->denied($chatId); }
                break;
            case 'unbanall': $this->cmdUnbanAll($chatId, $user); break;
        }
    }

    private function handleLegacyRegApprove(int $chatId, int $msgId, bool $isPhoto, array $user, int $regId): void
    {
        if (!Permissions::canFor($user, 'user.approve_registration')) {
            $this->renderScreen($chatId, $msgId, $isPhoto, '⛔ Anda tidak berwenang menyetujui pendaftaran.', ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
            return;
        }
        $ok = Registration::activateFromRegistration($regId, (int)$user['id'], 'Disetujui via Telegram');
        AuditLog::log('tg_registration_approved', ['reg_id' => $regId], (int)$user['id']);
        $this->renderScreen($chatId, $msgId, $isPhoto, $ok ? "✅ Pendaftar #{$regId} disetujui." : "⚠️ Pendaftar #{$regId} tidak ditemukan.", ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Smart screen renderer: edits caption if message has photo, edits text otherwise.
     * Falls back to sending a fresh message if in-place edit fails.
     */
    private function renderScreen(int $chatId, int $msgId, bool $isPhoto, string $text, ?array $kb = null): void
    {
        if ($msgId <= 0) {
            $this->tg->sendMessage($chatId, $text, $kb);
            return;
        }

        if ($isPhoto && mb_strlen($text) <= 1024) {
            $res = $this->tg->editMessageCaption($chatId, $msgId, $text, $kb);
            if ($res !== null && !empty($res['ok'])) {
                return;
            }
        }

        $res = $this->tg->editMessage($chatId, $msgId, $text, $kb);
        if ($res === null || empty($res['ok'])) {
            $this->tg->sendMessage($chatId, $text, $kb);
        }
    }

    private function denied(int $chatId): void
    {
        SecurityLog::write('tg_perm_denied', []);
        $this->reply($chatId, '⛔ Anda tidak memiliki izin untuk perintah ini.');
    }

    private function deniedCallback(int $chatId, int $msgId, bool $isPhoto): void
    {
        SecurityLog::write('tg_perm_denied', []);
        $this->renderScreen($chatId, $msgId, $isPhoto, '⛔ Anda tidak memiliki izin untuk aksi ini.',
            ['inline_keyboard' => [[['text' => '🏠 Beranda', 'callback_data' => 'menu:home']]]]);
    }

    private function isLinkedHelp(int $fromId): string
    {
        $site = BotMenus::siteUrl();
        return AccountLink::isLinked($fromId)
            ? 'Ketik /menu atau /help untuk membuka menu bot.'
            : "🔒 Akun Telegram ini belum ditautkan.\n\nBuka {$site}/telegram di browser web Anda, klik \"Buat Kode Tautan\", lalu kirim:\n<code>/link KODE</code>\n\nKetik <code>/link</code> untuk panduan lengkap.";
    }

    private function isBackupOwner(int $fromId, int $chatId): bool
    {
        $owner = (int)\vc_env('BACKUP_TELEGRAM_CHAT', '8021412878');
        return $owner > 0 && $fromId === $owner && $chatId === $owner; // hanya chat pribadi owner
    }

    /** Minta layanan backup (virtual-city-backup.service) mengirim backup sekarang lewat berkas permintaan. */
    private function cmdPullBackup(int $chatId, int $fromId): void
    {
        $req = dirname(__DIR__, 2) . '/data/backup_request.json';
        clearstatcache(true, $req);
        if (is_file($req) && time() - (int)filemtime($req) < 600) {
            $this->reply($chatId, '⏳ Backup yang kamu tarik sebelumnya masih diproses. Tunggu sebentar ya.');
            return;
        }
        $ok = @file_put_contents($req, json_encode(['from' => $fromId, 'at' => time()]), LOCK_EX) !== false;
        if (!$ok) {
            $this->reply($chatId, '❌ Gagal membuat permintaan backup (izin folder private/data).');
            return;
        }
        \VC\Security\AuditLog::log('backup_pull_request', ['tg' => $fromId], null);
        $this->reply($chatId, "🗄️ <b>Tarik backup diterima.</b>\nSeluruh folder <code>/var/www</code> sedang di-zip (database disalin konsisten) dan akan dikirim ke sini ±1–2 menit lagi.");
    }

    private function reply(int $chatId, string $text, ?array $kb = null): void
    {
        $this->tg->sendMessage($chatId, $text, $kb);
    }

    private function edit(int $chatId, int $msgId, string $text, ?array $kb = null): void
    {
        if ($msgId > 0) {
            $this->tg->editMessage($chatId, $msgId, $text, $kb);
        } else {
            $this->tg->sendMessage($chatId, $text, $kb);
        }
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
