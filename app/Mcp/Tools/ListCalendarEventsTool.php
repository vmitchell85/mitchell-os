<?php

namespace App\Mcp\Tools;

use App\Services\Calendar\CalendarException;
use App\Services\Calendar\HouseholdCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list-calendar-events')]
#[Description('List events between two dates from the family members\' own calendars (such as their Google calendars), each labeled with whose calendar it is. Times are the family\'s local time.')]
class ListCalendarEventsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, HouseholdCalendar $calendar): Response|ResponseFactory
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'The "to" date must be on or after the "from" date.',
        ]);

        $from = CarbonImmutable::parse($validated['from'], config('app.display_timezone'))->startOfDay();
        $to = CarbonImmutable::parse($validated['to'], config('app.display_timezone'))->endOfDay();

        if ($from->diffInDays($to) > 62) {
            return Response::error('Ask for at most two months of events at a time.');
        }

        if (! $calendar->isShared()) {
            return Response::error('The family hasn\'t shared any calendars yet. They can choose some in Settings > Calendars.');
        }

        try {
            return Response::structured(['events' => $calendar->events($from, $to)]);
        } catch (CalendarException $exception) {
            report($exception);

            return Response::error("The family's calendars couldn't be read: {$exception->getMessage()}");
        }
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('First date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('Last date, YYYY-MM-DD.')->required(),
        ];
    }
}
