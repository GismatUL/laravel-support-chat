<?php

namespace Gismatul\SupportChat\Telegram;

use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Thin wrapper around the official Telegram Bot API.
 * The bot token is scrubbed from every error message before it leaves this class.
 */
class TelegramClient
{
    public function __construct(protected HttpFactory $http)
    {
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->supportChatId() !== '';
    }

    public function supportChatId(): string
    {
        return (string) config('support-chat.telegram.chat_id');
    }

    /**
     * @return array<string, mixed> The sent Telegram Message object.
     */
    public function sendMessage(string $chatId, string $html, ?int $replyToMessageId = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($replyToMessageId) {
            $payload['reply_parameters'] = [
                'message_id' => $replyToMessageId,
                'allow_sending_without_reply' => true,
            ];
        }

        return (array) $this->call('sendMessage', $payload);
    }

    public function setWebhook(string $url, string $secret): mixed
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): mixed
    {
        return $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return (array) $this->call('getWebhookInfo');
    }

    protected function call(string $method, array $payload = []): mixed
    {
        if ($this->token() === '') {
            throw new TelegramException('Telegram bot token is not configured.');
        }

        try {
            $response = $this->http
                ->timeout((int) config('support-chat.telegram.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint($method), $payload);
        } catch (Throwable $exception) {
            throw new TelegramException($this->scrub(sprintf('Telegram %s request failed: %s', $method, $exception->getMessage())));
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ! ($body['ok'] ?? false)) {
            $description = is_array($body) ? ($body['description'] ?? 'unknown error') : 'invalid response';

            throw new TelegramException($this->scrub(sprintf('Telegram %s returned HTTP %d: %s', $method, $response->status(), $description)));
        }

        return $body['result'] ?? null;
    }

    protected function endpoint(string $method): string
    {
        return rtrim((string) config('support-chat.telegram.api_url', 'https://api.telegram.org'), '/') . '/bot' . $this->token() . '/' . $method;
    }

    protected function token(): string
    {
        return (string) config('support-chat.telegram.bot_token');
    }

    protected function scrub(string $text): string
    {
        $token = $this->token();

        return $token === '' ? $text : str_replace($token, '***', $text);
    }
}
