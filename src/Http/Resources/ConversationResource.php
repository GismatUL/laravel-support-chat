<?php

namespace Gismatul\SupportChat\Http\Resources;

use Gismatul\SupportChat\Models\Conversation;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing: only what the widget needs.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'customer_name' => $this->customer_name,
        ];
    }
}
