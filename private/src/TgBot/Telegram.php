<?php
// Minimal Telegram Bot API client (no external deps). Token loaded from private/config/telegram.env.
declare(strict_types=1);

namespace VC\TgBot;

final class Telegram
{
    private string $token;

    /** Captured API calls when TELEGRAM_DRY_RUN=1 (used by tests; no network, no real chats). */
    private array $sent = [];

    public function __construct(?string $token = null)
    {
        $token = $token ?? \vc_env('TELEGRAM_BOT_TOKEN', '');
        if ($token === '') {
            throw new \RuntimeException('Token bot Telegram belum diisi di private/config/telegram.env');
        }
        $this->token = $token;
    }

    /** Messages/calls captured during a dry run. */
    public function sent(): array
    {
        return $this->sent;
    }

    /** Low-level API call. Returns decoded array or null on failure (never throws to user chat). */
    public function call(string $method, array $payload = [], int $timeout = 15): ?array
    {
        if (\vc_env('TELEGRAM_DRY_RUN', '0') === '1') {
            $this->sent[] = ['method' => $method, 'payload' => $payload];
            return ['ok' => true, 'result' => ['message_id' => count($this->sent)]];
        }

        $url = "https://api.telegram.org/bot{$this->token}/" . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code !== 200) {
            \VC\Security\SecurityLog::write('tg_api_fail', ['method' => $method, 'code' => $code, 'err' => substr($err, 0, 100)]);
            return null;
        }
        $json = json_decode($res, true);
        return is_array($json) ? $json : null;
    }

    public function sendMessage(int $chatId, string $text, ?array $keyboard = null, bool $silent = false): ?array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_notification' => $silent,
        ];
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        return $this->call('sendMessage', $payload);
    }

    public function sendPhoto(int $chatId, string $photo, ?string $caption = null, ?array $keyboard = null, bool $silent = false): ?array
    {
        $payload = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'disable_notification' => $silent,
        ];
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        return $this->call('sendPhoto', $payload);
    }

    public function editMessageCaption(int $chatId, int $messageId, ?string $caption = null, ?array $keyboard = null): ?array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];
        if ($caption !== null) {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        return $this->call('editMessageCaption', $payload);
    }

    public function deleteMessage(int $chatId, int $messageId): ?array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function getWebhookInfo(): ?array
    {
        $res = $this->call('getWebhookInfo');
        return ($res && !empty($res['result'])) ? $res['result'] : null;
    }

    public function editMessage(int $chatId, int $messageId, string $text, ?array $keyboard = null): ?array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        return $this->call('editMessageText', $payload);
    }

    public function answerCallback(string $callbackId, string $text = ''): ?array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
        ]);
    }

    public function sendChatAction(int $chatId, string $action = 'typing'): ?array
    {
        return $this->call('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    /** Verify the token and return the bot profile (also used by /health checks). */
    public function getMe(): ?array
    {
        $res = $this->call('getMe');
        return ($res && !empty($res['result'])) ? $res['result'] : null;
    }

    /** Register the webhook URL with Telegram (drop_pending_updates avoids replaying old updates). */
    public function setWebhook(string $url, string $secretToken = ''): ?array
    {
        $payload = ['url' => $url, 'drop_pending_updates' => true, 'allowed_updates' => ['message', 'callback_query']];
        if ($secretToken !== '') {
            $payload['secret_token'] = $secretToken;
        }
        return $this->call('setWebhook', $payload);
    }

    public function deleteWebhook(): ?array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => true]);
    }

    public function setMyCommands(array $commands): ?array
    {
        return $this->call('setMyCommands', ['commands' => $commands]);
    }

    public function getFile(string $fileId): ?array
    {
        $res = $this->call('getFile', ['file_id' => $fileId]);
        return ($res && !empty($res['result'])) ? $res['result'] : null;
    }

    public function downloadFile(string $filePath): ?string
    {
        $url = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data === false || $code !== 200) {
            return null;
        }
        return (string)$data;
    }
}
