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
        if (! in_array($name, ['skylight_list_lists', 'skylight_get_list_items'], true)) {
            $this->writes[] = ['name' => $name, 'arguments' => $arguments];
        }

        return match ($name) {
            'skylight_list_lists' => array_values($this->lists),
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
}
