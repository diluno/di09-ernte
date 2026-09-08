<?php

namespace App\Http\Requests;

use App\Support\EstimateInputRules;
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
        // A submitted project must belong to whichever client the estimate will have:
        // the one being set in this request, or the estimate's current client.
        $clientId = $this->input('client_id', $this->route('estimate')->client_id);

        return EstimateInputRules::update($clientId);
    }
}
