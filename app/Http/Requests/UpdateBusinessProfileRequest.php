<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:32',
            'city' => 'nullable|string|max:255',
            'country' => 'required|string|size:2',
            'uid' => 'nullable|string|max:64',
            'vat_id' => 'nullable|string|max:64',
            'iban' => 'nullable|string|max:64',
            'qr_iban' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:255',
            'logo_path' => 'nullable|string|max:255',
            'default_currency' => 'required|in:CHF',
            'default_vat_rate' => 'required|numeric|min:0|max:100',
            'invoice_number_prefix' => 'nullable|string|max:20',
            'reminder_days_after_due' => 'required|integer|min:1|max:60',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('country')) {
            $this->merge(['country' => strtoupper((string) $this->input('country'))]);
        }
    }
}
