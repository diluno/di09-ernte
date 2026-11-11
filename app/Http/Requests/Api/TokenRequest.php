<?php

namespace App\Http\Requests\Api;

use App\Http\Controllers\Api\AuthController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            // What the token may do; see AuthController::ABILITIES.
            'scope' => ['sometimes', 'string', Rule::in(array_keys(AuthController::ABILITIES))],
        ];
    }
}
