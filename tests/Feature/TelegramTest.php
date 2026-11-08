<?php

namespace Gismatul\SupportChat\Tests\Feature;

use Gismatul\SupportChat\Models\Conversation;
use Gismatul\SupportChat\Models\Message;
use Gismatul\SupportChat\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class TelegramTest extends TestCase
{
    public function test_customer_messages_are_forwarded_with_html_escaped(): void
    {
        $this->fakeTelegram(777);

        $this->startConversation(['name' => '<b>Evil</b>', 'message' => '<a href="x">click</a> & more']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/bot123:TESTTOKEN/sendMessage')
                && $request['chat_id'] === self::CHAT_ID
                && str_contains($request['text'], '&lt;b&gt;Evil&lt;/b&gt;')
                && str_contains($request['text'], '&lt;a href=&quot;x&quot;&gt;click&lt;/a&gt; &amp; more');
        });

        $message = Message::query()->firstOrFail();
        $this->assertSame('sent', $message->telegram_status->value);
        $this->assertSame(777, $message->telegram_message_id);
    }

    public function test_failed_delivery_is_recorded_without_leaking_the_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $this->startConversation();

        $this->assertSame('failed', Message::query()->first()->telegram_status->value);
    }

    public function test_webhook_rejects_requests_without_the_secret(): void
    {
        $this->postJson('/api/support-chat/telegram/webhook', ['update_id' => 1])->assertForbidden();
        $this->postJson('/api/support-chat/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->assertForbidden();

        config(['support-chat.telegram.webhook_secret' => '']);
        $this->postJson('/api/support-chat/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertForbidden();
    }

    public function test_operator_reply_in_telegram_reaches_the_customer(): void
    {
        $this->fakeTelegram(900);
        $chat = $this->startConversation();

        $this->webhook($this->reply(900, 'We are on it!'))->assertOk();
        // Duplicate delivery of the same update is ignored.
        $this->webhook($this->reply(900, 'We are on it!'))->assertOk();

        $this->getJson("/api/support-chat/conversations/{$chat['uuid']}/messages", ['X-Chat-Token' => $chat['token']])
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.sender', 'operator')
            ->assertJsonPath('data.messages.1.message', 'We are on it!')
            ->assertJsonPath('data.conversation.status', 'open');
    }

    public function test_updates_from_other_chats_and_disallowed_operators_are_ignored(): void
    {
        $this->fakeTelegram(900);
        $this->startConversation();

        $this->webhook($this->reply(900, 'From elsewhere', chatId: '-100999'))->assertOk();

        config(['support-chat.telegram.operator_ids' => ['42']]);
        $this->webhook($this->reply(900, 'Not an operator', fromId: 7))->assertOk();

        $this->assertSame(1, Message::query()->count());

        $this->webhook($this->reply(900, 'Allowed', fromId: 42))->assertOk();
        $this->assertSame(2, Message::query()->count());
    }

    public function test_close_command_closes_the_conversation(): void
    {
        $this->fakeTelegram(900);
        $this->startConversation();

        $this->webhook($this->reply(900, '/close'))->assertOk();

        $this->assertTrue(Conversation::query()->first()->isClosed());
    }

    protected function webhook(array $update)
    {
        return $this->postJson('/api/support-chat/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    protected function reply(int $toMessageId, string $text, string $chatId = self::CHAT_ID, int $fromId = 42): array
    {
        static $id = 1000;
        $id++;

        return [
            'update_id' => $id,
            'message' => [
                'message_id' => 5000 + crc32($text . $chatId . $fromId) % 1000,
                'chat' => ['id' => (int) $chatId],
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Op'],
                'text' => $text,
                'reply_to_message' => ['message_id' => $toMessageId, 'from' => ['is_bot' => true]],
            ],
        ];
    }
}
