<?php

namespace Gmammadov\SupportChat\Events;

use Gmammadov\SupportChat\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

class ConversationStarted
{
    use Dispatchable;

    public function __construct(public Conversation $conversation)
    {
    }
}
