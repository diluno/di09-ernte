<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('project')->id;
        return [
            'client_id' => 'sometimes|exists:clients,id',
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('projects', 'code')->ignore($id)],
            'description' => 'nullable|string',
            'billable' => 'sometimes|boolean',
            'retainer' => 'sometimes|boolean',
            'retainer_hours' => 'nullable|integer|min:0',
            'retainer_resets_monthly' => 'sometimes|boolean',
            'budget_hours' => 'sometimes|integer|min:0',
            'budget_amount_rappen' => 'sometimes|integer|min:0',
            'rate_rappen' => 'sometimes|integer|min:0',
            'started_on' => 'nullable|date',
            'deadline_on' => 'nullable|date',
        ];
    }
}
