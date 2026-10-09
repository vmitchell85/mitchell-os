<?php

namespace App\Services\Skylight;

/**
 * Allow-lists of Skylight MCP tools, so each feature gets only the access it
 * needs. Tools that aren't allowed are refused by the CLI, even if Claude is
 * talked into calling them (for example by a web page during a search).
 */
final class SkylightTools
{
    /**
     * Look up calendar events, lists, meals and the family.
     *
     * @var list<string>
     */
    public const array Read = [
        'mcp__skylight__skylight_healthcheck',
        'mcp__skylight__skylight_list_frame_members',
        'mcp__skylight__skylight_resolve_member',
        'mcp__skylight__skylight_list_categories',
        'mcp__skylight__skylight_list_events',
        'mcp__skylight__skylight_get_event',
        'mcp__skylight__skylight_list_lists',
        'mcp__skylight__skylight_get_list_items',
        'mcp__skylight__skylight_list_chores',
        'mcp__skylight__skylight_list_tasks',
        'mcp__skylight__skylight_list_meals',
        'mcp__skylight__skylight_list_meal_categories',
        'mcp__skylight__skylight_list_recipes',
        'mcp__skylight__skylight_get_recipe',
    ];

    /**
     * Add things without being able to change or remove what's already there.
     *
     * @var list<string>
     */
    public const array Add = [
        'mcp__skylight__skylight_create_event',
        'mcp__skylight__skylight_add_list_item',
        'mcp__skylight__skylight_plan_meal',
        'mcp__skylight__skylight_add_recipe_to_grocery_list',
    ];
}
