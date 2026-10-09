<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    /**
     * Conversations are private to the household member who started them.
     * Others get a 404 so they can't tell which conversations exist.
     */
    public function view(User $user, Conversation $conversation): Response
    {
        return $conversation->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
