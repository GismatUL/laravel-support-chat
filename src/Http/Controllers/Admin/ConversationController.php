<?php

namespace Gismatul\SupportChat\Http\Controllers\Admin;

use Gismatul\SupportChat\Enums\ConversationStatus;
use Gismatul\SupportChat\Http\Requests\SendMessageRequest;
use Gismatul\SupportChat\Http\Resources\Admin\ConversationResource;
use Gismatul\SupportChat\Http\Resources\Admin\MessageResource;
use Gismatul\SupportChat\Models\Conversation;
use Gismatul\SupportChat\Services\ChatService;
use Gismatul\SupportChat\SupportChat;
use Gismatul\SupportChat\Telegram\TelegramClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Admin JSON API: list, read, answer and close conversations. Answers are shown to the customer
 * in the widget and mirrored to the Telegram support chat.
 * Authentication comes from the route group; every route also checks a supportChat.* gate.
 */
class ConversationController extends Controller
{
    public function __construct(protected ChatService $chat)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));

        $conversations = Conversation::query()
            ->withCount('messages')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . addcslashes($search, '%_\\') . '%';

                $query->where(fn ($query) => $query
                    ->where('reference', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_email', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like));
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        return ConversationResource::collection($conversations)->response();
    }

    /**
     * Counts per status (e.g. for a "pending" badge in a menu).
     */
    public function stats(): JsonResponse
    {
        $counts = Conversation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json(['data' => collect(ConversationStatus::cases())
            ->mapWithKeys(fn (ConversationStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])]);
    }

    public function show(string $conversation, Request $request): JsonResponse
    {
        $conversation = $this->find($conversation);
        $messages = $this->allMessages($conversation);

        return response()->json([
            'data' => [
                'conversation' => new ConversationResource($conversation),
                'messages' => MessageResource::collection($messages),
                'version' => $this->version($conversation, $messages),
                'can' => $this->abilities($request),
                'telegram_configured' => app(TelegramClient::class)->isConfigured(),
            ],
        ]);
    }

    /**
     * Polled by admin screens. Returns the messages only when something changed since the version the
     * screen has (new message, Telegram delivery status, conversation status).
     */
    public function messages(string $conversation, Request $request): JsonResponse
    {
        $conversation = $this->find($conversation);
        $messages = $this->allMessages($conversation);
        $version = $this->version($conversation, $messages);

        if ($version === (string) $request->query('version')) {
            return response()->json(['data' => ['changed' => false, 'version' => $version]]);
        }

        return response()->json([
            'data' => [
                'changed' => true,
                'version' => $version,
                'conversation' => new ConversationResource($conversation),
                'messages' => MessageResource::collection($messages),
            ],
        ]);
    }

    public function reply(string $conversation, SendMessageRequest $request): JsonResponse
    {
        $conversation = $this->find($conversation);

        if ($conversation->isClosed()) {
            return response()->json(['message' => trans('support-chat::support-chat.admin.reply_closed')], 422);
        }

        $message = $this->chat->addAdminMessage($conversation, $request->validated('message'), $request->user());

        return response()->json(['data' => new MessageResource($message)], 201);
    }

    public function close(string $conversation, Request $request): JsonResponse
    {
        $conversation = $this->find($conversation);

        if (! $conversation->isClosed()) {
            $this->chat->closeConversation($conversation, reason: 'closed', admin: $request->user());
        }

        return response()->json(['data' => new ConversationResource($conversation->refresh())]);
    }

    public function destroy(string $conversation): JsonResponse
    {
        // Messages are removed by the foreign key cascade.
        $this->find($conversation)->delete();

        return response()->json(null, 204);
    }

    protected function find(string $id): Conversation
    {
        return Conversation::query()->findOrFail($id);
    }

    protected function allMessages(Conversation $conversation): Collection
    {
        // Bounded so a pathological conversation cannot exhaust memory; newest 500 messages.
        return $conversation->messages()->orderByDesc('id')->limit(500)->get()->reverse()->values();
    }

    protected function version(Conversation $conversation, Collection $messages): string
    {
        return md5($conversation->status->value . '|' . $messages
            ->map(fn ($message) => $message->getKey() . ':' . $message->telegram_status?->value)
            ->implode(','));
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(Request $request): array
    {
        $gate = Gate::forUser($request->user());

        return [
            'reply' => $gate->allows(SupportChat::ABILITY_REPLY),
            'manage' => $gate->allows(SupportChat::ABILITY_MANAGE),
        ];
    }
}
