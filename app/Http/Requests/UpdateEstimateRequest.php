<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only drafts are editable.
        return $this->route('estimate')->status === 'draft';
    }

    public function rules(): array
    {
        // A submitted project must belong to whichever client the estimate will have:
        // the one being set in this request, or the estimate's current client.
        $clientId = $this->input('client_id', $this->route('estimate')->client_id);

        return [
            'client_id' => 'sometimes|required|exists:clients,id',
            'project_id' => ['sometimes', 'nullable', Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $clientId))],
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            'lines' => 'sometimes|array|min:1',
            'lines.*.description' => 'required_with:lines|string|max:1000',
            'lines.*.hours' => 'required_with:lines|numeric|min:0',
            'lines.*.rate_rappen' => 'required_with:lines|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
            'lines.*.vat_code' => ['sometimes', 'nullable', 'string', Rule::exists('vat_rates', 'code')],
        ];
    }
}
