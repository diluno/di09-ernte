<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBusinessProfileRequest;
use App\Models\BusinessProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Settings/Profile', [
            'profile' => BusinessProfile::current()->only([
                'name', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
                'uid', 'vat_id', 'iban', 'qr_iban', 'email', 'logo_path',
                'default_currency', 'default_vat_rate', 'invoice_number_prefix', 'reminder_days_after_due',
            ]),
        ]);
    }

    public function updateProfile(UpdateBusinessProfileRequest $request): RedirectResponse
    {
        BusinessProfile::current()->update($request->validated());

        return back()->with('success', 'Business profile updated.');
    }
}
