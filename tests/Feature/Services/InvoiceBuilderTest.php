<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;

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
        'rate_rappen' => 14500, // 145 CHF/h
    ]);
    $this->svc = app(InvoiceBuilder::class);
});

function makeEntry(User $user, Project $project, string $desc, int $minutes, bool $billable = true): TimeEntry
{
    $start = now()->subDays(2);
    return TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'description' => $desc,
        'started_at' => $start,
        'ended_at' => (clone $start)->addMinutes($minutes),
        'billable' => $billable,
    ]);
}

test('builds a draft invoice with one line per distinct description', function () {
    $e1 = makeEntry($this->user, $this->project, 'PR review', 60);
    $e2 = makeEntry($this->user, $this->project, 'PR review', 30);
    $e3 = makeEntry($this->user, $this->project, 'Telemetry', 120);

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client,
        $this->project,
        TimeEntry::all(),
        now()->subDays(7)->toDateString(),
        now()->toDateString()
    );

    expect($invoice)->status->toBe('draft');
    expect($invoice->lines)->toHaveCount(2);

    $pr = $invoice->lines->firstWhere('description', 'PR review');
    expect((float) $pr->hours)->toBe(1.5);
    expect($pr->rate_rappen)->toBe(14500);
    expect($pr->amount_rappen)->toBe(21750);

    $tel = $invoice->lines->firstWhere('description', 'Telemetry');
    expect((float) $tel->hours)->toBe(2.0);
    expect($tel->amount_rappen)->toBe(29000);
});

test('subtotal/vat/total are computed from line amounts', function () {
    makeEntry($this->user, $this->project, 'A', 60);
    makeEntry($this->user, $this->project, 'B', 60);

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->subtotal_rappen)->toBe(29000);
    expect($invoice->vat_rappen)->toBe(2349);  // 29000 * 8.10% = 2349
    expect($invoice->total_rappen)->toBe(31349);
});

test('vat_rate is stamped from business_profile at build time', function () {
    BusinessProfile::current()->update(['default_vat_rate' => 7.70]);

    makeEntry($this->user, $this->project, 'Work', 60);
    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect((float) $invoice->vat_rate)->toBe(7.70);

    BusinessProfile::current()->update(['default_vat_rate' => 8.10]);
    expect((float) $invoice->fresh()->vat_rate)->toBe(7.70);
});

test('selected entries are attached to the new invoice', function () {
    $e = makeEntry($this->user, $this->project, 'Work', 60);

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, collect([$e]),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($e->fresh()->invoice_id)->toBe($invoice->id);
});

test('a created invoice_event is written', function () {
    makeEntry($this->user, $this->project, 'Work', 60);
    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->events()->where('kind', 'created')->count())->toBe(1);
});

test('non-billable entries are excluded from line grouping', function () {
    makeEntry($this->user, $this->project, 'Billable', 60, true);
    makeEntry($this->user, $this->project, 'Internal', 60, false);

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->description)->toBe('Billable');
});

test('a number is allocated via InvoiceNumberer', function () {
    makeEntry($this->user, $this->project, 'Work', 60);
    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/');
});

test('computeTotals respects vat_exempt lines', function () {
    $totals = \App\Services\Invoicing\InvoiceBuilder::computeTotals(
        lineAmounts: [10000, 5000],
        vatExempts: [false, true],
        vatRate: 8.10,
    );

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(810);    // 8.10% of 10000 only
    expect($totals['total_rappen'])->toBe(15810);
});

test('computeTotals with all non-exempt matches original VAT formula', function () {
    $totals = \App\Services\Invoicing\InvoiceBuilder::computeTotals(
        lineAmounts: [29000],
        vatExempts: [false],
        vatRate: 8.10,
    );

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen'      => 2349,
        'total_rappen'    => 31349,
    ]);
});
