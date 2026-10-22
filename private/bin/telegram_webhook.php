<?php
// Telegram webhook entry point. In production the app routes POST /tg/webhook here via
// public/index.php; this file exists for direct include/FastCGI setups.
// Set the webhook once (secret must match TELEGRAM_WEBHOOK_SECRET in config/telegram.env):
//   curl "https://api.telegram.org/bot<TOKEN>/setWebhook" \
//        -d url="https://DOMAIN/tg/webhook" -d secret_token="<SECRET>" -d drop_pending_updates=true
declare(strict_types=1);

require dirname(__DIR__, 2) . '/private/src/bootstrap.php';

\VC\TgBot\Webhook::run();
