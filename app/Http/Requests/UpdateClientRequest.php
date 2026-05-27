<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('client')->id;
        return [
            'name' => 'sometimes|string|max:255',
            'short_code' => ['sometimes', 'string', 'max:4', Rule::unique('clients', 'short_code')->ignore($id)],
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'country' => 'sometimes|string|size:2',
            'vat_id' => 'nullable|string|max:64',
            'default_rate_rappen' => 'nullable|integer|min:0',
        ];
    }
}
