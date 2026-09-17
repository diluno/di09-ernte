<?php

use App\Mcp\Servers\ErnteServer;
use App\Mcp\Tools\CreateEstimate;
use App\Mcp\Tools\UpdateEstimate;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimateLifecycle;
use App\Services\Estimating\EstimatePdfRenderer;
use App\Support\EstimateProjections;
use App\Support\EstimateScope;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'alliance F']);
});

/** The OF-2026-003 proposal: six sections, 23 titled lines, three assumptions. */
function referenceInput(): array
{
    $ref = json_decode(file_get_contents(base_path('tests/Fixtures/estimate-reference.json')), true);
    $ref['sections'] = array_map(fn ($s) => [
        'label' => $s['label'],
        'title' => $s['title'],
        'lines' => array_map(fn ($l) => [
            'title' => $l['title'], 'description' => $l['description'],
            'hours' => $l['hours'], 'rate_rappen' => (int) round($l['rate'] * 100),
        ], $s['lines']),
    ], $ref['sections']);

    return $ref;
}

function referenceEstimate(): Estimate
{
    $ref = referenceInput();

    return app(EstimateBuilder::class)->createDraft(
        client: test()->client, project: null, title: $ref['title'],
        sections: $ref['sections'], assumptions: $ref['assumptions'],
    );
}

test('the reference estimate computes its totals and package totals from the lines', function () {
    $estimate = referenceEstimate();

    expect(EstimateScope::totalHours($estimate))->toBe(422.0)
        ->and($estimate->subtotal_rappen)->toBe(6_330_000)
        ->and($estimate->vat_rappen)->toBe(512_730)
        ->and($estimate->total_rappen)->toBe(6_842_730)
        ->and($estimate->sections)->toHaveCount(6)
        ->and($estimate->lines)->toHaveCount(23);

    $packages = collect(EstimateScope::groups($estimate))->map(fn ($g) => [$g['heading'], $g['hours'], $g['amount_rappen']])->all();
    expect($packages)->toBe([
        ['Konzeption & Projektleitung', 54.0, 810_000],
        ['Bündel 1 — Regionale Webapp', 104.0, 1_560_000],
        ['Bündel 2 — Beratungs- & Anlaufstellen', 66.0, 990_000],
        ['Bündel 3 — Steuermodul Bern & Solothurn', 76.0, 1_140_000],
        ['Bündel 4 — Kinderbetreuungskosten', 72.0, 1_080_000],
        ['QA, Launch & Reserve', 50.0, 750_000],
    ]);
});

test('updating with flat lines drops the sections, and with sections replaces them', function () {
    $estimate = referenceEstimate();
    $builder = app(EstimateBuilder::class);

    $flat = $builder->updateDraft($estimate, ['lines' => [['description' => 'Pauschal', 'hours' => 10, 'rate_rappen' => 15000]]]);
    expect($flat->sections)->toHaveCount(0)
        ->and($flat->lines)->toHaveCount(1)
        ->and($flat->lines->first()->estimate_section_id)->toBeNull()
        ->and($flat->subtotal_rappen)->toBe(150_000);

    $grouped = $builder->updateDraft($flat, ['sections' => [
        ['label' => 'A', 'lines' => [['title' => 'Eins', 'hours' => 1, 'rate_rappen' => 10000]]],
        ['label' => 'B', 'lines' => [['title' => 'Zwei', 'description' => 'Details', 'hours' => 2, 'rate_rappen' => 10000]]],
    ]]);
    expect($grouped->sections->pluck('label')->all())->toBe(['A', 'B'])
        ->and($grouped->lines->pluck('title')->all())->toBe(['Eins', 'Zwei'])
        ->and($grouped->lines->first()->description)->toBe('')
        ->and($grouped->subtotal_rappen)->toBe(30_000);
});

test('assumptions are trimmed, blank ones dropped, and an empty list stored as null', function () {
    $builder = app(EstimateBuilder::class);
    $estimate = $builder->createDraft(
        client: $this->client, project: null,
        lines: [['description' => 'X', 'hours' => 1, 'rate_rappen' => 100]],
        assumptions: ['  Erste ', '', '   ', 'Zweite'],
    );
    expect($estimate->assumptions)->toBe(['Erste', 'Zweite']);

    expect($builder->updateDraft($estimate, ['assumptions' => ['']])->assumptions)->toBeNull();
});

test('the pdf shows the summary, package overview and sections without repeating labels in line titles', function () {
    $html = app(EstimatePdfRenderer::class)->html(referenceEstimate());

    expect($html)
        ->toContain('422 h')
        ->toContain('CHF 63’300.–')
        ->toContain('CHF 68’427.30')
        ->toContain('CHF 15’600.–')
        ->toContain('<span class="sec-label">Bündel 1</span>')
        ->toContain('<span class="sec-title">Regionale Webapp</span>')
        ->toContain('Total Bündel 1')
        ->toContain('<div class="item-title">Technische Grundlage</div>')
        ->toContain('Stundensatz: <b>CHF 150.–</b>')
        ->toContain('Grundlagen der Schätzung')
        ->toContain('<div class="assumption">Anpassungen am Rechenmodell erfolgen in Abstimmung mit den wissenschaftlichen Partnern.</div>')
        ->not->toContain('Bündel 1 –')
        ->not->toContain('>Ansatz<');

    // Summary comes before the detailed scope.
    expect(strpos($html, 'Inkl. MwSt'))->toBeLessThan(strpos($html, 'Leistungen im Detail'));
});

