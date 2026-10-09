<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Chat\ReplyToConversation;
use App\Http\Controllers\Controller;
use App\Http\Responses\ConversationReplyResponse;
use App\Models\Conversation;
use App\Services\Claude\MessageRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationMessageController extends Controller
{
    /**
     * Send a message and answer it.
     */
    public function store(Request $request, Conversation $conversation, ReplyToConversation $replyToConversation): ConversationReplyResponse
    {
        Gate::authorize('view', $conversation);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'stream' => ['sometimes', 'boolean'],
        ]);

        abort_if(
            $replyToConversation->unansweredMessage($conversation) !== null,
            409,
            'The last message hasn\'t been answered yet. Retry it with the reply endpoint first.',
        );

        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $validated['message'],
        ]);

        return new ConversationReplyResponse($conversation);
    }
}
