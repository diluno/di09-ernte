<?php

namespace App\Http\Requests;

use App\Models\TimeEntry;
use Illuminate\Foundation\Http\FormRequest;

class StoreEntryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'description' => 'nullable|string|max:500',
            'started_at' => 'required|date',
            'ended_at' => 'required|date|after:started_at',  // manual entries are always finished
            'billable' => 'required|boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // Defensive: even though we require ended_at, double-check no other running entry exists for this user.
            if (! $this->filled('ended_at')) {
                $running = TimeEntry::running()->where('user_id', $this->user()->id)->exists();
                if ($running) {
                    $v->errors()->add('ended_at', 'Cannot create a second running entry — stop the existing timer first.');
                }
            }
        });
    }
}
