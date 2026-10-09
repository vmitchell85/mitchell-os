<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Chat\ReplyProgress;
use App\Http\Controllers\Controller;
use App\Http\Responses\ConversationReplyResponse;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationReplyController extends Controller
{
    /**
     * Try again to answer the latest message, after Claude failed to.
     */
    public function __invoke(Request $request, Conversation $conversation): ConversationReplyResponse
    {
        Gate::authorize('view', $conversation);

        $request->validate([
            'stream' => ['sometimes', 'boolean'],
        ]);

        abort_if(ReplyProgress::for($conversation)->isReplying(), 409, 'Claude is still replying.');

        return new ConversationReplyResponse($conversation);
    }
}
