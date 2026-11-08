<?php

namespace Gmammadov\SupportChat\Console;

use Gmammadov\SupportChat\Enums\ConversationStatus;
use Gmammadov\SupportChat\Models\Conversation;
use Gmammadov\SupportChat\Services\ChatService;
use Gmammadov\SupportChat\Settings\ChatSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExpireConversationsCommand extends Command
{
    protected $signature = 'support-chat:expire';

    protected $description = 'Auto-close inactive conversations and delete old closed ones (per the admin settings)';

    public function handle(ChatService $chat, ChatSettings $settings): int
    {
        $closed = $this->closeInactive($chat, $settings->autoCloseHours());
        $deleted = $this->deleteOld($settings->deleteAfterDays());

        $this->components->info(sprintf('Closed %d inactive conversation(s), deleted %d old conversation(s).', $closed, $deleted));

        return self::SUCCESS;
    }

    protected function closeInactive(ChatService $chat, int $hours): int
    {
        if ($hours <= 0) {
            return 0;
        }

        $threshold = Carbon::now()->subHours($hours);
        $count = 0;

        // Inactivity is measured from the last message of either side (or creation, if there are none).
        Conversation::query()
            ->where('status', '!=', ConversationStatus::Closed->value)
            ->where(fn ($query) => $query
                ->where('last_message_at', '<', $threshold)
                ->orWhere(fn ($query) => $query->whereNull('last_message_at')->where('created_at', '<', $threshold)))
            ->chunkById(100, function ($conversations) use ($chat, &$count) {
                foreach ($conversations as $conversation) {
                    if ($chat->closeConversation($conversation, reason: 'expired')) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    protected function deleteOld(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $threshold = Carbon::now()->subDays($days);
        $count = 0;

        // Only closed conversations are removed; their messages go with them (foreign key cascade).
        Conversation::query()
            ->where('status', ConversationStatus::Closed->value)
            ->where('closed_at', '<', $threshold)
            ->chunkById(200, function ($conversations) use (&$count) {
                $count += Conversation::query()->whereKey($conversations->modelKeys())->delete();
            });

        return $count;
    }
}
