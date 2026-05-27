<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEntryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

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
