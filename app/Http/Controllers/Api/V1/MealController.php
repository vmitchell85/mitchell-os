<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Approved meals reach Skylight at the next sync; drafts never do.
 */
class MealController extends Controller
{
    /**
     * Meals between two dates, this week by default, including drafts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($validated['from'] ?? CarbonImmutable::today()->startOfWeek());
        $to = CarbonImmutable::parse($validated['to'] ?? $from->addDays(6));

        abort_if($from->diffInDays($to) > 92, 422, 'Ask for at most 92 days of meals at a time.');

        return MealResource::collection(
            Meal::whereBetween('date', [$from->startOfDay(), $to->startOfDay()])
                ->orderBy('date')
                ->get()
                ->sortBy(fn (Meal $meal): array => [$meal->date->toDateString(), array_search($meal->category, Meal::Categories, true)])
                ->values(),
        );
    }

    /**
     * Meals added by hand are approved straight away.
     */
    public function store(Request $request): MealResource
    {
        $validated = $request->validate($this->rules());

        return new MealResource(Meal::create([
            ...$this->attributes($validated),
            'approved_at' => now(),
            'added_by' => $request->user()->id,
        ]));
    }

    public function show(Meal $meal): MealResource
    {
        return new MealResource($meal);
    }

    public function update(Request $request, Meal $meal): MealResource
    {
        $validated = $request->validate($this->rules(partial: true));

        $meal->update($this->attributes($validated));

        return new MealResource($meal);
    }

    public function destroy(Meal $meal): Response
    {
        $meal->removeFromPlan();

        return response()->noContent();
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'date' => [...$required, 'date'],
            'category' => [...$required, Rule::in(Meal::Categories)],
            'name' => [...$required, 'string', 'max:255'],
            'ingredients' => ['sometimes', 'nullable', 'array', 'max:100'],
            'ingredients.*' => ['nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function attributes(array $validated): array
    {
        $attributes = collect($validated)->only(['date', 'category', 'name', 'note'])->all();

        if (array_key_exists('note', $attributes)) {
            $attributes['note'] = $attributes['note'] ?: null;
        }

        if (array_key_exists('ingredients', $validated)) {
            $ingredients = array_filter(array_map(fn (?string $ingredient): string => trim((string) $ingredient), $validated['ingredients'] ?? []));
            $attributes['description'] = $ingredients !== [] ? implode("\n", $ingredients) : null;
        }

        return $attributes;
    }
}
