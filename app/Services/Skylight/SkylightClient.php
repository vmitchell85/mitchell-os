<?php

namespace App\Services\Skylight;

use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpException;
use Carbon\CarbonInterface;

/**
 * Typed access to the family's Skylight frame for syncing, through the
 * Skylight MCP server's tools. No Claude involved: every call is a direct
 * tool call, so sync is predictable and free.
 */
class SkylightClient
{
    public function __construct(protected McpClient $mcp) {}

    /**
     * @return list<SkylightList>
     *
     * @throws McpException
     */
    public function lists(): array
    {
        return array_map(SkylightList::fromArray(...), $this->records('skylight_list_lists'));
    }

    /**
     * @return list<SkylightList>
     *
     * @throws McpException
     */
    public function shoppingLists(): array
    {
        return array_values(array_filter($this->lists(), fn (SkylightList $list): bool => $list->isShoppingList()));
    }

    /**
     * @return list<SkylightListItem>
     *
     * @throws McpException
     */
    public function listItems(string $listId): array
    {
        return array_map(SkylightListItem::fromArray(...), $this->records('skylight_get_list_items', ['listId' => $listId]));
    }

    /**
     * Skylight can't set a section when adding an item, so a section is applied with a second call.
     *
     * @throws McpException
     */
    public function addListItem(string $listId, string $label, ?string $section = null): SkylightListItem
    {
        $item = SkylightListItem::fromArray($this->record('skylight_add_list_item', ['listId' => $listId, 'label' => $label]));

        if ($section === null) {
            return $item;
        }

        return $this->updateListItem($listId, $item->id, ['section' => $section]);
    }

    /**
     * @param  array{label?: string, isCompleted?: bool, section?: string|null}  $changes
     *
     * @throws McpException
     */
    public function updateListItem(string $listId, string $itemId, array $changes): SkylightListItem
    {
        $arguments = ['listId' => $listId, 'itemId' => $itemId];

        if (array_key_exists('label', $changes)) {
            $arguments['label'] = $changes['label'];
        }

        if (array_key_exists('isCompleted', $changes)) {
            $arguments['checked'] = $changes['isCompleted'];
        }

        if (array_key_exists('section', $changes)) {
            $arguments['section'] = $changes['section'];
        }

        return SkylightListItem::fromArray($this->record('skylight_update_list_item', $arguments));
    }

    /**
     * @throws McpException
     */
    public function deleteListItem(string $listId, string $itemId): void
    {
        $this->mcp->callTool('skylight_delete_list_item', ['listId' => $listId, 'itemId' => $itemId]);
    }

    /**
     * @return list<SkylightMealCategory>
     *
     * @throws McpException
     */
    public function mealCategories(): array
    {
        return array_map(SkylightMealCategory::fromArray(...), $this->records('skylight_list_meal_categories'));
    }

    /**
     * @return list<SkylightMeal>
     *
     * @throws McpException
     */
    public function meals(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(SkylightMeal::fromArray(...), $this->records('skylight_list_meals', [
            'date_min' => $from->toDateString(),
            'date_max' => $to->toDateString(),
        ]));
    }

    /**
     * @throws McpException
     */
    public function planMeal(string $categoryId, CarbonInterface $date, string $name, string $description = '', string $note = ''): SkylightMeal
    {
        return SkylightMeal::fromArray($this->record('skylight_plan_meal', array_filter([
            'meal_category_id' => $categoryId,
            'date' => $date->toDateString(),
            'summary' => $name,
            'description' => $description,
            'note' => $note,
        ], fn (string $value): bool => $value !== '')));
    }

    /**
     * Change one occurrence of a meal. For a repeating meal, Skylight splits
     * this occurrence off the series rather than editing every date.
     *
     * @param  array{name?: string, description?: string, note?: string, categoryId?: string, date?: CarbonInterface}  $changes
     *
     * @throws McpException
     */
    public function updateMeal(string $mealId, CarbonInterface $occurrence, array $changes): void
    {
        $arguments = ['id' => $mealId, 'instance_date' => $occurrence->toDateString(), 'apply_to' => 'one'];

        foreach (['name' => 'summary', 'description' => 'description', 'note' => 'note', 'categoryId' => 'meal_category_id'] as $key => $argument) {
            if (array_key_exists($key, $changes)) {
                $arguments[$argument] = $changes[$key];
            }
        }

        if (isset($changes['date'])) {
            $arguments['date'] = $changes['date']->toDateString();
        }

        $this->mcp->callTool('skylight_update_meal', $arguments);
    }

    /**
     * Remove one occurrence of a meal, leaving any other dates of a repeating meal in place.
     *
     * @throws McpException
     */
    public function deleteMeal(string $mealId, CarbonInterface $occurrence): void
    {
        $this->mcp->callTool('skylight_delete_meal', [
            'id' => $mealId,
            'instance_date' => $occurrence->toDateString(),
            'apply_to' => 'one',
        ]);
    }

    /**
     * Call a tool that returns a list of records.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     *
     * @throws McpException
     */
    protected function records(string $tool, array $arguments = []): array
    {
        $result = $this->mcp->callTool($tool, $arguments);

        if (! is_array($result) || ! array_is_list($result) || array_filter($result, fn (mixed $record): bool => ! is_array($record)) !== []) {
            throw new McpException("Skylight's [{$tool}] tool returned something other than a list of records.");
        }

        return $result;
    }

    /**
     * Call a tool that returns a single record.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    protected function record(string $tool, array $arguments = []): array
    {
        $result = $this->mcp->callTool($tool, $arguments);

        if (! is_array($result) || array_is_list($result)) {
            throw new McpException("Skylight's [{$tool}] tool returned something other than a record.");
        }

        return $result;
    }
}
