<?php

namespace Gismatul\SupportChat\Telegram;

use Gismatul\SupportChat\Models\Message;
use Gismatul\SupportChat\Services\ChatService;
use Illuminate\Support\Facades\Log;

/**
 * Turns Telegram updates from the support chat into operator messages.
 * An operator answers a customer by replying (Telegram "Reply") to one of the bot's messages;
 * the replied-to Telegram message id is mapped back to the conversation.
 */
class TelegramWebhookHandler
{
    public function __construct(
        protected ChatService $chat,
        protected TelegramClient $telegram,
        protected TelegramMessageFormatter $formatter
    ) {
    }

    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        // Only new messages are handled; edits, reactions, etc. are ignored.
        if (! is_array($message) || ! isset($message['message_id'], $message['chat']['id'])) {
            return;
        }

        $chatId = (string) $message['chat']['id'];
        $messageId = (int) $message['message_id'];

        // Anyone can add the bot to another chat: only the configured support chat is trusted.
        if ($chatId !== $this->telegram->supportChatId()) {
            Log::warning('[SupportChat] Ignored Telegram update from a non-support chat', [
                'update_id' => $update['update_id'] ?? null,
                'chat_id' => $chatId,
            ]);

            return;
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];

        if (($from['is_bot'] ?? false) || ! $this->isAllowedOperator($from)) {
            return;
        }

        $repliedTo = $message['reply_to_message']['message_id'] ?? null;

        // Chatter between operators (not a reply) is ignored, but a command sent without replying
        // gets a hint, since the bot cannot tell which conversation it is for.
        if (! $repliedTo) {
            if (str_starts_with(trim((string) ($message['text'] ?? '')), '/')) {
                $this->notify($chatId, 'Use Telegram\'s Reply on the customer\'s message, then type your answer (or /close to close that conversation).', $messageId);
            }

            return;
        }

        $source = Message::query()
            ->with('conversation')
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', (int) $repliedTo)
            ->first();

        if (! $source) {
            // Only hint when the operator replied to one of the bot's own messages.
            if ($message['reply_to_message']['from']['is_bot'] ?? false) {
                $this->notify($chatId, 'This message is not linked to a website conversation.', $messageId);
            }

            return;
        }

        // Duplicate webhook delivery: this Telegram message was already stored.
        if (Message::query()->where('telegram_chat_id', $chatId)->where('telegram_message_id', $messageId)->exists()) {
            return;
        }

        $conversation = $source->conversation;
        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '') {
            $this->notify($chatId, 'Only text replies are supported. The customer did not receive this message.', $messageId);

            return;
        }

        if (preg_match('/^\/close(@\w+)?$/i', $text)) {
            if (! $conversation->isClosed() && $this->chat->closeConversation($conversation, $chatId, $messageId)) {
                $this->notify($chatId, sprintf('Conversation %s closed.', $conversation->reference), $messageId);
            }

            return;
        }

        if ($conversation->isClosed()) {
            $this->notify($chatId, sprintf('Conversation %s is closed. The customer did not receive this message.', $conversation->reference), $messageId);

            return;
        }

        $maxLength = (int) config('support-chat.message_max_length', 2000);

        if (mb_strlen($text) > $maxLength) {
            $this->notify($chatId, sprintf('The answer is longer than %d characters and was not sent.', $maxLength), $messageId);

            return;
        }

        $this->chat->addTelegramOperatorMessage($conversation, $text, $chatId, $messageId, array_filter([
            'id' => $from['id'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'username' => $from['username'] ?? null,
        ]));
    }

    protected function isAllowedOperator(array $from): bool
    {
        $allowed = array_map('strval', (array) config('support-chat.telegram.operator_ids', []));

        return ! $allowed || in_array((string) ($from['id'] ?? ''), $allowed, true);
    }

    protected function notify(string $chatId, string $text, int $replyTo): void
    {
        try {
            $this->telegram->sendMessage($chatId, $this->formatter->notice($text), $replyTo);
        } catch (TelegramException $exception) {
            Log::warning('[SupportChat] Failed to send Telegram notice', ['error' => $exception->getMessage()]);
        }
    }
}
