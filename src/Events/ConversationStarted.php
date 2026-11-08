<?php

namespace Gismatul\SupportChat\Events;

use Gismatul\SupportChat\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

class ConversationStarted
{
    use Dispatchable;

    public function __construct(public Conversation $conversation)
    {
    }
}
