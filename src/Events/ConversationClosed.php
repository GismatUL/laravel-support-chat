<?php

namespace Gmammadov\SupportChat\Events;

use Gmammadov\SupportChat\Models\Conversation;
use Gmammadov\SupportChat\Models\Message;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * $reason is "closed" (by an operator) or "expired" (automatically, after inactivity).
 */
class ConversationClosed
{
    use Dispatchable;

    public function __construct(
        public Conversation $conversation,
        public Message $message,
        public string $reason,
        public ?Authenticatable $admin = null
    ) {
    }
}
