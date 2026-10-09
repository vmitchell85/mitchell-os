<?php

namespace Tests\Fakes;

use App\Services\Mcp\McpClient;
use LogicException;

/**
 * An in-memory Skylight frame that answers the Skylight MCP server's list
 * tools the way the real server does, so sync can be tested end to end.
 */
class FakeSkylight implements McpClient
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $lists = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $items = [];

    /**
     * Every write tool call, in order.
     *
     * @var list<array{name: string, arguments: array<string, mixed>}>
     */
    public array $writes = [];

    /**
     * Meal sittings by id; a repeating one lists every date it occurs on.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $meals = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $mealCategories = [
        '501' => ['id' => '501', 'type' => 'meal_category', 'label' => 'Breakfast', 'enabled' => true, 'position' => 1, 'color' => '#FDC36D', 'icon' => 'meals'],
        '502' => ['id' => '502', 'type' => 'meal_category', 'label' => 'Lunch', 'enabled' => true, 'position' => 2, 'color' => '#FDC36D', 'icon' => 'meals'],
        '503' => ['id' => '503', 'type' => 'meal_category', 'label' => 'Dinner', 'enabled' => true, 'position' => 3, 'color' => '#FDC36D', 'icon' => 'meals'],
        '504' => ['id' => '504', 'type' => 'meal_category', 'label' => 'Sides', 'enabled' => false, 'position' => 4, 'color' => '#FDC36D', 'icon' => 'meals'],
    ];

    protected int $nextId = 50000;

    public function addList(string $id, string $label, string $kind = 'shopping', bool $isDefault = false): static
    {
        $this->lists[$id] = [
            'id' => $id, 'type' => 'list', 'label' => $label, 'color' => '#42D792', 'kind' => $kind,
            'default_grocery_list' => $isDefault, 'hide_on_device' => false, 'draft' => false,
        ];
        $this->items[$id] = [];

        return $this;
    }

    public function addItem(string $listId, string $label, bool $completed = false, ?string $section = null, ?string $id = null): string
    {
        $id ??= (string) $this->nextId++;

        $this->items[$listId][$id] = [
            'id' => $id, 'type' => 'list_item', 'label' => $label, 'status' => $completed ? 'completed' : 'pending',
            'section' => $section, 'position' => count($this->items[$listId]) + 1, 'created_at' => '2026-10-01T08:00:00.000Z', 'draft' => false,
        ];

        return $id;
    }

    /**
     * @param  list<string>  $dates
     */
    public function addMeal(string $name, array $dates, string $category = 'Dinner', bool $repeats = false, string $description = '', string $note = '', ?string $id = null): string
    {
        $id ??= (string) $this->nextId++;

        $this->meals[$id] = [
            'id' => $id, 'type' => 'meal_sitting', 'summary' => $name, 'description' => $description, 'note' => $note,
            'rrule' => $repeats ? 'FREQ=WEEKLY;INTERVAL=1' : null, 'recurring' => $repeats, 'draft' => false,
            'instances' => $dates, 'meal_category' => $this->categoryNamed($category), 'profiles' => [],
        ];

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function item(string $listId, string $itemId): ?array
    {
        return $this->items[$listId][$itemId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function writtenTools(): array
    {
        return array_column($this->writes, 'name');
    }

    public function callTool(string $name, array $arguments = []): mixed
    {
        if (! in_array($name, ['skylight_list_lists', 'skylight_get_list_items', 'skylight_list_meals', 'skylight_list_meal_categories'], true)) {
            $this->writes[] = ['name' => $name, 'arguments' => $arguments];
        }

        return match ($name) {
            'skylight_list_lists' => array_values($this->lists),
            'skylight_create_list' => $this->createList($arguments),
            'skylight_update_list' => $this->renameList($arguments),
            'skylight_list_meal_categories' => array_values($this->mealCategories),
            'skylight_list_meals' => $this->mealsBetween($arguments['date_min'], $arguments['date_max']),
            'skylight_plan_meal' => $this->meals[$this->addMeal($arguments['summary'], [$arguments['date']], $this->mealCategories[$arguments['meal_category_id']]['label'], description: $arguments['description'] ?? '', note: $arguments['note'] ?? '')],
            'skylight_update_meal' => $this->updateMeal($arguments),
            'skylight_delete_meal' => $this->deleteMeal($arguments),
            'skylight_get_list_items' => array_values($this->items[$arguments['listId']]),
            'skylight_add_list_item' => $this->item($arguments['listId'], $this->addItem($arguments['listId'], $arguments['label'])),
            'skylight_update_list_item' => $this->updateItem($arguments),
            'skylight_delete_list_item' => $this->deleteItem($arguments),
            default => throw new LogicException("FakeSkylight does not support the [{$name}] tool."),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function createList(array $arguments): array
    {
        $id = (string) $this->nextId++;
        $this->addList($id, $arguments['label'], $arguments['kind']);
        $this->lists[$id]['color'] = $arguments['color'];

        return $this->lists[$id];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function renameList(array $arguments): array
    {
        $this->lists[$arguments['listId']]['label'] = $arguments['label'];

        return $this->lists[$arguments['listId']];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function updateItem(array $arguments): array
    {
        $item = &$this->items[$arguments['listId']][$arguments['itemId']];

        if (array_key_exists('label', $arguments)) {
            $item['label'] = $arguments['label'];
        }

        if (array_key_exists('checked', $arguments)) {
            $item['status'] = $arguments['checked'] ? 'completed' : 'pending';
        }

        if (array_key_exists('section', $arguments)) {
            $item['section'] = $arguments['section'];
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{deleted: string}
     */
    protected function deleteItem(array $arguments): array
    {
        unset($this->items[$arguments['listId']][$arguments['itemId']]);

        return ['deleted' => $arguments['itemId']];
    }

    /**
     * @return array<string, mixed>
     */
    protected function categoryNamed(string $label): array
    {
        foreach ($this->mealCategories as $category) {
            if ($category['label'] === $label) {
                return $category;
            }
        }

        throw new LogicException("FakeSkylight has no [{$label}] meal category.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mealsBetween(string $from, string $to): array
    {
        $meals = [];

        foreach ($this->meals as $meal) {
            $dates = array_values(array_filter($meal['instances'], fn (string $date): bool => $date >= $from && $date <= $to));

            if ($dates !== []) {
                $meals[] = [...$meal, 'instances' => $dates];
            }
        }

        return $meals;
    }

    /**
     * Like Skylight, changing one date of a repeating meal splits it off into a meal of its own.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function updateMeal(array $arguments): array
    {
        $meal = $this->meals[$arguments['id']];
        $changes = array_filter([
            'summary' => $arguments['summary'] ?? null,
            'description' => $arguments['description'] ?? null,
            'note' => $arguments['note'] ?? null,
        ], fn (?string $value): bool => $value !== null);
        $category = isset($arguments['meal_category_id']) ? $this->mealCategories[$arguments['meal_category_id']] : $meal['meal_category'];
        $date = $arguments['date'] ?? $arguments['instance_date'];

        if ($meal['recurring']) {
            $this->meals[$arguments['id']]['instances'] = array_values(array_diff($meal['instances'], [$arguments['instance_date']]));
            $newId = $this->addMeal($changes['summary'] ?? $meal['summary'], [$date], $category['label'], description: $changes['description'] ?? $meal['description'], note: $changes['note'] ?? $meal['note']);

            return $this->meals[$newId];
        }

        $this->meals[$arguments['id']] = [...$meal, ...$changes, 'meal_category' => $category, 'instances' => [$date]];

        return $this->meals[$arguments['id']];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{deleted: string}
     */
    protected function deleteMeal(array $arguments): array
    {
        $remaining = array_values(array_diff($this->meals[$arguments['id']]['instances'], [$arguments['instance_date']]));

        if ($remaining === []) {
            unset($this->meals[$arguments['id']]);
        } else {
            $this->meals[$arguments['id']]['instances'] = $remaining;
        }

        return ['deleted' => $arguments['id']];
    }
}
