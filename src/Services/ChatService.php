<?php

namespace Gmammadov\SupportChat\Services;

use Gmammadov\SupportChat\Enums\ConversationStatus;
use Gmammadov\SupportChat\Enums\MessageSender;
use Gmammadov\SupportChat\Enums\TelegramDeliveryStatus;
use Gmammadov\SupportChat\Events\ConversationClosed;
use Gmammadov\SupportChat\Events\ConversationStarted;
use Gmammadov\SupportChat\Events\CustomerMessageReceived;
use Gmammadov\SupportChat\Events\OperatorReplied;
use Gmammadov\SupportChat\Jobs\SendMessageToTelegram;
use Gmammadov\SupportChat\Models\Conversation;
use Gmammadov\SupportChat\Models\Message;
use Gmammadov\SupportChat\SupportChat;
use Gmammadov\SupportChat\Telegram\TelegramClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transport-agnostic chat operations. The widget API, the admin API and the Telegram webhook
 * all go through this class.
 */
class ChatService
{
    public function __construct(protected TelegramClient $telegram)
    {
    }

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null}  $customer
     * @return array{0: Conversation, 1: string} The conversation and its plain access token (only ever returned once).
     */
    public function startConversation(array $customer, ?string $ipAddress = null, int|string|null $customerId = null): array
    {
        $token = Str::random(64);

        $conversation = new Conversation();
        $conversation->forceFill([
            'uuid' => (string) Str::uuid(),
            'reference' => Conversation::generateReference(),
            'access_token_hash' => Conversation::hashAccessToken($token),
            'customer_id' => $customerId,
            'customer_name' => $customer['name'],
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $customer['phone'] ?? null,
            'status' => ConversationStatus::Pending,
            'ip_address' => config('support-chat.store_ip_address', true) ? $ipAddress : null,
        ])->save();

        event(new ConversationStarted($conversation));

        return [$conversation, $token];
    }

    /**
     * Stores a customer message and queues delivery to Telegram.
     * Re-sending the same client_message_id returns the existing message instead of creating a duplicate.
     *
     * @return array{0: Message, 1: bool} The message and whether it was newly created.
     */
    public function addCustomerMessage(Conversation $conversation, string $text, ?string $clientMessageId = null): array
    {
        if ($clientMessageId && $existing = $this->findByClientMessageId($conversation, $clientMessageId)) {
            return [$existing, false];
        }

        $telegramEnabled = $this->telegram->isConfigured();

        try {
            $message = DB::transaction(function () use ($conversation, $text, $clientMessageId, $telegramEnabled) {
                $message = $conversation->messages()->create([
                    'sender_type' => MessageSender::Customer,
                    'message' => $text,
                    'client_message_id' => $clientMessageId,
                    'telegram_status' => $telegramEnabled ? TelegramDeliveryStatus::Pending : null,
                ]);

                $conversation->forceFill([
                    'status' => ConversationStatus::Pending,
                    'last_message_at' => $message->created_at,
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same client_message_id won the race.
            return [$this->findByClientMessageId($conversation, (string) $clientMessageId), false];
        }

        if ($telegramEnabled) {
            $this->deliverToTelegram($message);
        }

        event(new CustomerMessageReceived($message));

        return [$message, true];
    }

    /**
     * Stores an answer written by a Telegram operator.
     *
     * @return Message|null Null when this Telegram message was already processed (duplicate webhook).
     */
    public function addTelegramOperatorMessage(Conversation $conversation, string $text, string $telegramChatId, int $telegramMessageId, array $operator = []): ?Message
    {
        try {
            $message = DB::transaction(function () use ($conversation, $text, $telegramChatId, $telegramMessageId, $operator) {
                $message = $conversation->messages()->create([
                    'sender_type' => MessageSender::Operator,
                    'message' => $text,
                    'telegram_chat_id' => $telegramChatId,
                    'telegram_message_id' => $telegramMessageId,
                    'meta' => ['telegram_operator' => $operator],
                ]);

                $conversation->forceFill([
                    'status' => ConversationStatus::Open,
                    'last_message_at' => $message->created_at,
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        event(new OperatorReplied($message));

        return $message;
    }

    /**
     * Stores an answer written in an admin panel. The customer sees it in the widget, and a copy is
     * posted to the Telegram support chat so operators there can follow and keep answering.
     */
    public function addAdminMessage(Conversation $conversation, string $text, Authenticatable $admin): Message
    {
        $telegramEnabled = $this->telegram->isConfigured();

        $message = DB::transaction(function () use ($conversation, $text, $admin, $telegramEnabled) {
            $message = $conversation->messages()->create([
                'sender_type' => MessageSender::Operator,
                'sender_id' => $this->adminId($admin),
                'message' => $text,
                'telegram_status' => $telegramEnabled ? TelegramDeliveryStatus::Pending : null,
                'meta' => ['admin_user' => $this->adminMeta($admin)],
            ]);

            $conversation->forceFill([
                'status' => ConversationStatus::Open,
                'last_message_at' => $message->created_at,
            ])->save();

            return $message;
        });

        if ($telegramEnabled) {
            $this->deliverToTelegram($message);
        }

        event(new OperatorReplied($message, $admin));

        return $message;
    }

    /**
     * @param  string  $reason  "closed" (by an operator) or "expired" (automatically, after inactivity).
     * @param  Authenticatable|null  $admin  Set when closed from an admin panel; Telegram is then notified.
     */
    public function closeConversation(
        Conversation $conversation,
        ?string $telegramChatId = null,
        ?int $telegramMessageId = null,
        string $reason = 'closed',
        ?Authenticatable $admin = null
    ): ?Message {
        $notifyTelegram = $admin && $this->telegram->isConfigured();

        try {
            $message = DB::transaction(function () use ($conversation, $telegramChatId, $telegramMessageId, $reason, $admin, $notifyTelegram) {
                // Re-check under a row lock so two concurrent closes do not both add a "closed" message.
                $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

                if (! $locked || $locked->isClosed()) {
                    return null;
                }

                $message = $conversation->messages()->create([
                    'sender_type' => MessageSender::System,
                    'sender_id' => $admin ? $this->adminId($admin) : null,
                    'message' => $reason === 'expired' ? 'Conversation closed automatically after inactivity.' : 'Conversation closed by support.',
                    'telegram_chat_id' => $telegramChatId,
                    'telegram_message_id' => $telegramMessageId,
                    'telegram_status' => $notifyTelegram ? TelegramDeliveryStatus::Pending : null,
                    'meta' => array_filter([
                        'event' => $reason,
                        'admin_user' => $admin ? $this->adminMeta($admin) : null,
                    ]),
                ]);

                $conversation->forceFill([
                    'status' => ConversationStatus::Closed,
                    'closed_at' => Carbon::now(),
                    'last_message_at' => $message->created_at,
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if (! $message) {
            return null;
        }

        if ($notifyTelegram) {
            $this->deliverToTelegram($message);
        }

        event(new ConversationClosed($conversation, $message, $reason, $admin));

        return $message;
    }

    /**
     * Messages in chronological order, optionally only those after the given message id (polling cursor).
     */
    public function messagesAfter(Conversation $conversation, int $afterId = 0, int $limit = 100): Collection
    {
        return $conversation->messages()
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function deliverToTelegram(Message $message): void
    {
        $job = new SendMessageToTelegram($message->getKey());

        if (config('support-chat.queue.after_response', true)) {
            // Runs after the HTTP response is sent, so a slow Telegram never delays the customer.
            dispatch($job)->afterResponse();

            return;
        }

        // Our own transaction has committed at this point. If the caller wraps us in a bigger transaction
        // and a worker runs before it commits, the job finds nothing to claim and the scheduled retry
        // command sends the message later.
        dispatch($job)
            ->onConnection(config('support-chat.queue.connection'))
            ->onQueue(config('support-chat.queue.queue'));
    }

    protected function findByClientMessageId(Conversation $conversation, string $clientMessageId): ?Message
    {
        return $conversation->messages()->where('client_message_id', $clientMessageId)->first();
    }

    protected function adminId(Authenticatable $admin): ?int
    {
        $id = $admin->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    protected function adminMeta(Authenticatable $admin): array
    {
        return ['id' => $admin->getAuthIdentifier(), 'name' => SupportChat::operatorName($admin)];
    }
}
