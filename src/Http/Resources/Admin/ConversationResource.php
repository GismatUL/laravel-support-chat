<?php

namespace Gmammadov\SupportChat\Http\Resources\Admin;

use Gmammadov\SupportChat\Models\Conversation;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing. Never includes the access token hash.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getKey(),
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'ip_address' => $this->ip_address,
            'messages_count' => $this->whenCounted('messages'),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
