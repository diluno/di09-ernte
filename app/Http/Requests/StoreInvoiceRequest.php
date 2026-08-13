<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // single-user app

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $this->input('client_id')))],
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'entry_ids' => 'array',
            'entry_ids.*' => 'integer|exists:time_entries,id',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate_rappen' => 'required|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
            'recipients' => 'sometimes|array',
            'recipients.*.name' => 'required|string|max:255',
            'recipients.*.email' => 'required|email|max:255',
        ];
    }
}
