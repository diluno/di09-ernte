<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DraftEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'brief' => ['required', 'string', 'min:10', 'max:8000'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'brief.min' => 'Describe the job in a bit more detail so the draft has something to work with.',
        ];
    }
}
