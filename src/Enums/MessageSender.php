<?php

namespace Gmammadov\SupportChat\Enums;

enum MessageSender: string
{
    case Customer = 'customer';

    // A support agent, answering from Telegram or from an admin panel.
    case Operator = 'operator';

    // Automatic events, e.g. "conversation closed".
    case System = 'system';

    public function label(): string
    {
        return trans('support-chat::support-chat.senders.' . $this->value);
    }
}
