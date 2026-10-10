# Mitchell OS

Mitchell OS is the Mitchell household's private "grab bag" app (Laravel 13, Livewire 4 single-file pages, Flux UI, Pest, SQLite). It runs on a Mac at home, and several household members log in. Its core is Claude, run through the `claude -p` CLI on the Mac's signed-in subscription login (`App\Services\Claude\ClaudeCli`; every run is logged as a `ClaudeRun`, and there are no spending caps).

**Features:**
- **Chat** at /chat. Conversations are private per user, and replies run in the queued `AnswerChatMessage` job so they survive leaving the page. Chat has web search, read/add access to the family's Skylight calendar, and a local `household` MCP server (`app/Mcp`, laravel/mcp). That server gives chat the meal plan, shopping lists, recipes, household notes and shared calendars.
- **Meals** at /meals. Claude drafts a week of meals for approval. It uses the food rules, the household notes, saved recipes and the family's calendar events.
- **Shopping lists** at /lists. A shopping-list builder turns approved meals into list items.
- **Recipes** at /recipes. Add by hand, import from a link, save from a meal, or save from chat.
- **Household notes and food rules** at /household.

**Integrations:**
- **Skylight:** meals and shopping lists live locally and sync two ways with Skylight on demand (`App\Actions\Skylight`, via the pinned skylight-mcp npm package). The hourly schedule is defined, but the scheduler isn't running yet.
- **Calendars:** the family's own calendars (e.g. Google) are read from macOS Calendar through a Swift EventKit helper (`resources/native`). The user installs it with `php artisan calendar:install`.
- **API:** a versioned API at /api/v1 (Sanctum tokens from Settings → API tokens) exposes all of this to the household's own apps.

**Conventions:**
- **Time:** times are stored in UTC and shown and calculated in Eastern time (`app.display_timezone`).
- **Language:** use US English everywhere.
- **Food rules:** the household eats no seafood. Every feature that suggests food must include `HouseholdSetting::foodRulesPrompt()` and `HouseholdNote::prompt()`.
- **Commits:** go straight to `main`.
- **Runtime:** the app needs a queue worker running (`composer run dev` starts one).

@AGENTS.md
