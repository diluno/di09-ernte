<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEntryRequest;
use App\Http\Requests\UpdateEntryRequest;
use App\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;

class EntryController extends Controller
{
    public function store(StoreEntryRequest $request): RedirectResponse
    {
        TimeEntry::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);
        return back();
    }

    public function update(UpdateEntryRequest $request, TimeEntry $entry): RedirectResponse
    {
        $entry->update($request->validated());
        return back();
    }

    public function destroy(TimeEntry $entry): RedirectResponse
    {
        $entry->delete();
        return back();
    }
}
