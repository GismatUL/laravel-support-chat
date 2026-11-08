<?php

namespace Gmammadov\SupportChat\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's throttle middleware, backed by the chat's own rate limiter (on the cache store chosen in
 * config('support-chat.rate_limits.store')). Usage: ThrottleChatRequests::class . ':support-chat-polls'.
 */
class ThrottleChatRequests extends ThrottleRequests
{
    public const LIMITER = 'support-chat.rate-limiter';

    public function __construct()
    {
        parent::__construct(app(self::LIMITER));
    }
}
