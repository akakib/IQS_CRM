<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot API. With no TELEGRAM_BOT_TOKEN (tests, staging) every call
 * is only logged and remembered in $sent, so flows can be checked safely.
 */
class TelegramService
{
    /** @var list<array> fake mode: what would have been sent */
    public static array $sent = [];

    public function enabled(): bool
    {
        return filled(config('services.telegram.bot_token')) && ! app()->environment('testing');
    }

    /**
     * @param  list<list<array{text: string, callback_data?: string, web_app?: array{url: string}}>>  $buttons  inline keyboard rows
     * @return string|null message id
     */
    public function send(string|int|null $chatId, string $text, array $buttons = []): ?string
    {
        if (! $chatId) {
            return null;
        }
        $payload = ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($buttons) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $buttons]);
        }

        if (! $this->enabled()) {
            self::$sent[] = $payload;
            Log::info('[telegram fake] '.json_encode($payload, JSON_UNESCAPED_UNICODE));

            return 'fake-'.count(self::$sent);
        }

        $response = $this->call('sendMessage', $payload);

        return isset($response['result']['message_id']) ? (string) $response['result']['message_id'] : null;
    }

    public function answerCallback(string $callbackId, string $text): void
    {
        if ($this->enabled()) {
            $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 190)]);
        }
    }

    /**
     * Mini App sign-in: initData is valid only if its hash matches
     * HMAC-SHA256 with key HMAC-SHA256("WebAppData", bot token).
     *
     * @return array|null the Telegram user, or null when invalid / older than a day
     */
    public function verifyInitData(string $initData): ?array
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '' || $initData === '') {
            return null;
        }
        parse_str($initData, $fields);
        $hash = $fields['hash'] ?? '';
        unset($fields['hash']);
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);

        if (! hash_equals(hash_hmac('sha256', $check, $secret), (string) $hash) || (int) ($fields['auth_date'] ?? 0) < time() - 86400) {
            return null;
        }

        return json_decode($fields['user'] ?? 'null', true);
    }

    private function call(string $method, array $payload): array
    {
        try {
            $response = Http::timeout(10)->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method, $payload);

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning("Telegram {$method} failed: {$e->getMessage()}");

            return [];
        }
    }
}
