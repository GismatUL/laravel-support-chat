<?php

namespace Gismatul\SupportChat\Enums;

enum TelegramDeliveryStatus: string
{
    case Pending = 'pending';

    case Sending = 'sending';

    case Sent = 'sent';

    case Failed = 'failed';
}
