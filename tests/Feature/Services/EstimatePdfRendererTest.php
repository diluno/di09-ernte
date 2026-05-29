<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Services\Estimating\EstimatePdfRenderer;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich',
    ]);
});

test('html renders the estimate number, client, lines and the Offerte heading', function () {
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    $estimate = Estimate::factory()->create([
        'client_id' => $client->id,
        'number' => 'OF-2026-001',
        'valid_until' => now()->addDays(30)->toDateString(),
        'subtotal_rappen' => 29000, 'vat_rappen' => 2349, 'total_rappen' => 31349,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'description' => 'Design phase']);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->toContain('OF-2026-001');
    expect($html)->toContain('Atlas Robotics');
    expect($html)->toContain('Design phase');
    expect($html)->toContain('Offerte');
    expect($html)->toContain('Gültig bis');
    expect($html)->not->toContain('Fällig'); // no due date / no payment slip
});

test('html renders the document title when set', function () {
    $estimate = Estimate::factory()->create(['title' => 'Partnerschaft auf Augenhöhe']);

    expect(app(EstimatePdfRenderer::class)->html($estimate))
        ->toContain('Partnerschaft auf Augenhöhe');
});

test('html renders markdown in the notes (lists, rules, emphasis, line breaks)', function () {
    $estimate = Estimate::factory()->create([
        'notes' => "Liebe Aline\nBesten Dank.\n\n---\n\nDie Website umfasst:\n\n- Informationsbereich\n- Checklisten-System\n\nStundensatz: **CHF 160.–**",
    ]);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->toContain('<hr');
    expect($html)->toContain('<li>Informationsbereich</li>');
    expect($html)->toContain('<strong>CHF 160.–</strong>');
    expect($html)->toContain('Liebe Aline<br>');
});

test('html renders markdown and line breaks in a line description', function () {
    $estimate = Estimate::factory()->create();
    EstimateLine::factory()->create([
        'estimate_id' => $estimate->id,
        'description' => "Design phase\nincluding:\n\n- wireframes\n- **hi-fi** mockups",
    ]);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->toContain('Design phase<br>');
    expect($html)->toContain('<li>wireframes</li>');
    expect($html)->toContain('<strong>hi-fi</strong>');
});

test('pdf caches the file on disk and stamps pdf_path', function () {
    $estimate = Estimate::factory()->create(['number' => 'OF-2026-009']);

    $path = app(EstimatePdfRenderer::class)->pdf($estimate);

    expect($path)->toBe('estimates/OF-2026-009.pdf');
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists($path))->toBeTrue();
    expect($estimate->fresh()->pdf_path)->toBe('estimates/OF-2026-009.pdf');
})->group('browsershot');
