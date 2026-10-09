<?php

namespace App\Actions\Meals;

use App\Models\Meal;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ask Claude to suggest meals for the empty slots in a week. Suggestions
 * are saved as drafts for the household to review; nothing reaches Skylight
 * until a draft is approved.
 */
class DraftMealPlan
{
    public function __construct(protected ClaudeCli $claude) {}

    /**
     * @param  list<string>  $categories  Which meals of the day to plan, e.g. ['Dinner'].
     * @return Collection<int, Meal> The new drafts.
     *
     * @throws ClaudeException
     */
    public function handle(User $user, CarbonImmutable $weekStart, array $categories, string $notes = ''): Collection
    {
        $slots = $this->emptySlots($weekStart, $categories);

        if ($slots === []) {
            return new Collection;
        }

        $result = $this->claude->ask(new ClaudeRequest(
            prompt: $this->prompt($weekStart, $slots, $notes),
            feature: 'meal-planner',
            user: $user,
            systemPrompt: MealPlanPrompt::System,
            jsonSchema: MealPlanPrompt::schema(),
            maxBudgetUsd: 0.5,
        ));

        $drafts = new Collection;
        $openSlots = array_flip(array_map(fn (array $slot): string => "{$slot['date']}|{$slot['category']}", $slots));

        foreach ($result->structured['meals'] ?? [] as $suggestion) {
            $key = "{$suggestion['date']}|{$suggestion['category']}";

            if (! isset($openSlots[$key])) {
                continue;
            }

            unset($openSlots[$key]);

            $drafts->push(Meal::create([
                ...MealPlanPrompt::attributesFrom($suggestion),
                'date' => $suggestion['date'],
                'category' => $suggestion['category'],
                'added_by' => $user->id,
            ]));
        }

        return $drafts;
    }

    /**
     * @param  list<string>  $categories
     * @return list<array{date: string, category: string}>
     */
    protected function emptySlots(CarbonImmutable $weekStart, array $categories): array
    {
        $taken = Meal::whereBetween('date', [$weekStart, $weekStart->addDays(6)])->get()
            ->map(fn (Meal $meal): string => "{$meal->date->toDateString()}|{$meal->category}")
            ->flip();

        $slots = [];

        foreach (range(0, 6) as $offset) {
            $date = $weekStart->addDays($offset)->toDateString();

            foreach (array_intersect(Meal::Categories, $categories) as $category) {
                if (! $taken->has("{$date}|{$category}")) {
                    $slots[] = ['date' => $date, 'category' => $category];
                }
            }
        }

        return $slots;
    }

    /**
     * @param  list<array{date: string, category: string}>  $slots
     */
    protected function prompt(CarbonImmutable $weekStart, array $slots, string $notes): string
    {
        $slotLines = array_map(
            fn (array $slot): string => '- '.CarbonImmutable::parse($slot['date'])->format('l j F').": {$slot['category']} (date {$slot['date']})",
            $slots,
        );

        return implode("\n\n", array_filter([
            "Suggest meals for these empty slots:\n".implode("\n", $slotLines),
            MealPlanPrompt::context($weekStart),
            $notes !== '' ? "Notes from the household:\n{$notes}" : null,
        ]));
    }
}
