<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreEntryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    // The browser sends true UTC instants (toISOString → ...Z), but the DATETIME
    // columns store app-local (Europe/Zurich) wall-clock. Convert the instant to the
    // app timezone before storing so the offset isn't stripped — otherwise edit-save
    // round-trips shift the time back by the UTC offset.
    protected function prepareForValidation(): void
    {
        foreach (['started_at', 'ended_at'] as $field) {
            if ($this->filled($field)) {
                $this->merge([
                    $field => Carbon::parse($this->input($field))
                        ->setTimezone(config('app.timezone'))
                        ->format('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where(fn ($q) => $q->where('project_id', $this->integer('project_id'))),
            ],
            'description' => 'nullable|string|max:500',
            'started_at' => 'required|date',
            'ended_at' => 'required|date|after:started_at',  // manual entries are always finished
            'billable' => 'required|boolean',
        ];
    }
}
