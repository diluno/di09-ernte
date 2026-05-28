<?php

use App\Mail\EstimateMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use Illuminate\Support\Facades\Storage;

test('estimate mail renders details and attaches the pdf path', function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'email' => 'offers@ernte.test',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);

    $client = Client::factory()->create(['contact_name' => 'Mira Okafor']);
    $estimate = Estimate::factory()->create([
        'client_id' => $client->id,
        'number' => 'OF-2026-014',
        'status' => 'sent',
        'valid_until' => now()->addDays(30)->toDateString(),
        'total_rappen' => 123450,
    ]);

    Storage::disk('local')->put('estimates/OF-2026-014.pdf', '%PDF-test');

    $mail = new EstimateMail($estimate, 'estimates/OF-2026-014.pdf');
    $html = $mail->render();

    expect($html)->toContain('Mira Okafor');
    expect($html)->toContain('OF-2026-014');
    expect($html)->toContain("CHF 1'234.50");
    expect($mail->pdfPath)->toBe('estimates/OF-2026-014.pdf');
});
