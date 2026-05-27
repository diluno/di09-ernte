<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEntryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => 'sometimes|exists:projects,id',
            'task_id' => 'sometimes|nullable|exists:tasks,id',
            'description' => 'sometimes|nullable|string|max:500',
            'started_at' => 'sometimes|date',
            'ended_at' => 'sometimes|nullable|date|after:started_at',
            'billable' => 'sometimes|boolean',
        ];
    }
}