test('the pdf keeps the rate column when lines use different rates', function () {
    $estimate = app(EstimateBuilder::class)->createDraft(client: $this->client, project: null, sections: [
        ['label' => 'Design', 'lines' => [
            ['title' => 'Konzept', 'hours' => 2, 'rate_rappen' => 16000],
            ['title' => 'Foto', 'hours' => 1, 'rate_rappen' => 12050],
        ]],
    ]);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->toContain('>Ansatz<')
        ->toContain('120.50')
        ->not->toContain('Stundensatz');
});

test('the pdf hides empty date rows instead of printing a dash', function () {
    $estimate = Estimate::factory()->create(['issued_on' => null, 'valid_until' => null]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id]);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->not->toContain('<span>Datum</span>')
        ->not->toContain('<span>Gültig bis</span>')
        ->not->toContain('<b>—</b>');
});

test('a legacy estimate without sections or titles renders as a plain list with its notes', function () {
    $estimate = Estimate::factory()->create(['notes' => 'Alte Notiz', 'subtotal_rappen' => 29000]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'description' => 'Bündel 1 – Alte Zeile', 'hours' => 2, 'rate_rappen' => 14500, 'amount_rappen' => 29000]);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->toContain('Bündel 1 – Alte Zeile')   // printed as-is, never parsed
        ->toContain('Alte Notiz')
        ->not->toContain('class="sec-head"')
        ->not->toContain('Übersicht')
        ->not->toContain('Grundlagen der Schätzung');
});

test('the web form can create an estimate with sections and assumptions', function () {
    $this->actingAs($this->user);

    $this->post('/estimates', [
        'client_id' => $this->client->id,
        'title' => 'Webapp',
        'assumptions' => ['Basis ist die bestehende Plattform.'],
        'sections' => [
            ['label' => 'Bündel 1', 'title' => 'Webapp', 'lines' => [
                ['title' => 'Technische Grundlage', 'description' => 'Setup', 'hours' => 16, 'rate_rappen' => 15000],
            ]],
        ],
    ])->assertRedirect();

    $estimate = Estimate::latest('id')->first();
    expect($estimate->sections->first()->heading())->toBe('Bündel 1 — Webapp')
        ->and($estimate->lines->first()->title)->toBe('Technische Grundlage')
        ->and($estimate->assumptions)->toBe(['Basis ist die bestehende Plattform.'])
        ->and($estimate->subtotal_rappen)->toBe(240_000);
});

test('a line needs a title or a description', function () {
    $this->actingAs($this->user);

    $this->post('/estimates', [
        'client_id' => $this->client->id,
        'sections' => [['label' => 'A', 'lines' => [['hours' => 1, 'rate_rappen' => 100]]]],
    ])->assertSessionHasErrors('sections.0.lines.0.description');

    expect(Estimate::count())->toBe(0);
});

test('create_estimate and update_estimate accept sections and assumptions', function () {
    ErnteServer::actingAs($this->user)->tool(CreateEstimate::class, [
        'client_id' => $this->client->id,
        'sections' => [
            ['label' => 'Bündel 1', 'title' => 'Webapp', 'lines' => [['title' => 'Setup', 'hours' => 16, 'rate' => 150]]],
        ],
        'assumptions' => ['Eins'],
    ])->assertOk();

    $estimate = Estimate::latest('id')->first();
    expect($estimate->sections)->toHaveCount(1)->and($estimate->assumptions)->toBe(['Eins']);

    ErnteServer::actingAs($this->user)->tool(UpdateEstimate::class, [
        'number' => $estimate->number,
        'sections' => [
            ['label' => 'A', 'lines' => [['title' => 'X', 'hours' => 1, 'rate' => 100]]],
            ['label' => 'B', 'lines' => [['description' => 'Y', 'hours' => 1, 'rate' => 100]]],
        ],
    ])->assertOk()->assertSee('"label":"B"');

    expect($estimate->fresh()->sections)->toHaveCount(2);
});

test('the detail projection exposes sections, line titles and assumptions', function () {
    $detail = EstimateProjections::detail(referenceEstimate());

    expect($detail['hours'])->toBe(422.0)
        ->and($detail['assumptions'])->toHaveCount(3)
        ->and($detail['sections'])->toHaveCount(6)
        ->and($detail['sections'][1])->toMatchArray(['label' => 'Bündel 1', 'title' => 'Regionale Webapp', 'hours' => 104.0, 'amount' => 15600.0])
        ->and($detail['lines'][2])->toMatchArray(['section' => 'Bündel 1 — Regionale Webapp', 'title' => 'Technische Grundlage']);
});

test('converting to an invoice folds line titles into the invoice description', function () {
    $estimate = app(EstimateBuilder::class)->createDraft(client: $this->client, project: null, sections: [
        ['label' => 'A', 'lines' => [
            ['title' => 'Setup', 'description' => 'Server und Domain', 'hours' => 2, 'rate_rappen' => 15000],
            ['title' => 'Reserve', 'hours' => 1, 'rate_rappen' => 15000],
        ]],
    ]);
    $estimate->update(['status' => 'accepted']);

    $invoice = app(EstimateLifecycle::class)->convertToInvoice($estimate);

    expect($invoice->lines->sortBy('sort_order')->pluck('description')->all())
        ->toBe(["**Setup**\nServer und Domain", 'Reserve']);
});
