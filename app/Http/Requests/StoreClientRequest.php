<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'short_code' => 'required|string|max:4|unique:clients,short_code',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'country' => 'required|string|size:2',
            'vat_id' => 'nullable|string|max:64',
            'default_rate_rappen' => 'nullable|integer|min:0',
        ];
    }
}
