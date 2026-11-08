<?php

namespace Gmammadov\SupportChat\Http\Resources\Admin;

use Gmammadov\SupportChat\Enums\TelegramDeliveryStatus;
use Gmammadov\SupportChat\Models\Message;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing: includes who answered and whether the message reached Telegram.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        $viaAdmin = isset($this->meta['admin_user']);

        return [
            'id' => $this->getKey(),
            'sender' => $this->sender_type->value,
            'sender_label' => $this->sender_type->label(),
            'operator_name' => $this->operatorName(),
            // "admin" = answered from an admin panel, "telegram" = from the Telegram support chat.
            'source' => $viaAdmin ? 'admin' : ($this->telegram_message_id && ! $this->telegram_status ? 'telegram' : null),
            'message' => $this->message,
            'event' => $this->meta['event'] ?? null,
            'telegram_status' => $this->telegram_status?->value,
            'telegram_attempts' => $this->telegram_attempts,
            'telegram_failed' => $this->telegram_status === TelegramDeliveryStatus::Failed,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
