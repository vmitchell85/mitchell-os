<?php

namespace App\Actions\Meals;

use App\Models\HouseholdSetting;
use App\Models\Meal;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Work out what to buy for the approved meals in a date range: one combined
 * line per ingredient, sorted into the list's sections, leaving out what's
 * already on the list. Returns proposals for the household to review;
 * nothing is added until they confirm.
 */
class BuildShoppingList
{
    public const string System = 'You turn a family\'s meal plan into a shopping list. Combine the same ingredient across meals into one line with the total quantity (for example "1kg beef mince"). '
        .'If a meal has no ingredients listed, use what that dish typically needs. Leave out basics most kitchens already have, such as salt, pepper, oil, flour and dried herbs, '
        .'and anything already on the list, even if it is worded differently. Put each item in one of the list\'s existing sections when one fits, otherwise use a common supermarket aisle name.';

    public function __construct(protected ClaudeCli $claude) {}

    /**
     * @return list<array{name: string, section: string|null, meals: list<string>}>
     *
     * @throws ClaudeException
     */
    public function handle(User $user, ShoppingList $list, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $meals = Meal::approved()->whereBetween('date', [$from, $to])->orderBy('date')->get();

        if ($meals->isEmpty()) {
            return [];
        }

        $onList = $list->items()->whereNull('completed_at')->orderBy('position')->get();
        $sections = $list->items()->whereNotNull('section')->distinct()->orderBy('section')->pluck('section');

        $mealLines = $meals->map(fn (Meal $meal): string => "- {$meal->date->format('l j F')}, {$meal->category}: {$meal->name}"
            .($meal->description ? "\n  Ingredients: ".str_replace("\n", '; ', $meal->description) : "\n  Ingredients: not listed"));

        $result = $this->claude->ask(new ClaudeRequest(
            prompt: implode("\n\n", array_filter([
                HouseholdSetting::foodRulesPrompt(),
                "Meals to shop for:\n".$mealLines->implode("\n"),
                $onList->isNotEmpty() ? "Already on the list:\n".$onList->map(fn ($item): string => "- {$item->name}")->implode("\n") : 'The list is empty.',
                $sections->isNotEmpty() ? "The list's sections: ".$sections->implode(', ') : null,
            ])),
            feature: 'shopping-list-builder',
            user: $user,
            systemPrompt: self::System,
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'What to buy, with quantity.'],
                                'section' => ['type' => 'string'],
                                'meals' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names of the meals that need it.'],
                            ],
                            'required' => ['name', 'section', 'meals'],
                        ],
                    ],
                ],
                'required' => ['items'],
            ],
            maxBudgetUsd: 0.5,
        ));

        $alreadyOnList = $onList->map(fn ($item): string => Str::lower(trim($item->name)))->flip();
        $proposals = [];

        foreach ($result->structured['items'] ?? [] as $item) {
            $name = trim($item['name'] ?? '');

            if ($name === '' || $alreadyOnList->has(Str::lower($name))) {
                continue;
            }

            $alreadyOnList->put(Str::lower($name), true);

            $proposals[] = [
                'name' => $name,
                'section' => trim($item['section'] ?? '') ?: null,
                'meals' => array_values(array_filter(array_map('trim', $item['meals'] ?? []))),
            ];
        }

        return $proposals;
    }
}
