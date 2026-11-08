<?php

namespace Gismatul\SupportChat\Events;

use Gismatul\SupportChat\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer wrote a new message (duplicates re-sent by the widget do not fire it again).
 */
class CustomerMessageReceived
{
    use Dispatchable;

    public function __construct(public Message $message)
    {
    }
}
