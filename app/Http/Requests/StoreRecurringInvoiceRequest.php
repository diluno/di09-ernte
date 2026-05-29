<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurringInvoiceRequest extends FormRequest
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
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            'cadence' => 'required|in:monthly,quarterly,half-yearly,yearly',
            'next_run_on' => 'required|date',
            'vat_rate' => 'sometimes|numeric|min:0|max:100',
            'auto_send' => 'sometimes|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate_rappen' => 'required|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
            'lines.*.vat_code' => ['sometimes', 'nullable', 'string', Rule::exists('vat_rates', 'code')],
        ];
    }
}
