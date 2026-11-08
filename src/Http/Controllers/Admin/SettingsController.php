<?php

namespace Gmammadov\SupportChat\Http\Controllers\Admin;

use Gmammadov\SupportChat\Http\Requests\UpdateSettingsRequest;
use Gmammadov\SupportChat\Settings\ChatSettings;
use Gmammadov\SupportChat\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SettingsController extends Controller
{
    public function show(ChatSettings $settings, TelegramClient $telegram): JsonResponse
    {
        return response()->json(['data' => [
            'settings' => $settings->all(),
            // Status only: secrets are never sent to the browser.
            'telegram_configured' => $telegram->isConfigured(),
            'webhook_secret_configured' => (string) config('support-chat.telegram.webhook_secret') !== '',
        ]]);
    }

    public function update(UpdateSettingsRequest $request, ChatSettings $settings): JsonResponse
    {
        $settings->save($request->validated());

        return response()->json(['data' => ['settings' => $settings->all()]]);
    }
}
