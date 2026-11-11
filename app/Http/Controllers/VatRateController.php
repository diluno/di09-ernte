<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVatRateRequest;
use App\Http\Requests\UpdateVatRateRequest;
use App\Models\VatRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $closed = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $open = $request->openRateToClose();
            $open?->update(['valid_until' => Carbon::parse($data['valid_from'])->subDay()->toDateString()]);
            VatRate::create($data);

            return $open;
        });

        $message = $closed
            ? sprintf('VAT rate added. The %s%% rate now ends on %s.', number_format((float) $closed->rate, 2), $closed->valid_until->format('d M Y'))
            : 'VAT rate added.';

        return back()->with('success', $message);
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
