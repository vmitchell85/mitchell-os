<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Responses\ConversationReplyResponse;
use App\Models\Conversation;
use App\Services\Claude\MessageRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ConversationController extends Controller
{
    /**
     * The user's own conversations, most recently active first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ConversationResource::collection(
            $request->user()->conversations()->latest('updated_at')->latest('id')->paginate(50),
        );
    }

    /**
     * Start a conversation with its first message and answer it.
     */
    public function store(Request $request): ConversationReplyResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'stream' => ['sometimes', 'boolean'],
        ]);

        $conversation = $request->user()->conversations()->create([
            'title' => Str::limit($validated['message'], 60),
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $validated['message'],
        ]);

        return new ConversationReplyResponse($conversation, Response::HTTP_CREATED);
    }

    public function show(Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        return new ConversationResource($conversation->load(['messages' => fn ($query) => $query->oldest('id')]));
    }
}
