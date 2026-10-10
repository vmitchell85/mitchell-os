<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HouseholdNoteResource;
use App\Models\HouseholdNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Notes Claude always keeps in mind about the household.
 */
class HouseholdNoteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return HouseholdNoteResource::collection(HouseholdNote::oldest('id')->get());
    }

    public function store(Request $request): HouseholdNoteResource
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        return new HouseholdNoteResource(HouseholdNote::create([
            'body' => trim($validated['body']),
            'added_by' => $request->user()->id,
        ]));
    }

    public function update(Request $request, HouseholdNote $note): HouseholdNoteResource
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        $note->update(['body' => trim($validated['body'])]);

        return new HouseholdNoteResource($note);
    }

    public function destroy(HouseholdNote $note): Response
    {
        $note->delete();

        return response()->noContent();
    }
}
