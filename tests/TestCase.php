<?php

namespace Gmammadov\SupportChat\Tests;

use Gmammadov\SupportChat\SupportChat;
use Gmammadov\SupportChat\SupportChatServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const SECRET = 'test-webhook-secret-1234567890';

    public const CHAT_ID = '-100123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        SupportChat::flushState();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [SupportChatServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'production');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('support-chat.telegram.bot_token', '123:TESTTOKEN');
        $app['config']->set('support-chat.telegram.chat_id', self::CHAT_ID);
        $app['config']->set('support-chat.telegram.webhook_secret', self::SECRET);
        // Deliver synchronously in tests.
        $app['config']->set('support-chat.queue.after_response', false);
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * Fakes the Bot API. Like Telegram, every sent message gets a new id: $firstMessageId, then +1...
     */
    protected function fakeTelegram(int $firstMessageId = 555): void
    {
        $next = $firstMessageId;

        Http::fake([
            'api.telegram.org/*' => function () use (&$next) {
                return Http::response(['ok' => true, 'result' => ['message_id' => $next++]]);
            },
        ]);
    }

    protected function admin(int $id = 1, string $name = 'Alice Admin'): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => $name, 'email' => "admin{$id}@example.com", 'remember_token' => null]);
    }

    /**
     * @return array{uuid: string, token: string, reference: string}
     */
    protected function startConversation(array $overrides = []): array
    {
        $response = $this->postJson('/api/support-chat/conversations', array_merge([
            'name' => 'John Customer',
            'email' => 'john@example.com',
            'message' => 'Hello, I need help',
        ], $overrides))->assertCreated();

        return [
            'uuid' => $response->json('data.conversation.uuid'),
            'token' => $response->json('data.token'),
            'reference' => $response->json('data.conversation.reference'),
        ];
    }
}
