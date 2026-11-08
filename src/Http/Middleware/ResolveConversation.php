<?php

namespace Gismatul\SupportChat\Http\Middleware;

use Closure;
use Gismatul\SupportChat\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A conversation is only accessible with both its UUID and the secret access token issued when it was
 * created (sent in the X-Chat-Token header). Every failure looks the same (404), so the endpoint cannot
 * be used to probe which UUIDs exist.
 */
class ResolveConversation
{
    public const HEADER = 'X-Chat-Token';

    public const ATTRIBUTE = 'support_chat_conversation';

    public function handle(Request $request, Closure $next): Response
    {
        $uuid = (string) $request->route('conversation');
        $token = $request->header(self::HEADER);

        $conversation = Str::isUuid($uuid) && is_string($token) && strlen($token) <= 128
            ? Conversation::query()->where('uuid', $uuid)->first()
            : null;

        if (! $conversation || ! $conversation->hasAccessToken($token)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $request->attributes->set(self::ATTRIBUTE, $conversation);

        return $next($request);
    }
}
