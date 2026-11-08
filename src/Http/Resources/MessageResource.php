<?php

namespace Gismatul\SupportChat\Http\Resources;

use Gismatul\SupportChat\Models\Message;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing: no operator identity, Telegram ids or delivery internals.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getKey(),
            'sender' => $this->sender_type->value,
            'message' => $this->message,
            'event' => $this->meta['event'] ?? null,
            'client_message_id' => $this->client_message_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
