<?php

namespace Gmammadov\SupportChat\Models;

use Gmammadov\SupportChat\Enums\MessageSender;
use Gmammadov\SupportChat\Enums\TelegramDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property MessageSender $sender_type
 * @property int|null $sender_id
 * @property string $message
 * @property string|null $client_message_id
 * @property string|null $telegram_chat_id
 * @property int|null $telegram_message_id
 * @property TelegramDeliveryStatus|null $telegram_status
 * @property int $telegram_attempts
 * @property array|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Conversation $conversation
 */
class Message extends Model
{
    protected $table = 'support_chat_messages';

    protected $fillable = [
        'sender_type',
        'sender_id',
        'message',
        'client_message_id',
        'telegram_chat_id',
        'telegram_message_id',
        'telegram_status',
        'meta',
    ];

    protected $casts = [
        'sender_type' => MessageSender::class,
        'telegram_status' => TelegramDeliveryStatus::class,
        'sender_id' => 'int',
        'telegram_message_id' => 'int',
        'telegram_attempts' => 'int',
        'meta' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Display name of the operator who wrote this message (admin user or Telegram member), if known.
     */
    public function operatorName(): string|null
    {
        $admin = $this->meta['admin_user']['name'] ?? null;

        if ($admin) {
            return (string) $admin;
        }

        $telegram = $this->meta['telegram_operator'] ?? null;

        if (! $telegram) {
            return null;
        }

        return (string) ($telegram['first_name'] ?? (isset($telegram['username']) ? '@' . $telegram['username'] : 'Telegram'));
    }
}
