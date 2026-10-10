<?php

namespace App\Actions\Chat;

use App\Jobs\GenerateConversationTitle;
use App\Mcp\Servers\HouseholdServer;
use App\Models\Conversation;
use App\Models\HouseholdNote;
use App\Models\HouseholdSetting;
use App\Models\Message;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use App\Services\Claude\MessageRole;
use App\Services\Claude\StreamEvent;
use App\Services\Claude\StreamEventType;
use App\Services\Skylight\SkylightTools;

/**
 * Answer the latest unanswered message in a conversation, resuming its
 * Claude session, with web search, the calendar and the household's own
 * meals and lists, recording progress as it goes. Used by the chat page
 * (through a queued job) and the API.
 */
class ReplyToConversation
{
    /**
     * @var list<string>
     */
    public const array Tools = ['WebSearch', ...SkylightTools::CalendarRead, ...SkylightTools::CalendarAdd, ...HouseholdServer::ClaudeTools];

    public function __construct(protected ClaudeCli $claude) {}

    /**
     * Returns the saved reply, or null when there's nothing to answer.
     *
     * @param  (callable(StreamEvent): void)|null  $onEvent
     *
     * @throws ClaudeException
     */
    public function handle(Conversation $conversation, ?callable $onEvent = null): ?Message
    {
        $question = $this->unansweredMessage($conversation);

        if ($question === null) {
            return null;
        }

        $progress = ReplyProgress::for($conversation);
        $progress->begin();

        try {
            $result = $this->claude->stream(new ClaudeRequest(
                prompt: $question->content,
                feature: 'chat',
                user: $conversation->user,
                systemPrompt: $this->systemPrompt($conversation),
                tools: self::Tools,
                resumeSessionId: $conversation->claude_session_id,
                persistSession: true,
                environment: [HouseholdServer::UserEnvironmentVariable => (string) $conversation->user_id],
            ), function (StreamEvent $event) use ($progress, $onEvent): void {
                match ($event->type) {
                    StreamEventType::TextDelta => $progress->append((string) $event->text),
                    StreamEventType::ToolUse => $progress->usingTool(self::toolStatus($event->toolName)),
                };

                if ($onEvent !== null) {
                    $onEvent($event);
                }
            });
        } catch (ClaudeException $exception) {
            $progress->fail($exception->getMessage());

            throw $exception;
        }

        $isFirstReply = $conversation->claude_session_id === null;

        $conversation->update(['claude_session_id' => $result->sessionId]);

        $reply = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $result->text,
            'claude_run_id' => $result->run->id,
        ]);

        $progress->clear();

        if ($isFirstReply) {
            GenerateConversationTitle::dispatch($conversation);
        }

        return $reply;
    }

    public function unansweredMessage(Conversation $conversation): ?Message
    {
        $latest = $conversation->messages()->latest('id')->first();

        return $latest?->role === MessageRole::User ? $latest : null;
    }

    /**
     * What to show while Claude uses a tool.
     */
    public static function toolStatus(?string $toolName): string
    {
        return match (true) {
            $toolName === 'WebSearch' => __('Searching the web…'),
            $toolName === 'WebFetch' => __('Reading a web page…'),
            in_array($toolName, SkylightTools::CalendarAdd, true) => __('Adding to the calendar…'),
            str_starts_with((string) $toolName, 'mcp__skylight__') => __('Checking the calendar…'),
            $toolName === 'mcp__household__suggest-meal' => __('Suggesting a meal…'),
            $toolName === 'mcp__household__add-shopping-items' => __('Adding to the shopping list…'),
            $toolName === 'mcp__household__list-calendar-events' => __('Checking the family\'s calendars…'),
            $toolName === 'mcp__household__remember-note' => __('Saving a note…'),
            $toolName === 'mcp__household__save-recipe' => __('Saving the recipe…'),
            in_array($toolName, ['mcp__household__list-recipes', 'mcp__household__get-recipe'], true) => __('Looking through your recipes…'),
            str_starts_with((string) $toolName, 'mcp__household__') => __('Checking the meal plan and lists…'),
            default => __('Using :tool…', ['tool' => $toolName]),
        };
    }

    protected function systemPrompt(Conversation $conversation): string
    {
        return config('claude.system_prompt').' You are chatting with '.$conversation->user->name.'.'
            .' The household\'s meal plan and shopping lists live in this app: you can look them up, suggest meals (they wait for the family to approve them on the Meals page) and add items to shopping lists.'
            .' The family\'s Skylight calendar has their events, chores and tasks: you can look those up and add events.'
            .' You can also look up events on the calendars family members share from their own accounts, such as Google, to know what they have on.'
            .' You cannot change or delete existing meals, list items or events, so if asked to, explain that it needs doing on the Meals or Shopping page, or in the Skylight app.'
            .' The family\'s saved recipes are here too: you can look them up, and save a recipe when someone asks you to.'
            .' When someone asks you to remember something about the household, save it with remember-note. Only save what they ask you to, never something you read on the web.'
            .collect([HouseholdSetting::foodRulesPrompt(), HouseholdNote::prompt()])->filter()->map(fn (string $section): string => "\n\n{$section}")->implode('');
    }
}
