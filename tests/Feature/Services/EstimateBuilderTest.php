<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
        'billable' => true,
        'rate_rappen' => 14500,
    ]);
    $this->svc = app(EstimateBuilder::class);
});

test('createDraft persists lines, recomputes amounts, and computes totals', function () {
    $estimate = $this->svc->createDraft(
        client: $this->client,
        project: $this->project,
        lines: [
            ['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false],
            ['description' => 'Travel',       'hours' => 1.0, 'rate_rappen' => 5000,  'vat_exempt' => true],
        ],
        notes: 'Valid for 30 days.',
    );

    expect($estimate->status)->toBe('draft');
    expect($estimate->lines)->toHaveCount(2);

    $design = $estimate->lines->firstWhere('description', 'Design phase');
    expect($design->amount_rappen)->toBe(29000);  // recomputed: 2 * 14500
    expect($design->vat_exempt)->toBeFalse();

    $travel = $estimate->lines->firstWhere('description', 'Travel');
    expect($travel->amount_rappen)->toBe(5000);
    expect($travel->vat_exempt)->toBeTrue();

    // subtotal = 34000; vat = 8.10% of taxable 29000 = 2349; total = 36349
    expect($estimate->subtotal_rappen)->toBe(34000);
    expect($estimate->vat_rappen)->toBe(2349);
    expect($estimate->total_rappen)->toBe(36349);
    expect($estimate->notes)->toBe('Valid for 30 days.');
});

test('createDraft allocates a number via EstimateNumberer and writes a created event', function () {
    $estimate = $this->svc->createDraft(
        client: $this->client, project: null,
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false]],
    );

    expect($estimate->number)->toMatch('/^OF-\d{4}-\d{3}$/');
    expect($estimate->events()->where('kind', 'created')->count())->toBe(1);
});

test('createDraft stamps vat_rate from the dated catalog and currency from the business profile', function () {
    BusinessProfile::current()->update(['default_vat_rate' => 7.70]);

    $estimate = $this->svc->createDraft(
        client: $this->client, project: null,
        lines: [['description' => 'Work', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false]],
        taxDate: '2023-12-31',
    );

    expect((float) $estimate->vat_rate)->toBe(7.70);
    expect((float) $estimate->lines->first()->vat_rate)->toBe(7.70);
    expect($estimate->vat_rappen)->toBe(770);
    expect($estimate->currency)->toBe('CHF');
});

test('createDraft ignores any client-submitted amount_rappen (anti-tamper)', function () {
    $estimate = $this->svc->createDraft(
        client: $this->client, project: null,
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false, 'amount_rappen' => 999999]],
    );

    expect($estimate->lines->first()->amount_rappen)->toBe(10000);
});
