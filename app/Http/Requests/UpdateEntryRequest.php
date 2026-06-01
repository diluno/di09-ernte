<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UpdateEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('entry')->user_id === $this->user()->id;
    }

    // Convert incoming UTC instants to the app timezone before storing — see the note
    // on StoreEntryRequest. Without this, editing an entry shifts its time back by the
    // UTC offset on every save.
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
        $taskExists = Rule::exists('tasks', 'id');
        if ($this->filled('project_id')) {
            $taskExists = $taskExists->where(fn ($q) => $q->where('project_id', $this->integer('project_id')));
        }

        return [
            'project_id' => 'sometimes|exists:projects,id',
            'task_id' => ['sometimes', 'nullable', 'integer', $taskExists],
            'description' => 'sometimes|nullable|string|max:500',
            'started_at' => 'sometimes|date',
            'ended_at' => 'sometimes|nullable|date|after:started_at',
            'billable' => 'sometimes|boolean',
        ];
    }
}
