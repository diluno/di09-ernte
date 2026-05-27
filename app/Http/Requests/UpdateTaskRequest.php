<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'budget_hours' => 'sometimes|nullable|integer|min:0',
            'done' => 'sometimes|boolean',
        ];
    }
}
