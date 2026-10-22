<?php
// Telegram webhook receiver, shared by the public route (/tg/webhook) and the CLI wrapper.
// Guarded by a secret token, rate limited, size limited and duplicate-safe.
declare(strict_types=1);

namespace VC\TgBot;

use VC\Security\Headers;
use VC\Security\Ip;
use VC\Security\RateLimiter;
use VC\Security\SecurityLog;

final class Webhook
{
    /** Handle one webhook request. Always answers 200 to Telegram except on auth/size errors. */
    public static function run(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            exit;
        }

        Headers::send();

        if (RateLimiter::hit('ws_ip', Ip::bucket()) > 0) {
            http_response_code(429);
            exit;
        }

        // Fail closed: an unconfigured secret means the endpoint cannot be trusted,
        // so we refuse updates instead of accepting unauthenticated ones.
        $expected = trim((string)\vc_env('TELEGRAM_WEBHOOK_SECRET', ''));
        if ($expected === '' || str_starts_with($expected, 'GANTI_') || str_starts_with($expected, 'ganti-')) {
            SecurityLog::write('tg_webhook_secret_unset', []);
            http_response_code(503);
            exit;
        }
        $got = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if (!hash_equals($expected, $got)) {
            SecurityLog::write('tg_webhook_bad_secret', []);
            http_response_code(403);
            exit;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 256 * 1024) {
            http_response_code(413);
            exit;
        }
        $update = json_decode($raw, true);
        if (!is_array($update)) {
            http_response_code(200); // ack quietly; malformed frames are dropped
            echo 'ok';
            return;
        }

        try {
            $bot = new Bot(new Telegram());
            $bot->handle($update); // update-level dedup happens inside Bot
        } catch (\Throwable $e) {
            SecurityLog::write('tg_webhook_exception', ['err' => substr($e->getMessage(), 0, 200)]);
        }
        echo 'ok';
    }
}
