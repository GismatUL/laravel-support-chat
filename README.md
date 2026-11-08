# Laravel Support Chat

A live-chat widget for Laravel websites. Customers write in a small chat bubble on your site; your team answers **from a Telegram group** (by replying to the bot's message) or **from an admin panel**. Answers from either side reach the customer and are mirrored to the other side.

- Works on Blade pages **and** on separate frontends (React, Vue, Next, static sites) with one `<script>` tag
- No WebSocket server, no queue worker required (short polling, sends to Telegram after the response)
- Admin JSON API with fine-grained gates, plus a ready-made [Laravel Nova panel](https://github.com/GismatUL/nova-support-chat)
- Built with security in mind: see [Security](#security)
- Translations: English, Azerbaijani, Russian

Requires PHP 8.2+ (a version the PHP team still supports) and Laravel 10, 11, 12 or 13.

## Installation

```bash
composer require gismatul/laravel-support-chat
php artisan support-chat:install
```

The install command publishes `config/support-chat.php`, runs the migrations and prints the remaining steps.

### 1. Telegram

You need four values. Get them in this order: steps 2 and 4 read the group's recent messages, which only works **before** the webhook is registered in step 5.

```dotenv
SUPPORT_CHAT_TELEGRAM_BOT_TOKEN=        # step 1
SUPPORT_CHAT_TELEGRAM_CHAT_ID=          # step 2
SUPPORT_CHAT_TELEGRAM_WEBHOOK_SECRET=   # step 3
SUPPORT_CHAT_TELEGRAM_OPERATOR_IDS=     # step 4, optional
```

**Step 1: create the bot and get the token (`SUPPORT_CHAT_TELEGRAM_BOT_TOKEN`)**

1. In Telegram, open [@BotFather](https://t.me/BotFather) (Telegram's official bot for creating bots; check for the blue verified tick) and send `/newbot`.
2. Send a display name, e.g. `Example Support`, then a username that ends in `bot`, e.g. `example_support_bot`.
3. BotFather replies with a token like `7123456789:AAH-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`. That whole string is the token.

Treat the token like a password: anyone who has it controls the bot. Keep it only in `.env`. If it leaks, send `/revoke` to BotFather to get a new one.

**Step 2: create the support group and get its id (`SUPPORT_CHAT_TELEGRAM_CHAT_ID`)**

1. Create a Telegram group for your support team (*New Group*) and add the bot by its username.
2. Send any message in the group, e.g. `hello`.
3. Open this address in a browser, with your token in place of `<TOKEN>`:

   ```
   https://api.telegram.org/bot<TOKEN>/getUpdates
   ```

4. In the response, find `"chat":{"id":-100…,"title":"<your group name>"…}`. That negative number, including the minus sign, is the chat id.

If the response is `{"ok":true,"result":[]}`, send another message in the group and reload. If it still stays empty, a webhook is already set: run `php artisan support-chat:telegram-webhook --delete` and try again.

The id changes if Telegram converts the group to a *supergroup* (for example when you enable topics or make the history visible to new members). Check the id again after changing group settings.

**Step 3: make up the webhook secret (`SUPPORT_CHAT_TELEGRAM_WEBHOOK_SECRET`)**

You don't get this from Telegram; you generate it. Telegram sends it back with every update, so the app can reject requests that don't come from Telegram. Any random 16 to 256 characters of `A-Z a-z 0-9 _ -`:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

**Step 4 (optional): operator user ids (`SUPPORT_CHAT_TELEGRAM_OPERATOR_IDS`)**

Leave this empty to let every member of the group answer customers. To allow only certain people, have each operator send a message in the group, reload the `getUpdates` address from step 2, and copy each `"from":{"id":…}` number (a positive number, not the group id). Separate them with commas: `11111111,22222222`.

**Step 5: register the webhook**

Your app must be reachable over public HTTPS, with `APP_URL` set to that address, so this is usually done on the server:

```bash
php artisan support-chat:telegram-webhook          # set
php artisan support-chat:telegram-webhook --info   # check: shows the URL and the last error, if any
```

**How operators answer:** use Telegram's **Reply** on a customer's message in the group. Replying `/close` closes the conversation. Messages that aren't replies to the bot are ignored.

The bot's *privacy mode* can stay on (the default): bots always receive replies to their own messages. If replies don't reach the site, send `/setprivacy` → *Disable* to BotFather, then remove the bot from the group and add it again (the change only applies after re-adding).

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
use Gismatul\SupportChat\SupportChat;

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

`ConversationStarted`, `CustomerMessageReceived`, `OperatorReplied` (with `$admin` when answered from a panel) and `ConversationClosed` (`$reason` is `closed` or `expired`), all in `Gismatul\SupportChat\Events`. Use them for notifications, analytics or broadcasting.

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
