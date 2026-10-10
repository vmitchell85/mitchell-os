<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddShoppingItemsTool;
use App\Mcp\Tools\GetShoppingListTool;
use App\Mcp\Tools\ListCalendarEventsTool;
use App\Mcp\Tools\ListMealsTool;
use App\Mcp\Tools\ListShoppingListsTool;
use App\Mcp\Tools\SuggestMealTool;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The household's meal plan, shopping lists and shared calendars, for Claude in chat. The chat
 * starts this server through the claude CLI and names the household member
 * it's talking to in HOUSEHOLD_USER_ID, so anything added is attributed.
 */
#[Name('Household')]
#[Version('1.0.0')]
#[Instructions('The family\'s meal plan, shopping lists and the calendars they share. Meals you suggest are drafts that the family approves on the Meals page; items you add to a shopping list are added straight away and reach Skylight at the next sync.')]
class HouseholdServer extends Server
{
    public const string UserEnvironmentVariable = 'HOUSEHOLD_USER_ID';

    /**
     * Every tool, as the claude CLI names them, for allowing in a ClaudeRequest.
     *
     * @var list<string>
     */
    public const array ClaudeTools = [
        'mcp__household__list-meals',
        'mcp__household__suggest-meal',
        'mcp__household__list-shopping-lists',
        'mcp__household__get-shopping-list',
        'mcp__household__add-shopping-items',
        'mcp__household__list-calendar-events',
    ];

    protected array $tools = [
        ListMealsTool::class,
        SuggestMealTool::class,
        ListShoppingListsTool::class,
        GetShoppingListTool::class,
        AddShoppingItemsTool::class,
        ListCalendarEventsTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];

    protected function boot(): void
    {
        $userId = getenv(self::UserEnvironmentVariable);

        if ($userId !== false && $user = User::find((int) $userId)) {
            Auth::setUser($user);
        }
    }
}
