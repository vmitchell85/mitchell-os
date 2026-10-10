<?php

namespace App\Mcp\Tools;

use App\Models\HouseholdNote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('remember-note')]
#[Description('Save a short note about the household that you and the meal planner should always keep in mind, such as an allergy, a routine or a preference. Only use this when the person chatting asks you to remember something. The family can see and edit notes on the Household page.')]
class RememberNoteTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $note = HouseholdNote::create([
            'body' => trim($validated['note']),
            'added_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return Response::text("Saved the note \"{$note->body}\". The family can see and edit it on the Household page.");
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->description('One short, self-contained fact, e.g. "Lucy is allergic to peanuts."')->required(),
        ];
    }
}
