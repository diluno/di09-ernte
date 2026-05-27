<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('entry')->user_id === $this->user()->id;
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
