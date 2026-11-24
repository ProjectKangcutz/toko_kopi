<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;

class ConversationPolicy
{
    public function view(Customer|User $actor, Conversation $conversation): bool
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('conversations.view');
        }

        return (int) $conversation->customer_id === (int) $actor->id;
    }

    public function reply(Customer|User $actor, Conversation $conversation): bool
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('conversations.reply');
        }

        return (int) $conversation->customer_id === (int) $actor->id && $conversation->status !== 'closed';
    }
}
