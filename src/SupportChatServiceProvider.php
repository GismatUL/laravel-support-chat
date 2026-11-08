<?php

namespace Gmammadov\SupportChat;

use Gmammadov\SupportChat\Console\ExpireConversationsCommand;
use Gmammadov\SupportChat\Console\InstallCommand;
use Gmammadov\SupportChat\Console\RetryTelegramDeliveryCommand;
use Gmammadov\SupportChat\Console\TelegramWebhookCommand;
use Gmammadov\SupportChat\Http\Middleware\ThrottleChatRequests;
use Gmammadov\SupportChat\Models\Conversation;
use Gmammadov\SupportChat\Policies\ConversationPolicy;
use Gmammadov\SupportChat\Settings\ChatSettings;
use Gmammadov\SupportChat\Telegram\TelegramClient;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SupportChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/support-chat.php', 'support-chat');

        $this->app->singleton(TelegramClient::class);

        // Settings are read once per request/job (scoped = reset between Octane requests and queue jobs).
        $this->app->scoped(ChatSettings::class, fn ($app) => new ChatSettings($app['db']->connection()));

        $this->app->singleton(ThrottleChatRequests::LIMITER, fn ($app) => new RateLimiter(
            $app['cache']->store(config('support-chat.rate_limits.store'))
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'support-chat');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'support-chat');

        $this->registerRoutes();
        $this->registerRateLimiters();
        $this->registerGates();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                ExpireConversationsCommand::class,
                RetryTelegramDeliveryCommand::class,
                TelegramWebhookCommand::class,
            ]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                if (! config('support-chat.schedule', true)) {
                    return;
                }

                $schedule->command(RetryTelegramDeliveryCommand::class)->everyMinute()->withoutOverlapping();
                // Applies the "auto-close" and "delete after" settings; a no-op while both are 0.
                $schedule->command(ExpireConversationsCommand::class)->everyFifteenMinutes()->withoutOverlapping();
            });
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $api = config('support-chat.routes.api');

        Route::group([
            'prefix' => $api['prefix'] ?? 'api/support-chat',
            'middleware' => $api['middleware'] ?? [],
            'domain' => $api['domain'] ?? null,
            'as' => 'support-chat.api.',
        ], __DIR__ . '/../routes/api.php');

        $admin = config('support-chat.routes.admin');

        if ($admin['enabled'] ?? true) {
            Route::group([
                'prefix' => $admin['prefix'] ?? 'support-chat/admin',
                'middleware' => $admin['middleware'] ?? ['web', 'auth'],
                'domain' => $admin['domain'] ?? null,
                'as' => 'support-chat.admin.',
            ], __DIR__ . '/../routes/admin.php');
        }
    }

    protected function registerRateLimiters(): void
    {
        $limit = fn (string $key): int => max(1, (int) config('support-chat.rate_limits.' . $key));

        $this->callAfterResolving(ThrottleChatRequests::LIMITER, function (RateLimiter $limiter) use ($limit) {
            $limiter->for('support-chat-config', fn (Request $request) => Limit::perMinute($limit('polls_per_minute'))->by('ip:' . $request->ip()));

            $limiter->for('support-chat-conversations', fn (Request $request) => Limit::perHour($limit('conversations_per_hour'))->by('ip:' . $request->ip()));

            $limiter->for('support-chat-messages', fn (Request $request) => [
                Limit::perMinute($limit('messages_per_minute'))->by('conv:' . $request->route('conversation')),
                // Looser per-IP cap: many customers can share one carrier/NAT IP.
                Limit::perMinute($limit('messages_per_minute') * 4)->by('ip:' . $request->ip()),
            ]);

            $limiter->for('support-chat-polls', fn (Request $request) => [
                Limit::perMinute($limit('polls_per_minute'))->by('conv:' . $request->route('conversation')),
                Limit::perMinute($limit('polls_per_minute') * 10)->by('ip:' . $request->ip()),
            ]);

            $limiter->for('support-chat-webhook', fn (Request $request) => Limit::perMinute($limit('webhook_per_minute'))->by('ip:' . $request->ip()));
        });
    }

    /**
     * Defines the supportChat.* gates and the Conversation policy unless the application already did.
     * Runs after every provider has booted, so definitions in the app's own providers take precedence.
     */
    protected function registerGates(): void
    {
        $this->app->booted(function () {
            foreach (SupportChat::ABILITIES as $ability) {
                if (! Gate::has($ability)) {
                    Gate::define($ability, fn (Authenticatable $user) => SupportChat::authorizes($user, $ability));
                }
            }

            // Admin panels such as Nova only enforce authorization when a policy exists:
            // without one, their create/update endpoints would be open to every panel user.
            if (Gate::getPolicyFor(Conversation::class) === null) {
                Gate::policy(Conversation::class, ConversationPolicy::class);
            }
        });
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/support-chat.php' => config_path('support-chat.php'),
        ], 'support-chat-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'support-chat-migrations');

        $this->publishes([
            __DIR__ . '/../lang' => $this->app->langPath('vendor/support-chat'),
        ], 'support-chat-translations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/support-chat'),
        ], 'support-chat-views');

        // Optional: serve the widget files from your own public/ or a CDN instead of the asset route.
        $this->publishes([
            __DIR__ . '/../resources/dist' => public_path('vendor/support-chat'),
        ], 'support-chat-assets');
    }
}
