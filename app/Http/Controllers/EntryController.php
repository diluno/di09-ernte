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
        if ($locked = $this->rejectIfInvoiced($entry)) {
            return $locked;
        }

        $data = $request->validated();
        // description is NOT NULL; a blank field arrives as null via ConvertEmptyStringsToNull.
        if (array_key_exists('description', $data)) {
            $data['description'] = $data['description'] ?? '';
        }
        $entry->update($data);
        return back();
    }

    public function destroy(Request $request, TimeEntry $entry): RedirectResponse
    {
        abort_if($entry->user_id !== $request->user()->id, 403);

        if ($locked = $this->rejectIfInvoiced($entry)) {
            return $locked;
        }

        $entry->delete();
        return back();
    }

    /**
     * Time on an issued invoice is part of that document: changing or deleting it
     * would make the invoice disagree with the time behind it. Entries on a draft
     * invoice stay editable. Reported both as a validation error (`entry`) and a
     * flash message, since deletes are not sent from a form.
     */
    private function rejectIfInvoiced(TimeEntry $entry): ?RedirectResponse
    {
        if (! $entry->isLockedByInvoice()) {
            return null;
        }

        $message = "This entry is billed on invoice {$entry->invoice->number} and can no longer be changed. Void the invoice to release its time.";

        return back()->withErrors(['entry' => $message])->with('error', $message);
    }
}
