<?php

namespace Gismatul\SupportChat;

use Closure;
use Gismatul\SupportChat\Http\Controllers\Admin\ConversationController;
use Gismatul\SupportChat\Http\Controllers\Admin\SettingsController;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Extension points for the host application. Call these from a service provider's boot() method.
 */
class SupportChat
{
    /** Ability to list and read conversations. */
    public const ABILITY_VIEW = 'supportChat.view';

    /** Ability to answer and close conversations. */
    public const ABILITY_REPLY = 'supportChat.reply';

    /** Ability to change settings and delete conversations. */
    public const ABILITY_MANAGE = 'supportChat.manage';

    public const ABILITIES = [self::ABILITY_VIEW, self::ABILITY_REPLY, self::ABILITY_MANAGE];

    /** @var (Closure(Authenticatable, string): bool)|null */
    protected static ?Closure $authorizeUsing = null;

    /** @var (Closure(Authenticatable, string): bool)|null Set by admin-panel integrations such as the Nova package. */
    protected static ?Closure $defaultAuthorizeUsing = null;

    /** @var (Closure(Request): (array|null))|null */
    protected static ?Closure $resolveCustomerUsing = null;

    /** @var (Closure(Authenticatable): string)|null */
    protected static ?Closure $operatorNameUsing = null;

    /**
     * Decide who may use the admin side. The callback receives the user and one of the ABILITY_* constants.
     *
     *     SupportChat::authorizeUsing(fn ($user, string $ability) => $user->is_admin);
     *
     * You can also define the gates (supportChat.view, supportChat.reply, supportChat.manage) yourself.
     * Without either, access is only granted in the "local" environment, or through an admin-panel
     * integration such as gismatul/nova-support-chat (which allows users that can access Nova).
     */
    public static function authorizeUsing(?Closure $callback): void
    {
        static::$authorizeUsing = $callback;
    }

    /**
     * @internal Used by admin-panel integrations to provide a default; authorizeUsing() always wins.
     */
    public static function defaultAuthorizeUsing(?Closure $callback): void
    {
        static::$defaultAuthorizeUsing = $callback;
    }

    public static function authorizes(?Authenticatable $user, string $ability): bool
    {
        if (! $user) {
            return false;
        }

        if (static::$authorizeUsing) {
            return (bool) call_user_func(static::$authorizeUsing, $user, $ability);
        }

        if (static::$defaultAuthorizeUsing) {
            return (bool) call_user_func(static::$defaultAuthorizeUsing, $user, $ability);
        }

        // Secure by default: outside local development, access must be granted explicitly.
        return app()->environment('local');
    }

    /**
     * Identify the logged-in customer of a widget request (prefills the form and links the conversation).
     * Only use authentication here, never request input.
     *
     *     SupportChat::resolveCustomerUsing(fn (Request $request) => ($u = $request->user('sanctum'))
     *         ? ['id' => $u->id, 'name' => $u->full_name, 'email' => $u->email, 'phone' => $u->mobile]
     *         : null);
     */
    public static function resolveCustomerUsing(?Closure $callback): void
    {
        static::$resolveCustomerUsing = $callback;
    }

    /**
     * @return array{id: int|string|null, name: string|null, email: string|null, phone: string|null}|null
     */
    public static function customer(Request $request): ?array
    {
        if (static::$resolveCustomerUsing) {
            $customer = call_user_func(static::$resolveCustomerUsing, $request);
        } else {
            $guard = config('support-chat.customer.guard');
            $user = $guard ? auth($guard)->user() : null;

            $customer = $user ? [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
                'phone' => $user->phone ?? null,
            ] : null;
        }

        if (! is_array($customer)) {
            return null;
        }

        $string = fn (string $key) => isset($customer[$key]) && is_scalar($customer[$key]) ? (string) $customer[$key] : null;

        return [
            'id' => $customer['id'] ?? null,
            'name' => $string('name'),
            'email' => $string('email'),
            'phone' => $string('phone'),
        ];
    }

    /**
     * Name shown to Telegram operators and in admin panels for answers written by an admin user.
     * It is never shown to customers.
     */
    public static function operatorNameUsing(?Closure $callback): void
    {
        static::$operatorNameUsing = $callback;
    }

    public static function operatorName(Authenticatable $user): string
    {
        if (static::$operatorNameUsing) {
            return (string) call_user_func(static::$operatorNameUsing, $user);
        }

        return (string) ($user->name ?? $user->email ?? ('#' . $user->getAuthIdentifier()));
    }

    /**
     * Register the admin JSON API inside the current route group. Every route checks a gate,
     * so the group only has to authenticate the user (and provide CSRF protection).
     */
    public static function adminRoutes(): void
    {
        $view = 'can:' . self::ABILITY_VIEW;
        $reply = 'can:' . self::ABILITY_REPLY;
        $manage = 'can:' . self::ABILITY_MANAGE;

        Route::get('conversations', [ConversationController::class, 'index'])->middleware($view)->name('conversations.index');
        Route::get('conversations/stats', [ConversationController::class, 'stats'])->middleware($view)->name('conversations.stats');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->middleware($view)->whereNumber('conversation')->name('conversations.show');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->middleware($view)->whereNumber('conversation')->name('conversations.messages');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply'])->middleware($reply)->whereNumber('conversation')->name('conversations.reply');
        Route::post('conversations/{conversation}/close', [ConversationController::class, 'close'])->middleware($reply)->whereNumber('conversation')->name('conversations.close');
        Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->middleware($manage)->whereNumber('conversation')->name('conversations.destroy');

        Route::get('settings', [SettingsController::class, 'show'])->middleware($manage)->name('settings.show');
        Route::put('settings', [SettingsController::class, 'update'])->middleware($manage)->name('settings.update');
    }

    /**
     * @internal Reset static state between tests.
     */
    public static function flushState(): void
    {
        static::$authorizeUsing = null;
        static::$defaultAuthorizeUsing = null;
        static::$resolveCustomerUsing = null;
        static::$operatorNameUsing = null;
    }
}
