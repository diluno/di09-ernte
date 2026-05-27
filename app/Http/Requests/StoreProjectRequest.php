<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }   // single-user app

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:32|unique:projects,code',
            'description' => 'nullable|string',
            'glyph' => 'required|in:alt-0,alt-1,alt-2,alt-3,alt-4',
            'billable' => 'required|boolean',
            'retainer' => 'sometimes|boolean',
            'retainer_hours' => 'nullable|integer|min:0',
            'retainer_resets_monthly' => 'sometimes|boolean',
            'budget_hours' => 'required|integer|min:0',
            'budget_amount_rappen' => 'required|integer|min:0',
            'rate_rappen' => 'required|integer|min:0',
            'started_on' => 'nullable|date',
            'deadline_on' => 'nullable|date|after_or_equal:started_on',
        ];
    }
}
