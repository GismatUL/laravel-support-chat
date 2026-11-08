<?php

namespace Gismatul\SupportChat\Telegram;

use Gismatul\SupportChat\Models\Conversation;
use Gismatul\SupportChat\Models\Message;

/**
 * Builds Telegram HTML messages. Every customer- or admin-provided value is escaped,
 * so it can never inject formatting or markup.
 */
class TelegramMessageFormatter
{
    public function customerMessage(Conversation $conversation, Message $message, bool $isFirst): string
    {
        $lines = [];

        if ($isFirst) {
            $lines[] = '🟢 <b>New customer message</b>';
            $lines[] = '';
            $lines[] = 'Customer: ' . $this->e($conversation->customer_name);

            if ($conversation->customer_phone) {
                $lines[] = 'Phone: ' . $this->e($conversation->customer_phone);
            }

            if ($conversation->customer_email) {
                $lines[] = 'Email: ' . $this->e($conversation->customer_email);
            }

            if ($conversation->customer_id) {
                $lines[] = 'Registered customer ID: ' . $this->e((string) $conversation->customer_id);
            }
        } else {
            $lines[] = '💬 <b>' . $this->e($conversation->customer_name) . '</b>';
        }

        $lines[] = 'Conversation: <code>' . $this->e($conversation->reference) . '</code>';
        $lines[] = '';
        $lines[] = 'Message:';
        $lines[] = $this->e($message->message);
        $lines[] = '';
        $lines[] = '<i>↩️ Reply to this message to answer. Reply /close to close the conversation.</i>';

        return implode("\n", $lines);
    }

    public function adminReply(Conversation $conversation, Message $message): string
    {
        return implode("\n", [
            '👤 <b>' . $this->e($this->adminName($message)) . '</b> answered from the admin panel',
            'Conversation: <code>' . $this->e($conversation->reference) . '</code>',
            '',
            $this->e($message->message),
            '',
            '<i>↩️ Reply to this message to continue the conversation.</i>',
        ]);
    }

    public function adminClosed(Conversation $conversation, Message $message): string
    {
        return sprintf(
            '🔒 Conversation <code>%s</code> was closed by <b>%s</b> from the admin panel.',
            $this->e($conversation->reference),
            $this->e($this->adminName($message))
        );
    }

    public function notice(string $text): string
    {
        return 'ℹ️ ' . $this->e($text);
    }

    protected function adminName(Message $message): string
    {
        return (string) ($message->meta['admin_user']['name'] ?? 'An operator');
    }

    protected function e(?string $value): string
    {
        // Telegram's HTML mode only needs &, < and > escaped (plus quotes inside attributes).
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
