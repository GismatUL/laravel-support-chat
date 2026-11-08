<?php

namespace Gismatul\SupportChat\Events;

use Gismatul\SupportChat\Models\Message;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An operator answered, from Telegram ($admin is null) or from an admin panel.
 */
class OperatorReplied
{
    use Dispatchable;

    public function __construct(public Message $message, public ?Authenticatable $admin = null)
    {
    }
}
