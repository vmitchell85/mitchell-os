<?php

namespace App\Actions\Meals;

use App\Models\HouseholdSetting;
use App\Models\Meal;
use Carbon\CarbonImmutable;

/**
 * The instructions, context and response shape the meal planner gives Claude.
 */
class MealPlanPrompt
{
    public const string System = 'You plan meals for a family. Suggest practical home-cooked meals that suit the household notes, vary across the week, and avoid repeating anything they have eaten recently. '
        .'For each meal, list the ingredients to buy with rough quantities (for example "500g beef mince"), leaving out basics like salt, pepper and oil. '
        .'Keep notes short and useful, such as prep time or a tip; leave them empty if there is nothing worth saying.';

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, one of the requested slots.'],
                            'category' => ['type' => 'string', 'enum' => Meal::Categories],
                            'name' => ['type' => 'string'],
                            'ingredients' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['date', 'category', 'name', 'ingredients', 'note'],
                    ],
                ],
            ],
            'required' => ['meals'],
        ];
    }

    /**
     * The household's food rules, what's already planned this week and what was eaten recently.
     */
    public static function context(CarbonImmutable $weekStart, ?Meal $except = null): string
    {
        $describe = fn (Meal $meal): string => "- {$meal->date->format('l j F')}, {$meal->category}: {$meal->name}";

        $thisWeek = Meal::whereBetween('date', [$weekStart, $weekStart->addDays(6)])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->orderBy('date')->get()->map($describe);

        $recent = Meal::approved()
            ->whereBetween('date', [$weekStart->subWeeks(4), $weekStart->subDay()])
            ->distinct()->orderBy('name')->pluck('name');

        return implode("\n\n", array_filter([
            HouseholdSetting::foodRulesPrompt(),
            $thisWeek->isNotEmpty() ? "Already planned this week:\n".$thisWeek->implode("\n") : null,
            $recent->isNotEmpty() ? "Eaten in the last four weeks:\n".$recent->map(fn (string $name): string => "- {$name}")->implode("\n") : null,
        ]));
    }

    /**
     * Turn one suggestion from Claude into meal attributes, as a draft.
     *
     * @param  array<string, mixed>  $suggestion
     * @return array{name: string, description: string|null, note: string|null, approved_at: null}
     */
    public static function attributesFrom(array $suggestion): array
    {
        $ingredients = array_filter(array_map('trim', $suggestion['ingredients'] ?? []));

        return [
            'name' => trim($suggestion['name']),
            'description' => $ingredients !== [] ? implode("\n", $ingredients) : null,
            'note' => trim($suggestion['note'] ?? '') ?: null,
            'approved_at' => null,
        ];
    }
}
