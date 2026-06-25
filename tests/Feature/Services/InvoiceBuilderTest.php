<?php

use App\Models\BusinessProfile;
use App\Models\Client;
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
    expect($invoice->total_rappen)->toBe(31350); // 31349 rounded to nearest 5
});

test('vat_rate is stamped from the dated VAT catalog at build time', function () {
    BusinessProfile::current()->update(['default_vat_rate' => 7.70]);

    makeEntry($this->user, $this->project, 'Work', 60);
    $invoice = $this->svc->buildDraftFromEntries(
        $this->client,
        $this->project,
        TimeEntry::all(),
        '2023-12-01',
        '2023-12-31'
    );

    expect((float) $invoice->vat_rate)->toBe(7.70);
    expect($invoice->vat_rappen)->toBe(1117); // 7.70% of 14500

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

test('computeTotals taxes every line at the document rate', function () {
    $totals = InvoiceBuilder::computeTotals([10000, 5000], 8.10);

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(1215);   // 8.10% of 15000
    expect($totals['total_rappen'])->toBe(16215);
});

test('computeTotals matches the original VAT formula', function () {
    $totals = InvoiceBuilder::computeTotals([29000], 8.10);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'total_rappen' => 31350, // 31349 rounded to nearest 5
    ]);
});

test('suggestLinesFromEntries groups by description and sums hours/amount', function () {
    $e1 = makeEntry($this->user, $this->project, 'PR review', 60);
    $e2 = makeEntry($this->user, $this->project, 'PR review', 30);
    makeEntry($this->user, $this->project, 'Telemetry', 120);

    $lines = $this->svc->suggestLinesFromEntries(TimeEntry::all(), $this->project);

    expect($lines)->toHaveCount(2);
    $pr = collect($lines)->firstWhere('description', 'PR review');
    expect($pr['hours'])->toBe(1.5);
    expect($pr['rate_rappen'])->toBe(14500);
    expect($pr['amount_rappen'])->toBe(21750);
    expect($pr['entry_ids'])->toEqualCanonicalizing([$e1->id, $e2->id]);
});

test('createDraft persists submitted lines and recomputes amounts', function () {
    $e = makeEntry($this->user, $this->project, 'Work', 120);

    $invoice = $this->svc->createDraft(
        client: $this->client,
        project: $this->project,
        periodStart: now()->subDays(7)->toDateString(),
        periodEnd: now()->toDateString(),
        lines: [
            ['description' => 'Consulting',     'hours' => 2.0, 'rate_rappen' => 14500],
            ['description' => 'Reimbursement',  'hours' => 1.0, 'rate_rappen' => 5000],
        ],
        entryIds: [$e->id],
    );

    expect($invoice->status)->toBe('draft');
    expect($invoice->lines)->toHaveCount(2);

    $consulting = $invoice->lines->firstWhere('description', 'Consulting');
    expect($consulting->amount_rappen)->toBe(29000);          // recomputed: 2 * 14500

    $reimb = $invoice->lines->firstWhere('description', 'Reimbursement');
    expect($reimb->amount_rappen)->toBe(5000);

    // subtotal = 34000; vat = 8.10% of 34000 = 2754; total = 36754 → rounded to 36755
    expect($invoice->subtotal_rappen)->toBe(34000);
    expect($invoice->vat_rappen)->toBe(2754);
    expect($invoice->total_rappen)->toBe(36755); // 36754 rounded to nearest 5

    expect($e->fresh()->invoice_id)->toBe($invoice->id);
    expect($invoice->qr_reference)->toMatch('/^\d{27}$/');
    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/');
    expect($invoice->events()->where('kind', 'created')->count())->toBe(1);
});

test('suggestLinesFromEntries keeps no-description no-task entries as separate lines', function () {
    $start = now()->subDays(2);
    $e1 = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => '', 'task_id' => null,
        'started_at' => $start, 'ended_at' => (clone $start)->addMinutes(60), 'billable' => true,
    ]);
    $e2 = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => '', 'task_id' => null,
        'started_at' => $start, 'ended_at' => (clone $start)->addMinutes(30), 'billable' => true,
    ]);

    $lines = $this->svc->suggestLinesFromEntries(TimeEntry::all(), $this->project);

    expect($lines)->toHaveCount(2);
    expect(collect($lines)->pluck('entry_ids')->flatten()->all())
        ->toEqualCanonicalizing([$e1->id, $e2->id]);
});

test('createDraft ignores client-submitted amount_rappen (anti-tamper)', function () {
    $invoice = $this->svc->createDraft(
        client: $this->client, project: null,
        periodStart: now()->subDay()->toDateString(), periodEnd: now()->toDateString(),
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'amount_rappen' => 999999]],
        entryIds: [],
    );
    expect($invoice->lines->first()->amount_rappen)->toBe(10000);
});

test('createDraft stamps an explicit vat rate when provided', function () {
    $invoice = $this->svc->createDraft(
        client: $this->client,
        project: null,
        periodStart: now()->subDay()->toDateString(),
        periodEnd: now()->toDateString(),
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000]],
        entryIds: [],
        title: null,
        notes: null,
        vatRate: 2.60,
    );

    expect((float) $invoice->vat_rate)->toBe(2.60);
    expect($invoice->vat_rappen)->toBe(260); // 2.60% of 10000
});
