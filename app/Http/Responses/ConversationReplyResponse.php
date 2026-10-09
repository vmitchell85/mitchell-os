<?php

namespace App\Http\Responses;

use App\Actions\Chat\ReplyToConversation;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\StreamEvent;
use App\Services\Claude\StreamEventType;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Claude's reply to the latest message in a conversation, either as JSON
 * once it's finished or, when the request asks to stream, as server-sent
 * events: `conversation`, then `status` and `text` as Claude works, then
 * `done` with the saved message or `error`.
 */
class ConversationReplyResponse implements Responsable
{
    public function __construct(
        protected Conversation $conversation,
        protected int $status = Response::HTTP_OK,
    ) {}

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $replyToConversation = app(ReplyToConversation::class);

        return $request->boolean('stream')
            ? $this->streamed($request, $replyToConversation)
            : $this->json($request, $replyToConversation);
    }

    protected function json(Request $request, ReplyToConversation $replyToConversation): JsonResponse
    {
        try {
            $replyToConversation->handle($this->conversation);
        } catch (ClaudeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
                'conversation_id' => $this->conversation->id,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return (new ConversationResource($this->conversation->load(['messages' => fn ($query) => $query->oldest('id')])))
            ->response($request)
            ->setStatusCode($this->status);
    }

    protected function streamed(Request $request, ReplyToConversation $replyToConversation): StreamedResponse
    {
        return response()->stream(function () use ($request, $replyToConversation): void {
            $this->send('conversation', ['id' => $this->conversation->id, 'title' => $this->conversation->title]);

            try {
                $reply = $replyToConversation->handle($this->conversation, function (StreamEvent $event): void {
                    match ($event->type) {
                        StreamEventType::TextDelta => $this->send('text', ['text' => $event->text]),
                        StreamEventType::ToolUse => $this->sendToolUse($event->toolName),
                    };
                });
            } catch (ClaudeException $exception) {
                report($exception);
                $this->send('error', ['message' => $exception->getMessage()]);

                return;
            }

            $this->send('done', $reply === null ? ['message' => null] : ['message' => (new MessageResource($reply))->resolve($request)]);
        }, $this->status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Say what Claude is doing, and break the text so what it wrote before
     * the tool call doesn't run into what it writes after.
     */
    protected function sendToolUse(?string $toolName): void
    {
        $this->send('status', ['text' => ReplyToConversation::toolStatus($toolName)]);
        $this->send('text', ['text' => "\n\n"]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $event, array $data): void
    {
        echo "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
