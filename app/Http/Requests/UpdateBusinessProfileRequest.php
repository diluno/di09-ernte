<?php

namespace App\Http\Requests;

use App\Support\SwissIban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

        foreach (['iban', 'qr_iban'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => SwissIban::normalize($this->input($field))]);
            }
        }
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $qrIban = $this->input('qr_iban');

                if ($qrIban && ! SwissIban::isQrIban($qrIban)) {
                    $validator->errors()->add(
                        'qr_iban',
                        'The QR-IBAN must use IID 30000-31999. Put a normal IBAN in the IBAN field.',
                    );
                }
            },
        ];
    }
}
