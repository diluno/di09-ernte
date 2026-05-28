<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEntryRequest;
use App\Http\Requests\UpdateEntryRequest;
use App\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    public function store(StoreEntryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        TimeEntry::create([
            ...$data,
            // description is NOT NULL; a blank field arrives as null via ConvertEmptyStringsToNull.
            'description' => $data['description'] ?? '',
            'user_id' => $request->user()->id,
        ]);
        return back();
    }

    public function update(UpdateEntryRequest $request, TimeEntry $entry): RedirectResponse
    {
        $entry->update($request->validated());
        return back();
    }

    public function destroy(Request $request, TimeEntry $entry): RedirectResponse
    {
        abort_if($entry->user_id !== $request->user()->id, 403);
        $entry->delete();
        return back();
    }
}
