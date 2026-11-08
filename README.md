# Laravel Support Chat

A live-chat widget for Laravel websites. Customers write in a small chat bubble on your site; your team answers **from a Telegram group** (by replying to the bot's message) or **from an admin panel**. Answers from either side reach the customer and are mirrored to the other side.

- Works on Blade pages **and** on separate frontends (React, Vue, Next, static sites) with one `<script>` tag
- No WebSocket server, no queue worker required (short polling, sends to Telegram after the response)
- Admin JSON API with fine-grained gates, plus a ready-made [Laravel Nova panel](https://github.com/GismatUL/nova-support-chat)
- Built with security in mind: see [Security](#security)
- Translations: English, Azerbaijani, Russian

Requires PHP 8.1+ and Laravel 10, 11, 12 or 13.

## Installation

```bash
composer require gmammadov/laravel-support-chat
php artisan support-chat:install
```

The install command publishes `config/support-chat.php`, runs the migrations and prints the remaining steps.

### 1. Telegram

1. Create a bot with [@BotFather](https://t.me/BotFather). Send `/setprivacy` → *Disable* so the bot can read your operators' replies.
2. Create a group for your support team and add the bot. Get the group id (e.g. by adding [@RawDataBot](https://t.me/RawDataBot) for a moment, it looks like `-100…`).
3. Add to `.env`:

```dotenv
SUPPORT_CHAT_TELEGRAM_BOT_TOKEN=123456:ABC...
SUPPORT_CHAT_TELEGRAM_CHAT_ID=-1001234567890
SUPPORT_CHAT_TELEGRAM_WEBHOOK_SECRET=<random 16-256 chars of A-Z a-z 0-9 _ ->
# Optional: only these Telegram user ids may answer customers
SUPPORT_CHAT_TELEGRAM_OPERATOR_IDS=11111111,22222222
```

4. Register the webhook (your app must be reachable over HTTPS):

```bash
php artisan support-chat:telegram-webhook          # set
php artisan support-chat:telegram-webhook --info   # inspect
```

Operators answer by using Telegram's **Reply** on a customer message. Replying `/close` closes the conversation.

### 2. Scheduler

Make sure `php artisan schedule:run` runs every minute. The package schedules:

- `support-chat:retry-telegram`: re-sends messages that failed to reach Telegram (every minute)
- `support-chat:expire`: auto-closes inactive conversations and deletes old ones, per the admin settings (every 15 minutes)

### 3. The widget

**Blade**: put this before `</body>`:

```blade
<x-support-chat::widget />
{{-- or force a language: <x-support-chat::widget locale="az" /> --}}
```

**Any other frontend** (React, Vue, a static site on another domain):

```html
<script src="https://api.example.com/api/support-chat/assets/support-chat.js"
        data-config-url="https://api.example.com/api/support-chat/config"
        data-stylesheet="https://api.example.com/api/support-chat/assets/support-chat.css"
        data-locale="en"
        defer></script>
```

Or initialise it yourself, for example in a React SPA with logged-in customers:

```js
window.SupportChat.init({
  configUrl: 'https://api.example.com/api/support-chat/config',
  stylesheet: 'https://api.example.com/api/support-chat/assets/support-chat.css',
  locale: i18n.language,
  authToken: () => localStorage.getItem('token'), // optional, used for prefill + linking the customer
})
// window.SupportChat.open() / .close() / .destroy()
```

The widget endpoints live under `api/` so Laravel's default CORS config (`paths => ['api/*']`) already covers them. The widget never sends cookies, so you don't need `supports_credentials`.

**Theming**: override CSS variables from your own stylesheet:

```css
.sc-chat {
  --sc-chat-primary: #0f766e;
  --sc-chat-offset-x: 24px;   /* distance from the side */
  --sc-chat-offset-y: 80px;   /* distance from the bottom */
}
```

## Admin access

The admin side is a JSON API (`/support-chat/admin/...` by default, behind `web` + `auth`), used by admin panels such as the [Nova package](https://github.com/GismatUL/nova-support-chat). Every route checks one of three gates:

| Gate | Allows |
|---|---|
| `supportChat.view` | list and read conversations |
| `supportChat.reply` | answer and close conversations |
| `supportChat.manage` | change settings, delete conversations |

**Access is denied by default outside the `local` environment.** Grant it in a service provider:

```php
use Gmammadov\SupportChat\SupportChat;

SupportChat::authorizeUsing(function ($user, string $ability) {
    return $user->is_admin;                                      // all abilities
    // or: $ability === SupportChat::ABILITY_VIEW || $user->hasRole('support');
});
```

You can also `Gate::define('supportChat.view', ...)` yourself; your definitions win. With the Nova package installed, users who can access Nova get access by default.

A `ConversationPolicy` is registered too (create/update always denied, delete needs `manage`), so policy-driven panels such as Nova or Filament enforce the same rules.

### Admin API

| Method | Path | Gate |
|---|---|---|
| GET | `conversations?status=&q=&per_page=` | view |
| GET | `conversations/stats` | view |
| GET | `conversations/{id}` | view |
| GET | `conversations/{id}/messages?version=` | view |
| POST | `conversations/{id}/messages` `{message}` | reply |
| POST | `conversations/{id}/close` | reply |
| DELETE | `conversations/{id}` | manage |
| GET / PUT | `settings` | manage |

Answers from the admin API are shown to the customer as "Support" (the admin's name is never sent to the customer) and are posted to the Telegram group with the admin's name, so operators there can follow and continue.

## Customers

To link conversations to logged-in customers and prefill the form, set a guard:

```dotenv
SUPPORT_CHAT_CUSTOMER_GUARD=web   # or sanctum, customer, ...
```

or resolve the customer yourself:

```php
SupportChat::resolveCustomerUsing(fn (Request $request) => ($user = $request->user('sanctum')) ? [
    'id' => $user->id, 'name' => $user->full_name, 'email' => $user->email, 'phone' => $user->mobile,
] : null);
```

## Events

`ConversationStarted`, `CustomerMessageReceived`, `OperatorReplied` (with `$admin` when answered from a panel) and `ConversationClosed` (`$reason` is `closed` or `expired`), all in `Gmammadov\SupportChat\Events`. Use them for notifications, analytics or broadcasting.

## Configuration

See [`config/support-chat.php`](config/support-chat.php): route prefixes and middleware, rate limits, queue, reference prefix, message length, polling interval, IP storage. Admin-editable settings (email/phone field: hidden/optional/required, auto-close hours, delete-after days) live in the `support_chat_settings` table. Secrets never do.

## Security

- **Customer access**: each conversation has a random 64-character token, returned once and stored only as a SHA-256 hash. Every lookup failure returns the same 404, so UUIDs cannot be probed.
- **No cookies on the widget API**: requests are stateless (`credentials: 'omit'`), so there is no CSRF surface. The admin API uses session auth + CSRF (`web` middleware).
- **Telegram webhook** requires the secret token header (compared in constant time; the endpoint is closed if no secret is set), only accepts the configured chat, optionally only listed operators, and ignores bots and duplicate deliveries.
- **XSS**: messages are stored as plain text. The widget renders with `textContent`, the Nova panel with text interpolation, and Telegram messages are HTML-escaped. Control characters and bidi overrides are stripped.
- **Rate limits** per IP and per conversation for starting chats, sending, polling and the webhook (configure TrustProxies behind a load balancer).
- **Least privilege**: admin access is off by default; view, reply and manage are separate abilities; customers never see admin identities or Telegram internals.
- **Secrets** stay in the environment. The bot token is scrubbed from every error message and never sent to browsers.
- **Privacy**: IP storage can be disabled; old conversations can be deleted automatically.

Please report vulnerabilities privately: see [SECURITY.md](SECURITY.md).

## Testing

```bash
composer test
```

## License

MIT
