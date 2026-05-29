<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVatRateRequest;
use App\Http\Requests\UpdateVatRateRequest;
use App\Models\VatRate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class VatRateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/VatRates', [
            'rates' => VatRate::query()
                ->orderByDesc('valid_from')
                ->get(['id', 'rate', 'valid_from', 'valid_until'])
                ->map(fn (VatRate $r) => [
                    'id' => $r->id,
                    'rate' => (float) $r->rate,
                    'valid_from' => $r->valid_from->toDateString(),
                    'valid_until' => $r->valid_until?->toDateString(),
                ]),
        ]);
    }

    public function store(StoreVatRateRequest $request): RedirectResponse
    {
        VatRate::create($request->validated());

        return back()->with('success', 'VAT rate added.');
    }

    public function update(UpdateVatRateRequest $request, VatRate $vatRate): RedirectResponse
    {
        $vatRate->update($request->validated());

        return back()->with('success', 'VAT rate updated.');
    }

    public function destroy(VatRate $vatRate): RedirectResponse
    {
        $vatRate->delete();

        return back()->with('success', 'VAT rate removed.');
    }
}
