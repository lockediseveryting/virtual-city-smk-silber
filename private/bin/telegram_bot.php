<?php
// Telegram long-polling runner (use when no public HTTPS for webhook).
//   php private/bin/telegram_bot.php            # runs forever
//   nohup php private/bin/telegram_bot.php &    # background via systemd/tmux
// A single instance is enforced with flock — two pollers would fight over getUpdates.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require __DIR__ . '/../src/bootstrap.php';

use VC\TgBot\Bot;
use VC\TgBot\Telegram;

$token = \vc_env('TELEGRAM_BOT_TOKEN', '');
if ($token === '') {
    fwrite(STDERR, "Isi private/config/telegram.env dengan TELEGRAM_BOT_TOKEN terlebih dahulu.\n");
    exit(1);
}

// Single-worker lock.
$lockPath = VC_PRIVATE . '/data/telegram_bot.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Poller Telegram lain sedang berjalan (lock: {$lockPath}). Keluar.\n");
    exit(1);
}

$tg = new Telegram($token);
$bot = new Bot($tg);

// Best-effort: confirm the token works before looping.
$me = $tg->getMe();
echo $me ? ("Bot @" . ($me['username'] ?? '?') . " terhubung.\n") : "⚠️ Tidak bisa verifikasi token (lanjut polling).\n";
echo "Bot berjalan (long polling). Ctrl+C untuk berhenti.\n";

$offset = 0;
while (true) {
    $res = $tg->call('getUpdates', ['offset' => $offset, 'timeout' => 60, 'limit' => 10, 'allowed_updates' => ['message', 'callback_query']], 70);
    if ($res === null) {
        sleep(3);
        continue;
    }
    foreach (($res['result'] ?? []) as $update) {
        $offset = max($offset, (int)($update['update_id'] ?? 0) + 1);
        $bot->handle($update); // Bot::handle dedups by update_id
    }
}
