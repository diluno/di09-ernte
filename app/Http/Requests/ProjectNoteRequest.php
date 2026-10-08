<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }   // single-user app

    public function rules(): array
    {
        return [
            'body' => 'required|string|max:20000',
        ];
    }
}
