<?php

namespace Gmammadov\SupportChat\Jobs;

use Gmammadov\SupportChat\Enums\MessageSender;
use Gmammadov\SupportChat\Enums\TelegramDeliveryStatus;
use Gmammadov\SupportChat\Models\Message;
use Gmammadov\SupportChat\Telegram\TelegramClient;
use Gmammadov\SupportChat\Telegram\TelegramException;
use Gmammadov\SupportChat\Telegram\TelegramMessageFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Forwards one message to the Telegram support chat: a customer message, or a reply/close done in an admin panel.
 * Never throws: failures are recorded on the message and retried by the support-chat:retry-telegram command.
 */
class SendMessageToTelegram implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    // Retries are handled by the scheduled command, not by the queue.
    public int $tries = 1;

    public function __construct(public int $messageId)
    {
    }

    public function handle(TelegramClient $telegram, TelegramMessageFormatter $formatter): void
    {
        if (! $telegram->isConfigured()) {
            return;
        }

        // Atomically claim the message so concurrent workers/retries never send it twice.
        // Only messages created with a delivery status are sent; answers that came from Telegram have none.
        $claimed = Message::query()
            ->whereKey($this->messageId)
            ->whereIn('telegram_status', [TelegramDeliveryStatus::Pending->value, TelegramDeliveryStatus::Failed->value])
            ->where('telegram_attempts', '<', self::MAX_ATTEMPTS)
            ->update([
                'telegram_status' => TelegramDeliveryStatus::Sending->value,
                'telegram_attempts' => DB::raw('telegram_attempts + 1'),
                'updated_at' => now(),
            ]);

        if (! $claimed) {
            return;
        }

        /** @var Message|null $message */
        $message = Message::query()->with('conversation')->find($this->messageId);

        if (! $message) {
            return;
        }

        $conversation = $message->conversation;
        $chatId = $telegram->supportChatId();

        // Thread every message under the previous one, so the whole conversation is one reply chain in Telegram.
        $previous = $conversation->messages()
            ->where('id', '<', $message->getKey())
            ->where('telegram_chat_id', $chatId)
            ->whereNotNull('telegram_message_id')
            ->latest('id')
            ->first();

        $text = match ($message->sender_type) {
            MessageSender::Operator => $formatter->adminReply($conversation, $message),
            MessageSender::System => $formatter->adminClosed($conversation, $message),
            MessageSender::Customer => $formatter->customerMessage($conversation, $message, ! $conversation->messages()
                ->where('id', '<', $message->getKey())
                ->where('sender_type', MessageSender::Customer->value)
                ->exists()),
        };

        try {
            $sent = $telegram->sendMessage($chatId, $text, $previous?->telegram_message_id);

            $message->forceFill([
                'telegram_status' => TelegramDeliveryStatus::Sent,
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => (int) ($sent['message_id'] ?? 0) ?: null,
            ])->save();
        } catch (TelegramException $exception) {
            $message->forceFill(['telegram_status' => TelegramDeliveryStatus::Failed])->save();

            Log::warning('[SupportChat] Failed to forward message to Telegram', [
                'conversation' => $conversation->reference,
                'message_id' => $message->getKey(),
                'attempt' => $message->telegram_attempts,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
