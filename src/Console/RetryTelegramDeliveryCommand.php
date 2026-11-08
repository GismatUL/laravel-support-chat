<?php

namespace Gmammadov\SupportChat\Console;

use Gmammadov\SupportChat\Enums\TelegramDeliveryStatus;
use Gmammadov\SupportChat\Jobs\SendMessageToTelegram;
use Gmammadov\SupportChat\Models\Message;
use Gmammadov\SupportChat\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RetryTelegramDeliveryCommand extends Command
{
    protected $signature = 'support-chat:retry-telegram';

    protected $description = 'Re-send chat messages (customer messages and admin replies) that failed to reach Telegram';

    public function handle(TelegramClient $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->components->warn('Telegram is not configured, nothing to retry.');

            return self::SUCCESS;
        }

        $since = Carbon::now()->subDay();
        $stale = Carbon::now()->subMinutes(5);

        // A send that never finished (process crash) is treated as failed.
        Message::query()
            ->where('telegram_status', TelegramDeliveryStatus::Sending->value)
            ->where('updated_at', '<', $stale)
            ->update(['telegram_status' => TelegramDeliveryStatus::Failed->value]);

        $ids = Message::query()
            ->where('created_at', '>=', $since)
            ->where('telegram_attempts', '<', SendMessageToTelegram::MAX_ATTEMPTS)
            ->where(fn ($query) => $query
                ->where('telegram_status', TelegramDeliveryStatus::Failed->value)
                ->orWhere(fn ($query) => $query
                    ->where('telegram_status', TelegramDeliveryStatus::Pending->value)
                    ->where('created_at', '<', $stale)))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($ids as $id) {
            SendMessageToTelegram::dispatchSync($id);
        }

        $this->components->info(sprintf('Retried %d message(s).', $ids->count()));

        return self::SUCCESS;
    }
}
