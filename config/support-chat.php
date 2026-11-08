<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / disable
    |--------------------------------------------------------------------------
    | When disabled, the Blade widget renders nothing. The API routes stay registered
    | so open conversations can still be read.
    */
    'enabled' => env('SUPPORT_CHAT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    | Secrets live in the environment only, never in the admin-editable settings.
    | Create a bot with @BotFather, add it to your support group and put the group id here.
    */
    'telegram' => [
        'bot_token' => env('SUPPORT_CHAT_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('SUPPORT_CHAT_TELEGRAM_CHAT_ID'),
        // 16-256 chars of A-Z a-z 0-9 _ -. Telegram echoes it in a header on every webhook call.
        'webhook_secret' => env('SUPPORT_CHAT_TELEGRAM_WEBHOOK_SECRET'),
        // Comma-separated Telegram user ids allowed to answer. Empty = any member of the support chat.
        'operator_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('SUPPORT_CHAT_TELEGRAM_OPERATOR_IDS', ''))))),
        'api_url' => env('SUPPORT_CHAT_TELEGRAM_API_URL', 'https://api.telegram.org'),
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | "api" routes are used by the website widget and the Telegram webhook. They are stateless
    | (no session, no cookies): a conversation is authorised by its secret token header.
    | The default prefix lives under "api/" so Laravel's default CORS paths (api/*) cover it,
    | which matters when the widget runs on a separate frontend (React, Vue, Next...).
    |
    | "admin" routes are a JSON API for admin panels (the Nova package uses its own copy of them
    | behind Nova's authentication). Set admin.enabled to false if you only use Nova.
    */
    'routes' => [
        'api' => [
            'prefix' => env('SUPPORT_CHAT_API_PREFIX', 'api/support-chat'),
            'middleware' => [],
            'domain' => null,
        ],

        'admin' => [
            'enabled' => env('SUPPORT_CHAT_ADMIN_ROUTES', true),
            'prefix' => 'support-chat/admin',
            // Must authenticate the user and protect against CSRF ("web" does the latter).
            'middleware' => ['web', 'auth'],
            'domain' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    | Optional guard of your logged-in customers (e.g. "web" or "sanctum"). When the widget
    | is used by a logged-in customer, the conversation is linked to them and the form is prefilled.
    | For full control use SupportChat::resolveCustomerUsing() in a service provider.
    */
    'customer' => [
        'guard' => env('SUPPORT_CHAT_CUSTOMER_GUARD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for admin-editable settings
    |--------------------------------------------------------------------------
    | Values saved from an admin panel (support_chat_settings table) take precedence.
    | email_field / phone_field: hidden, optional or required.
    | auto_close_hours / delete_after_days: 0 means never.
    */
    'settings' => [
        'email_field' => 'optional',
        'phone_field' => 'optional',
        'auto_close_hours' => 0,
        'delete_after_days' => 0,
    ],

    // Prefix of the human-readable conversation reference shown to customers and operators (e.g. SC-7KQ2ZD).
    'reference_prefix' => env('SUPPORT_CHAT_REFERENCE_PREFIX', 'SC-'),

    'message_max_length' => 2000,

    // Seconds between widget polls for new messages while the chat is open.
    'poll_interval' => 3,

    // Store the customer's IP address with the conversation (shown to admins). Disable for stricter privacy.
    'store_ip_address' => true,

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    | Dedicated limiters (not the shared "api" one): many customers can share one mobile-carrier IP.
    | "store" is a cache store name from config/cache.php; null = your default store.
    | Make sure TrustProxies is configured, or every request will appear to come from your proxy.
    */
    'rate_limits' => [
        'store' => env('SUPPORT_CHAT_RATE_LIMIT_STORE'),
        'conversations_per_hour' => 5,
        'messages_per_minute' => 15,
        'polls_per_minute' => 60,
        'webhook_per_minute' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram delivery
    |--------------------------------------------------------------------------
    | after_response: send right after the HTTP response, in the same PHP process (no worker needed).
    | Set it to false to push the job onto a real queue instead.
    | Failed deliveries are always retried by the scheduled support-chat:retry-telegram command.
    */
    'queue' => [
        'after_response' => env('SUPPORT_CHAT_SEND_AFTER_RESPONSE', true),
        'connection' => env('SUPPORT_CHAT_QUEUE_CONNECTION'),
        'queue' => env('SUPPORT_CHAT_QUEUE'),
    ],

    // Register the retry and expiry commands with the scheduler (requires `php artisan schedule:run` in cron).
    'schedule' => true,
];
