<?php

namespace Gismatul\SupportChat\Tests\Feature;

use Gismatul\SupportChat\Models\Conversation;
use Gismatul\SupportChat\Settings\ChatSettings;
use Gismatul\SupportChat\Tests\TestCase;

class WidgetApiTest extends TestCase
{
    public function test_customer_can_start_a_conversation_and_receives_a_token_once(): void
    {
        $this->fakeTelegram();

        $response = $this->postJson('/api/support-chat/conversations', [
            'name' => 'John',
            'message' => 'Hi there',
            'client_message_id' => '6f1c0a3e-8a3b-4c1e-9f55-0a0b0c0d0e0f',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.conversation.status', 'pending')
            ->assertJsonPath('data.messages.0.message', 'Hi there')
            ->assertJsonMissingPath('data.conversation.access_token_hash');

        $conversation = Conversation::query()->firstOrFail();

        // Only the hash is stored.
        $this->assertNotSame($response->json('data.token'), $conversation->access_token_hash);
        $this->assertTrue($conversation->hasAccessToken($response->json('data.token')));
    }

    public function test_messages_require_the_conversation_token(): void
    {
        $this->fakeTelegram();
        $chat = $this->startConversation();

        $url = "/api/support-chat/conversations/{$chat['uuid']}/messages";

        $this->getJson($url)->assertNotFound();
        $this->getJson($url, ['X-Chat-Token' => 'wrong'])->assertNotFound();
        $this->getJson('/api/support-chat/conversations/not-a-uuid/messages', ['X-Chat-Token' => $chat['token']])->assertNotFound();

        $this->getJson($url, ['X-Chat-Token' => $chat['token']])
            ->assertOk()
            ->assertJsonPath('data.messages.0.sender', 'customer')
            // Customer view never exposes Telegram internals.
            ->assertJsonMissingPath('data.messages.0.telegram_status');
    }

    public function test_resending_the_same_client_message_id_does_not_duplicate(): void
    {
        $this->fakeTelegram();
        $chat = $this->startConversation();
        $url = "/api/support-chat/conversations/{$chat['uuid']}/messages";
        $payload = ['message' => 'Again', 'client_message_id' => 'a3b6f0a2-5d2e-4b8e-9c7c-1f2e3d4c5b6a'];

        $this->postJson($url, $payload, ['X-Chat-Token' => $chat['token']])->assertCreated();
        $this->postJson($url, $payload, ['X-Chat-Token' => $chat['token']])->assertOk();

        $this->assertSame(2, Conversation::query()->first()->messages()->count());
    }

    public function test_closed_conversation_rejects_new_messages(): void
    {
        $this->fakeTelegram();
        $chat = $this->startConversation();
        Conversation::query()->update(['status' => 'closed']);

        $this->postJson("/api/support-chat/conversations/{$chat['uuid']}/messages", ['message' => 'Hello?'], ['X-Chat-Token' => $chat['token']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'conversation_closed');
    }

    public function test_honeypot_and_validation(): void
    {
        $this->postJson('/api/support-chat/conversations', ['name' => 'Bot', 'website' => 'http://spam'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('website');

        $this->postJson('/api/support-chat/conversations', ['name' => 'X'])
            ->assertJsonValidationErrors('name');
    }

    public function test_hidden_fields_are_ignored_and_required_fields_enforced(): void
    {
        $this->fakeTelegram();

        app(ChatSettings::class)->save(['email_field' => 'hidden', 'phone_field' => 'required']);

        $this->postJson('/api/support-chat/conversations', ['name' => 'John', 'message' => 'Hi'])
            ->assertJsonValidationErrors('phone');

        $this->postJson('/api/support-chat/conversations', ['name' => 'John', 'email' => 'john@example.com', 'phone' => '+994 50 123 45 67', 'message' => 'Hi'])
            ->assertCreated();

        $this->assertNull(Conversation::query()->first()->customer_email);
    }

    public function test_control_characters_are_stripped(): void
    {
        $this->fakeTelegram();
        $chat = $this->startConversation(['message' => "Hi\u{202E}there\x07"]);

        $this->getJson("/api/support-chat/conversations/{$chat['uuid']}/messages", ['X-Chat-Token' => $chat['token']])
            ->assertJsonPath('data.messages.0.message', 'Hithere');
    }

    public function test_config_endpoint_exposes_no_secrets(): void
    {
        $response = $this->getJson('/api/support-chat/config?locale=az')->assertOk();

        $this->assertSame('az', $response->json('data.locale'));
        $this->assertSame('optional', $response->json('data.fields.email'));
        $this->assertStringNotContainsString('TESTTOKEN', $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        // Unknown locales fall back to the app locale.
        $this->getJson('/api/support-chat/config?locale=../../etc')->assertJsonPath('data.locale', 'en');
    }

    public function test_assets_are_served_and_only_whitelisted_files(): void
    {
        $this->get('/api/support-chat/assets/support-chat.js')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/support-chat/assets/support-chat.css')->assertOk();
        $this->get('/api/support-chat/assets/..%2Fcomposer.json')->assertNotFound();
    }

    public function test_conversation_creation_is_rate_limited(): void
    {
        $this->fakeTelegram();
        config(['support-chat.rate_limits.conversations_per_hour' => 2]);

        $this->startConversation();
        $this->startConversation();

        $this->postJson('/api/support-chat/conversations', ['name' => 'John', 'message' => 'Hi'])->assertStatus(429);
    }

    public function test_blade_component_renders_config_without_secrets(): void
    {
        $html = (string) $this->blade('<x-support-chat::widget />');

        $this->assertStringContainsString('id="support-chat-config"', $html);
        $this->assertStringContainsString('support-chat.js', $html);
        $this->assertStringNotContainsString('TESTTOKEN', $html);
    }
}
