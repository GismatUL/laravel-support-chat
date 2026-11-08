<?php

namespace Gismatul\SupportChat\Tests\Feature;

use Gismatul\SupportChat\Models\Conversation;
use Gismatul\SupportChat\SupportChat;
use Gismatul\SupportChat\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

class AdminApiTest extends TestCase
{
    public function test_guests_cannot_access_the_admin_api(): void
    {
        $this->getJson('/support-chat/admin/conversations')->assertUnauthorized();
        $this->getJson('/support-chat/admin/settings')->assertUnauthorized();
    }

    public function test_authenticated_users_are_denied_by_default_outside_local(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/support-chat/admin/conversations')
            ->assertForbidden();
    }

    public function test_authorized_admin_can_list_and_read_conversations(): void
    {
        $this->fakeTelegram();
        SupportChat::authorizeUsing(fn ($user, $ability) => true);
        $chat = $this->startConversation();

        $this->actingAs($this->admin())
            ->getJson('/support-chat/admin/conversations?status=pending&q=John')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $chat['reference'])
            ->assertJsonPath('data.0.messages_count', 1)
            ->assertJsonMissingPath('data.0.access_token_hash');

        $id = Conversation::query()->value('id');

        $this->getJson("/support-chat/admin/conversations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.telegram_status', 'sent')
            ->assertJsonPath('data.can.reply', true);
    }

    public function test_admin_reply_is_visible_to_the_customer_and_mirrored_to_telegram(): void
    {
        $this->fakeTelegram();
        SupportChat::authorizeUsing(fn ($user, $ability) => true);
        $chat = $this->startConversation();
        $id = Conversation::query()->value('id');

        $this->actingAs($this->admin(7, 'Alice Admin'))
            ->postJson("/support-chat/admin/conversations/{$id}/messages", ['message' => 'Hello from the panel'])
            ->assertCreated()
            ->assertJsonPath('data.operator_name', 'Alice Admin')
            ->assertJsonPath('data.source', 'admin');

        // The customer sees the answer as "operator", without the admin's identity.
        $customerView = $this->getJson("/api/support-chat/conversations/{$chat['uuid']}/messages", ['X-Chat-Token' => $chat['token']])
            ->assertJsonPath('data.messages.1.sender', 'operator')
            ->assertJsonPath('data.messages.1.message', 'Hello from the panel');
        $this->assertStringNotContainsString('Alice', $customerView->getContent());

        Http::assertSent(fn (Request $request) => str_contains((string) $request['text'], 'Alice Admin</b> answered from the admin panel'));
    }

    public function test_reply_and_manage_abilities_are_enforced_separately(): void
    {
        $this->fakeTelegram();
        SupportChat::authorizeUsing(fn ($user, string $ability) => $ability === SupportChat::ABILITY_VIEW);
        $this->startConversation();
        $id = Conversation::query()->value('id');

        $this->actingAs($this->admin());

        $this->getJson("/support-chat/admin/conversations/{$id}")->assertOk()->assertJsonPath('data.can.reply', false);
        $this->postJson("/support-chat/admin/conversations/{$id}/messages", ['message' => 'Hi'])->assertForbidden();
        $this->postJson("/support-chat/admin/conversations/{$id}/close")->assertForbidden();
        $this->deleteJson("/support-chat/admin/conversations/{$id}")->assertForbidden();
        $this->putJson('/support-chat/admin/settings', [])->assertForbidden();
    }

    public function test_gates_defined_by_the_application_take_precedence(): void
    {
        Gate::define(SupportChat::ABILITY_VIEW, fn ($user) => $user->id === 99);

        $this->actingAs($this->admin(1))->getJson('/support-chat/admin/conversations')->assertForbidden();
        $this->actingAs($this->admin(99))->getJson('/support-chat/admin/conversations')->assertOk();
    }

    public function test_admin_can_close_and_customer_sees_it(): void
    {
        $this->fakeTelegram();
        SupportChat::authorizeUsing(fn ($user, $ability) => true);
        $chat = $this->startConversation();
        $id = Conversation::query()->value('id');

        $this->actingAs($this->admin())
            ->postJson("/support-chat/admin/conversations/{$id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        // Closing twice adds no second "closed" message.
        $this->postJson("/support-chat/admin/conversations/{$id}/close")->assertOk();

        $this->getJson("/api/support-chat/conversations/{$chat['uuid']}/messages", ['X-Chat-Token' => $chat['token']])
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.event', 'closed')
            ->assertJsonPath('data.conversation.status', 'closed');

        $this->postJson("/support-chat/admin/conversations/{$id}/messages", ['message' => 'Too late'])->assertUnprocessable();
    }

    public function test_settings_are_validated_and_saved(): void
    {
        SupportChat::authorizeUsing(fn ($user, $ability) => true);
        $this->actingAs($this->admin());

        $this->putJson('/support-chat/admin/settings', ['email_field' => 'sometimes', 'phone_field' => 'optional', 'auto_close_hours' => -1, 'delete_after_days' => 0])
            ->assertJsonValidationErrors(['email_field', 'auto_close_hours']);

        $this->putJson('/support-chat/admin/settings', ['email_field' => 'required', 'phone_field' => 'hidden', 'auto_close_hours' => 24, 'delete_after_days' => 30])
            ->assertOk()
            ->assertJsonPath('data.settings.email_field', 'required');

        $this->getJson('/support-chat/admin/settings')
            ->assertJsonPath('data.settings.auto_close_hours', 24)
            ->assertJsonPath('data.telegram_configured', true)
            ->assertJsonMissingPath('data.bot_token');
    }

    public function test_conversation_policy_is_registered_and_never_allows_edits(): void
    {
        $this->fakeTelegram();
        $this->startConversation();
        $conversation = Conversation::query()->first();
        SupportChat::authorizeUsing(fn ($user, $ability) => true);

        $gate = Gate::forUser($this->admin());

        $this->assertTrue($gate->allows('viewAny', Conversation::class));
        $this->assertTrue($gate->allows('delete', $conversation));
        $this->assertFalse($gate->allows('create', Conversation::class));
        $this->assertFalse($gate->allows('update', $conversation));

        SupportChat::authorizeUsing(fn ($user, $ability) => $ability === SupportChat::ABILITY_VIEW);
        $this->assertFalse(Gate::forUser($this->admin())->allows('delete', $conversation));
    }

    public function test_expire_command_closes_inactive_conversations(): void
    {
        $this->fakeTelegram();
        $this->startConversation();
        Conversation::query()->update(['last_message_at' => now()->subHours(30)]);

        app(\Gismatul\SupportChat\Settings\ChatSettings::class)->save(['auto_close_hours' => 24]);

        $this->artisan('support-chat:expire')->assertSuccessful();

        $this->assertTrue(Conversation::query()->first()->isClosed());
    }
}
