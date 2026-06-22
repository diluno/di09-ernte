<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only drafts are editable.
        return $this->route('invoice')->status === 'draft';
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            'period_start' => 'sometimes|nullable|date',
            'period_end' => 'sometimes|nullable|date|after_or_equal:period_start',
            'lines' => 'sometimes|array|min:1',
            'lines.*.description' => 'required_with:lines|string|max:1000',
            'lines.*.hours' => 'required_with:lines|numeric|min:0',
            'lines.*.rate_rappen' => 'required_with:lines|integer|min:0',
        ];
    }
}
