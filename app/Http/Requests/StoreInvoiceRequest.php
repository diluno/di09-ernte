<?php

namespace App\Http\Requests;

use App\Support\InvoiceInputRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // single-user app

    public function rules(): array
    {
        return InvoiceInputRules::create($this->input('client_id'));
    }

    public function messages(): array
    {
        return InvoiceInputRules::messages();
    }
}
