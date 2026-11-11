<?php

namespace App\Http\Requests;

use App\Support\InvoiceInputRules;
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
        return InvoiceInputRules::update();
    }
}
