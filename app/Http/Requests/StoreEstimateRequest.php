<?php

namespace App\Http\Requests;

use App\Support\EstimateInputRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // single-user app

    public function rules(): array
    {
        return EstimateInputRules::create($this->input('client_id'));
    }
}
