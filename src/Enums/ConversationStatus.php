<?php

namespace Gismatul\SupportChat\Enums;

enum ConversationStatus: string
{
    // Waiting for an operator (the last message came from the customer).
    case Pending = 'pending';

    // An operator has answered.
    case Open = 'open';

    case Closed = 'closed';

    public function label(): string
    {
        return trans('support-chat::support-chat.statuses.' . $this->value);
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function labels(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
