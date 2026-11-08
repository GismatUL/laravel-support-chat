<?php

namespace Gismatul\SupportChat\Http\Controllers\Api;

use Gismatul\SupportChat\Http\Middleware\ResolveConversation;
use Gismatul\SupportChat\Http\Requests\SendMessageRequest;
use Gismatul\SupportChat\Http\Requests\StartConversationRequest;
use Gismatul\SupportChat\Http\Resources\ConversationResource;
use Gismatul\SupportChat\Http\Resources\MessageResource;
use Gismatul\SupportChat\Models\Conversation;
use Gismatul\SupportChat\Services\ChatService;
use Gismatul\SupportChat\Support\WidgetConfig;
use Gismatul\SupportChat\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public, stateless API used by the website widget. No session or cookies are involved:
 * a conversation is authorised by its secret token (see ResolveConversation).
 */
class ChatController extends Controller
{
    public function __construct(protected ChatService $chat)
    {
    }

    /**
     * Widget bootstrap for frontends that are not rendered by Laravel (React, Vue, static sites...).
     */
    public function config(Request $request, WidgetConfig $config): JsonResponse
    {
        $locale = $request->query('locale');

        return response()->json(['data' => $config->toArray($request, is_string($locale) ? $locale : null)]);
    }

    public function start(StartConversationRequest $request): JsonResponse
    {
        $customer = SupportChat::customer($request);

        [$conversation, $token] = $this->chat->startConversation(
            $request->safe()->only(['name', 'email', 'phone']),
            $request->ip(),
            $customer['id'] ?? null
        );

        $messages = [];

        if ($text = $request->validated('message')) {
            [$message] = $this->chat->addCustomerMessage($conversation, $text, $request->validated('client_message_id'));
            $messages[] = $message;
        }

        return response()->json([
            'data' => [
                'conversation' => new ConversationResource($conversation->refresh()),
                // Returned once; the browser keeps it and sends it in the X-Chat-Token header.
                'token' => $token,
                'messages' => MessageResource::collection($messages),
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = $this->conversation($request);

        return response()->json([
            'data' => [
                'conversation' => new ConversationResource($conversation),
                'messages' => MessageResource::collection(
                    $this->chat->messagesAfter($conversation, (int) ($validated['after_id'] ?? 0))
                ),
            ],
        ]);
    }

    public function store(SendMessageRequest $request): JsonResponse
    {
        $conversation = $this->conversation($request);

        // A closed conversation stays closed; the widget offers to start a new one.
        if ($conversation->isClosed()) {
            return response()->json([
                'message' => trans('support-chat::support-chat.widget.closed_notice'),
                'code' => 'conversation_closed',
            ], 409);
        }

        [$message, $created] = $this->chat->addCustomerMessage(
            $conversation,
            $request->validated('message'),
            $request->validated('client_message_id')
        );

        return response()->json(['data' => new MessageResource($message)], $created ? 201 : 200);
    }

    protected function conversation(Request $request): Conversation
    {
        return $request->attributes->get(ResolveConversation::ATTRIBUTE);
    }
}
