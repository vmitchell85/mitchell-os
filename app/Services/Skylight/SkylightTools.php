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
     * Look up calendar events, chores, tasks and the family.
     *
     * @var list<string>
     */
    public const array CalendarRead = [
        'mcp__skylight__skylight_healthcheck',
        'mcp__skylight__skylight_list_frame_members',
        'mcp__skylight__skylight_resolve_member',
        'mcp__skylight__skylight_list_categories',
        'mcp__skylight__skylight_list_events',
        'mcp__skylight__skylight_get_event',
        'mcp__skylight__skylight_list_chores',
        'mcp__skylight__skylight_list_tasks',
    ];

    /**
     * Add calendar events without being able to change or remove existing ones.
     *
     * @var list<string>
     */
    public const array CalendarAdd = [
        'mcp__skylight__skylight_create_event',
    ];
}
