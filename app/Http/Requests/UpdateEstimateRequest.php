<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only drafts are editable.
        return $this->route('estimate')->status === 'draft';
    }

    public function rules(): array
    {
        return [
            'notes' => 'sometimes|nullable|string|max:5000',
            'lines' => 'sometimes|array|min:1',
            'lines.*.description' => 'required_with:lines|string|max:1000',
            'lines.*.hours' => 'required_with:lines|numeric|min:0',
            'lines.*.rate_rappen' => 'required_with:lines|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ];
    }
}
