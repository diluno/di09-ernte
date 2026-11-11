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
            // The open-ended rate that adding this one will close (see openRateToClose)
            // does not count as an overlap: the controller ends it the day before.
            $closingId = $this->openRateToClose()?->id;

            $overlaps = VatRate::query()
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->when($closingId, fn ($q) => $q->whereKeyNot($closingId))
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

    /**
     * Adding a rate that starts after the current open-ended rate (the usual "the
     * Swiss rate changes on 1 January" case) closes that rate on the day before,
     * instead of rejecting the new one as an overlap. Only when adding an
     * open-ended rate (a bounded one would leave a gap after it), and never when
     * editing: changing one row never changes another.
     */
    public function openRateToClose(): ?VatRate
    {
        if ($this->route('vatRate') || ! $this->filled('valid_from') || $this->filled('valid_until')) {
            return null;
        }

        $from = rescue(fn () => $this->date('valid_from'), null, report: false);
        if (! $from) {
            return null;
        }

        return VatRate::query()
            ->whereNull('valid_until')
            ->whereDate('valid_from', '<', $from->toDateString())
            ->orderByDesc('valid_from')
            ->first();
    }
}
