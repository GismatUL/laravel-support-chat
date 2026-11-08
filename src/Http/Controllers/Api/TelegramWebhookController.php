<?php

namespace Gismatul\SupportChat\Http\Controllers\Api;

use Gismatul\SupportChat\Telegram\TelegramWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function __invoke(Request $request, TelegramWebhookHandler $handler): JsonResponse
    {
        $secret = (string) config('support-chat.telegram.webhook_secret');
        $provided = (string) $request->header(self::SECRET_HEADER, '');

        // Telegram echoes the secret_token given to setWebhook in this header on every request.
        // Without a configured secret the endpoint is closed (fail closed).
        if ($secret === '' || ! hash_equals($secret, $provided)) {
            Log::warning('[SupportChat] Rejected Telegram webhook request with an invalid secret token', [
                'ip' => $request->ip(),
                'secret_configured' => $secret !== '',
            ]);

            return response()->json(['ok' => false], 403);
        }

        $update = $request->json()->all();

        if (! isset($update['update_id']) || ! is_int($update['update_id'])) {
            return response()->json(['ok' => false], 400);
        }

        try {
            $handler->handle($update);
        } catch (Throwable $exception) {
            Log::error('[SupportChat] Telegram webhook processing failed', [
                'update_id' => $update['update_id'],
                'error' => $exception->getMessage(),
            ]);

            // Non-2xx makes Telegram retry later; duplicates are safe thanks to message de-duplication.
            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
