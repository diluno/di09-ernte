<?php

namespace App\Http\Requests;

use App\Models\VatRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreVatRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // single-user app
    }

    public function rules(): array
    {
        return [
            'rate' => 'required|numeric|min:0|max:100',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->date('valid_from')->toDateString();
            $until = $this->filled('valid_until') ? $this->date('valid_until')->toDateString() : null;
            $ignoreId = $this->route('vatRate')?->id;

            $overlaps = VatRate::query()
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->whereDate('valid_from', '<=', $until ?? '9999-12-31')
                ->where(function ($q) use ($from) {
                    $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from);
                })
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('valid_from', 'This validity period overlaps an existing VAT rate.');
            }
        });
    }
}
