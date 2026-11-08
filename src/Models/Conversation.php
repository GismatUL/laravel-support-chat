<?php

namespace Gismatul\SupportChat\Models;

use Gismatul\SupportChat\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $reference
 * @property string $access_token_hash
 * @property int|null $customer_id
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property ConversationStatus $status
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Conversation extends Model
{
    protected $table = 'support_chat_conversations';

    // Everything is written with forceFill() by ChatService; nothing is mass-assignable from input.
    protected $guarded = ['*'];

    protected $hidden = ['access_token_hash'];

    protected $casts = [
        'status' => ConversationStatus::class,
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function isClosed(): bool
    {
        return $this->status === ConversationStatus::Closed;
    }

    public static function hashAccessToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function hasAccessToken(string|null $token): bool
    {
        return $token !== null && $token !== '' && hash_equals($this->access_token_hash, static::hashAccessToken($token));
    }

    public static function generateReference(): string
    {
        $prefix = substr((string) config('support-chat.reference_prefix', 'SC-'), 0, 8);

        do {
            $reference = $prefix . strtoupper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
