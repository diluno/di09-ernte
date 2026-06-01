<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEntryRequest;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;

class EntryController extends Controller
{
    public function store(StoreEntryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $entry = TimeEntry::create([
            ...$data,
            // description is NOT NULL; a blank field arrives as null via ConvertEmptyStringsToNull.
            'description' => $data['description'] ?? '',
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['id' => $entry->id], 201);
    }
}
