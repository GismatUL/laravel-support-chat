<?php

namespace Gismatul\SupportChat\Policies;

use Gismatul\SupportChat\Models\Conversation;
use Gismatul\SupportChat\SupportChat;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Maps model abilities onto the supportChat.* gates, so admin panels that rely on policies
 * (Laravel Nova, Filament, ...) enforce the same rules as the JSON API.
 *
 * Conversations are created by customers only and their data is never edited by admins:
 * create/update/replicate are always denied. Answering and closing go through ChatService.
 */
class ConversationPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, SupportChat::ABILITY_VIEW);
    }

    public function view(Authenticatable $user, Conversation $conversation): bool
    {
        return $this->allows($user, SupportChat::ABILITY_VIEW);
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, Conversation $conversation): bool
    {
        return false;
    }

    public function replicate(Authenticatable $user, Conversation $conversation): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, Conversation $conversation): bool
    {
        return $this->allows($user, SupportChat::ABILITY_MANAGE);
    }

    public function forceDelete(Authenticatable $user, Conversation $conversation): bool
    {
        return $this->allows($user, SupportChat::ABILITY_MANAGE);
    }

    public function restore(Authenticatable $user, Conversation $conversation): bool
    {
        return false;
    }

    /**
     * Laravel Nova: running a (non-destructive) action, e.g. "Close conversation".
     */
    public function runAction(Authenticatable $user, $model = null, $action = null): bool
    {
        return $this->allows($user, SupportChat::ABILITY_REPLY);
    }

    /**
     * Laravel Nova: running a destructive action.
     */
    public function runDestructiveAction(Authenticatable $user, $model = null, $action = null): bool
    {
        return $this->allows($user, SupportChat::ABILITY_MANAGE);
    }

    protected function allows(Authenticatable $user, string $ability): bool
    {
        return Gate::forUser($user)->allows($ability);
    }
}
