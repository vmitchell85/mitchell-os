<?php

namespace App\Actions\Meals;

use App\Models\Meal;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use Carbon\CarbonImmutable;

/**
 * Replace one draft with a different suggestion for the same slot.
 */
class RegenerateMeal
{
    public function __construct(protected ClaudeCli $claude) {}

    /**
     * @throws ClaudeException
     */
    public function handle(User $user, Meal $draft, string $notes = ''): Meal
    {
        $result = $this->claude->ask(new ClaudeRequest(
            prompt: implode("\n\n", array_filter([
                "Suggest one {$draft->category} for {$draft->date->format('l, F j')} (date {$draft->date->toDateString()}). It must be something other than {$draft->name}.",
                MealPlanPrompt::context(CarbonImmutable::parse($draft->date)->startOfWeek(), except: $draft),
                $notes !== '' ? "Notes from the household:\n{$notes}" : null,
            ])),
            feature: 'meal-planner',
            user: $user,
            systemPrompt: MealPlanPrompt::System,
            jsonSchema: MealPlanPrompt::schema(),
        ));

        $suggestion = $result->structured['meals'][0] ?? null;

        if ($suggestion === null) {
            throw new ClaudeException('Claude did not suggest a meal.');
        }

        $draft->update(MealPlanPrompt::attributesFrom($suggestion));

        return $draft;
    }
}
