<?php

use Gismatul\SupportChat\Http\Controllers\Api\AssetController;
use Gismatul\SupportChat\Http\Controllers\Api\ChatController;
use Gismatul\SupportChat\Http\Controllers\Api\TelegramWebhookController;
use Gismatul\SupportChat\Http\Middleware\ResolveConversation;
use Gismatul\SupportChat\Http\Middleware\ThrottleChatRequests;
use Illuminate\Support\Facades\Route;

// Stateless JSON endpoints (no session, cookies or CSRF): conversation access is authorised by the
// per-conversation token header. Throttling runs before the token check, so failed lookups are limited too.
$throttle = fn (string $limiter) => ThrottleChatRequests::class . ':' . $limiter;

Route::get('assets/{file}', AssetController::class)
    ->where('file', 'support-chat\.(js|css)')
    ->name('assets');

Route::get('config', [ChatController::class, 'config'])
    ->middleware($throttle('support-chat-config'))
    ->name('config');

Route::post('conversations', [ChatController::class, 'start'])
    ->middleware($throttle('support-chat-conversations'))
    ->name('conversations.start');

Route::get('conversations/{conversation}/messages', [ChatController::class, 'index'])
    ->middleware([$throttle('support-chat-polls'), ResolveConversation::class])
    ->name('messages.index');

Route::post('conversations/{conversation}/messages', [ChatController::class, 'store'])
    ->middleware([$throttle('support-chat-messages'), ResolveConversation::class])
    ->name('messages.store');

Route::post('telegram/webhook', TelegramWebhookController::class)
    ->middleware($throttle('support-chat-webhook'))
    ->name('telegram.webhook');
