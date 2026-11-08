<?php

namespace Gmammadov\SupportChat\Console;

use Gmammadov\SupportChat\Telegram\TelegramClient;
use Gmammadov\SupportChat\Telegram\TelegramException;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'support-chat:telegram-webhook
        {--info : Show the current webhook status}
        {--delete : Remove the webhook}
        {--url= : Override the webhook URL (defaults to the route built from APP_URL)}';

    protected $description = 'Register, inspect or remove the Telegram webhook of the support chat bot';

    public function handle(TelegramClient $telegram): int
    {
        try {
            if ($this->option('info')) {
                $info = $telegram->getWebhookInfo();

                $this->table(['Key', 'Value'], collect($info)->map(fn ($value, $key) => [
                    $key,
                    is_scalar($value) ? var_export($value, true) : json_encode($value),
                ])->values()->all());

                return self::SUCCESS;
            }

            if ($this->option('delete')) {
                $telegram->deleteWebhook();
                $this->components->info('Webhook removed.');

                return self::SUCCESS;
            }

            $secret = (string) config('support-chat.telegram.webhook_secret');

            if (strlen($secret) < 16 || ! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
                $this->components->error('SUPPORT_CHAT_TELEGRAM_WEBHOOK_SECRET must be 16-256 characters of A-Z, a-z, 0-9, _ or -.');

                return self::FAILURE;
            }

            $url = $this->option('url') ?: route('support-chat.api.telegram.webhook');

            if (! str_starts_with($url, 'https://')) {
                $this->components->error("Telegram requires an HTTPS webhook URL, got: {$url}");

                return self::FAILURE;
            }

            $telegram->setWebhook($url, $secret);
            $this->components->info("Webhook set to {$url}");

            return self::SUCCESS;
        } catch (TelegramException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
