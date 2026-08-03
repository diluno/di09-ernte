<?php

use App\Mail\InvoiceMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoicePdfRenderer;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
});

test('GET /invoices renders Invoices/Index with rows + stats + counts', function () {
    // Use non-round rappen/hours so JSON serialisation preserves float type
    $inv = Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2026-001',
        'status' => 'sent', 'due_on' => now()->addDays(10)->toDateString(), 'total_rappen' => 100_50]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 5.5]);

    $this->get('/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->where('invoices.total', 1)
            ->where('invoices.per_page', 50)
            ->has('invoices.data', 1, fn (Assert $r) => $r
                ->where('number', '2026-001')
                ->where('client.name', 'Atlas Robotics')
                ->where('total', 100.5)
                ->where('hours', 5.5)
                ->where('status', 'sent')
                ->where('overdue', false)
                ->etc())
            ->has('stats', fn (Assert $s) => $s
                ->where('outstanding', 100.5)
                ->has('overdue')->has('paid_ytd')->has('avg_days_to_pay')->etc())
            ->has('counts', fn (Assert $c) => $c
                ->where('all', 1)->has('draft')->has('sent')->has('overdue')->has('paid')->etc())
            ->where('filters.filter', 'all'));
});

test('GET /invoices passes an invoiceChart prop for the current year', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => now()->startOfYear()->toDateString(), 'total_rappen' => 12_000_00]);

    $this->get('/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->where('invoiceChart.year', (int) now()->year)
            ->where('invoiceChart.max_year', (int) now()->year)
            ->has('invoiceChart.months', 12)
            ->etc()
        );
});

test('GET /invoices?chart_year=2025 returns chart data for that year', function () {
    $this->get('/invoices?chart_year=2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->where('invoiceChart.year', 2025)
            ->etc()
        );
});

test('GET /invoices?filter=overdue narrows to past-due sent invoices', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'due_on' => now()->subDay()->toDateString()]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'due_on' => now()->addDay()->toDateString()]);

    $this->get('/invoices?filter=overdue')
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
});

test('GET /invoices paginates at 50 rows per page', function () {
    Invoice::factory()->count(51)->create(['client_id' => $this->client->id]);

    $this->get('/invoices')
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.total', 51)
            ->where('invoices.per_page', 50)
            ->where('invoices.current_page', 1)
            ->has('invoices.data', 50));

    $this->get('/invoices?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.current_page', 2)
            ->has('invoices.data', 1));
});

test('unauthenticated /invoices redirects to login', function () {
    auth()->logout();
    $this->get('/invoices')->assertRedirect('/login');
});

test('GET /invoices/new defaults to previous month and lists billable unbilled entries', function () {
    $prevMonth = now()->subMonthNoOverflow();
    // 2.5h (non-round) so JSON serialisation preserves the float type (see Index test)
    $inRange = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'In range',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(9, 0),
        'ended_at' => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(11, 30),
        'billable' => true,
    ]);
    // out of range (this month) — excluded by default period
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'This month',
        'started_at' => now()->startOfMonth()->addDay(), 'ended_at' => now()->startOfMonth()->addDay()->addHour(),
        'billable' => true,
    ]);
    // non-billable — excluded
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Internal',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(6), 'ended_at' => $prevMonth->copy()->startOfMonth()->addDays(6)->addHour(),
        'billable' => false,
    ]);

    $this->get("/invoices/new?client={$this->client->id}&project={$this->project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Create')
            ->where('client.id', $this->client->id)
            ->where('project.id', $this->project->id)
            ->has('period.start')->has('period.end')
            ->has('entries', 1, fn (Assert $e) => $e->where('description', 'In range')->etc())
            ->has('suggested_lines', 1, fn (Assert $l) => $l->where('description', 'In range')->where('hours', 2.5)->etc()));
});

test('POST /invoices creates a draft from submitted lines and redirects to its detail', function () {
    $entry = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Work',
        'started_at' => now()->subDays(20), 'ended_at' => now()->subDays(20)->addHours(2), 'billable' => true,
    ]);

    $res = $this->post('/invoices', [
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'entry_ids' => [$entry->id],
        'lines' => [
            ['description' => 'Work', 'hours' => 2.0, 'rate_rappen' => 14500],
        ],
    ]);

    $invoice = Invoice::latest('id')->first();
    $res->assertRedirect("/invoices/{$invoice->number}");
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->total_rappen)->toBe(31350); // 29000 + 8.10% = 31349, rounded to nearest 5
    expect($entry->fresh()->invoice_id)->toBe($invoice->id);
});

test('POST /invoices requires at least one line', function () {
    $this->post('/invoices', [
        'client_id' => $this->client->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'entry_ids' => [],
        'lines' => [],
    ])->assertSessionHasErrors('lines');
});

test('GET /invoices/new excludes running (in-progress) timers', function () {
    $prevMonth = now()->subMonthNoOverflow();
    // finished entry in range — should appear
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Finished',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(9, 0),
        'ended_at' => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(11, 30),
        'billable' => true,
    ]);
    // running entry started in range (ended_at null) — must be excluded
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Running',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(6)->setTime(9, 0),
        'ended_at' => null,
        'billable' => true,
    ]);

    $this->get("/invoices/new?client={$this->client->id}&project={$this->project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Create')
            ->has('entries', 1, fn (Assert $e) => $e->where('description', 'Finished')->etc()));
});

test('POST /invoices rejects a project belonging to a different client', function () {
    $otherClient = Client::factory()->create(['name' => 'Other Co', 'short_code' => 'OC']);
    $otherProject = Project::factory()->create(['client_id' => $otherClient->id, 'billable' => true, 'rate_rappen' => 10000]);

    $this->post('/invoices', [
        'client_id' => $this->client->id,
        'project_id' => $otherProject->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'entry_ids' => [],
        'lines' => [
            ['description' => 'Work', 'hours' => 1.0, 'rate_rappen' => 14500],
        ],
    ])->assertSessionHasErrors('project_id');
});

function makeDraft(): Invoice
{
    $start = now()->subDays(20);
    $entry = TimeEntry::factory()->create([
        'user_id' => test()->user->id, 'project_id' => test()->project->id, 'description' => 'Work',
        'started_at' => $start, 'ended_at' => (clone $start)->addHours(2), 'billable' => true,
    ]);

    return app(InvoiceBuilder::class)->buildDraftFromEntries(
        test()->client, test()->project, TimeEntry::all(),
        now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()
    );
}

test('GET /invoices/{number} renders Invoices/Show with invoice + lines + events', function () {
    $inv = makeDraft();

    $this->get("/invoices/{$inv->number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Show')
            ->where('invoice.number', $inv->number)
            ->where('invoice.status', 'draft')
            ->where('invoice.title', $inv->title)
            ->has('invoice.lines', 1)
            ->has('events', 1, fn (Assert $e) => $e->where('kind', 'created')->etc())
            ->has('linked_entries')
            ->where('preview_url', "/invoices/{$inv->number}/preview"));
});

test('GET /invoices/{number}/edit renders the draft invoice editor', function () {
    $this->withoutVite();

    $inv = makeDraft();
    $inv->lines()->first()->update(['rate_rappen' => 14550]);

    $this->get("/invoices/{$inv->number}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Edit')
            ->where('invoice.id', $inv->id)
            ->where('invoice.number', $inv->number)
            ->where('invoice.client.name', 'Atlas Robotics')
            ->where('invoice.period_start', $inv->period_start->toDateString())
            ->where('invoice.period_end', $inv->period_end->toDateString())
            ->has('invoice.lines', 1, fn (Assert $line) => $line
                ->where('description', 'Work')
                ->where('rate', 145.5)
                ->where('rate_rappen', 14550)
                ->etc()));
});

test('GET /invoices/{number}/edit redirects once the invoice is not a draft', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent']);

    $this->get("/invoices/{$inv->number}/edit")
        ->assertRedirect("/invoices/{$inv->number}")
        ->assertSessionHas('error', 'Only draft invoices can be edited.');
});

test('GET /invoices/{number}/preview returns raw HTML (not Inertia)', function () {
    $inv = makeDraft();
    $res = $this->get("/invoices/{$inv->number}/preview");
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/html');
    $res->assertSee($inv->number, false);
});

test('GET /invoices/{number}/preview renders markdown notes (lists, rules, emphasis, line breaks)', function () {
    $inv = Invoice::factory()->create([
        'client_id' => $this->client->id,
        'notes' => "Liebe Aline\nBesten Dank.\n\n---\n\nLeistungen:\n\n- Setup\n- Betrieb\n\nStundensatz: **CHF 160.–**",
    ]);

    $res = $this->get("/invoices/{$inv->number}/preview");

    $res->assertOk();
    $res->assertSee('<hr', false);
    $res->assertSee('<li>Setup</li>', false);
    $res->assertSee('<strong>CHF 160.–</strong>', false);
    $res->assertSee('Liebe Aline<br>', false);
});

test('GET /invoices/{number}/pdf streams draft PDFs without caching on the invoice', function () {
    $inv = makeDraft();

    $this->mock(InvoicePdfRenderer::class, function ($mock) use ($inv) {
        $mock->shouldReceive('pdfBytes')
            ->once()
            ->with(Mockery::on(fn ($invoice) => $invoice->is($inv)))
            ->andReturn('%PDF-draft');
    });

    $this->get("/invoices/{$inv->number}/pdf")
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('content-type', 'application/pdf')
        ->assertStreamedContent('%PDF-draft');

    expect($inv->fresh()->pdf_path)->toBeNull();
});

test('GET /invoices/{number}/pdf renders sent invoices fresh, ignoring the cached pdf_path', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent', 'pdf_path' => 'invoices/stale.pdf']);

    $this->mock(InvoicePdfRenderer::class, function ($mock) use ($inv) {
        $mock->shouldReceive('pdfBytes')
            ->once()
            ->with(Mockery::on(fn ($invoice) => $invoice->is($inv)))
            ->andReturn('%PDF-fresh');
        $mock->shouldNotReceive('pdf');
    });

    $this->get("/invoices/{$inv->number}/pdf")
        ->assertOk()
        ->assertStreamed()
        ->assertStreamedContent('%PDF-fresh');

    // The frozen as-sent copy stays untouched for reminder emails.
    expect($inv->fresh()->pdf_path)->toBe('invoices/stale.pdf');
});

test('PATCH /invoices/{id} edits a draft notes + lines and recomputes totals', function () {
    $inv = makeDraft();
    $this->patch("/invoices/{$inv->id}", [
        'title' => 'Projekt Aurora',
        'notes' => 'Thanks for your business.',
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'lines' => [['description' => 'Edited', 'hours' => 1.0, 'rate_rappen' => 10000]],
    ])->assertRedirect("/invoices/{$inv->number}");

    $inv->refresh();
    expect($inv->title)->toBe('Projekt Aurora');
    expect($inv->notes)->toBe('Thanks for your business.');
    expect($inv->period_start->toDateString())->toBe('2026-04-01');
    expect($inv->period_end->toDateString())->toBe('2026-04-30');
    expect($inv->lines)->toHaveCount(1);
    expect($inv->subtotal_rappen)->toBe(10000);
    expect($inv->total_rappen)->toBe(10810);
});

test('PATCH is rejected once the invoice is not a draft', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent']);
    $this->patch("/invoices/{$inv->id}", ['notes' => 'x'])->assertStatus(403);
});

test('POST /invoices/{id}/mark-sent issues a draft without emailing', function () {
    $inv = makeDraft();
    Mail::fake();

    $this->post("/invoices/{$inv->id}/mark-sent")->assertRedirect();

    expect($inv->fresh()->status)->toBe('sent');
    Mail::assertNothingSent();
});

test('POST /invoices/{id}/pause-reminders and resume-reminders toggle the pause flag and log events', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent']);

    $this->post("/invoices/{$inv->id}/pause-reminders")->assertRedirect();
    expect($inv->fresh()->reminders_paused_at)->not->toBeNull();
    expect($inv->events()->where('kind', 'reminders_paused')->count())->toBe(1);

    $this->post("/invoices/{$inv->id}/resume-reminders")->assertRedirect();
    expect($inv->fresh()->reminders_paused_at)->toBeNull();
    expect($inv->events()->where('kind', 'reminders_resumed')->count())->toBe(1);
});

test('POST /invoices/{id}/pause-reminders is rejected for non-sent invoices', function () {
    $inv = makeDraft();

    $this->post("/invoices/{$inv->id}/pause-reminders")->assertRedirect();

    expect($inv->fresh()->reminders_paused_at)->toBeNull();
});

test('POST /invoices/{id}/mark-sent is rejected once not a draft', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent']);

    $this->post("/invoices/{$inv->id}/mark-sent");

    expect($inv->fresh()->status)->toBe('sent'); // unchanged, no error 500
});

test('DELETE /invoices/{id} deletes it, cascades lines/events, and releases linked entries', function () {
    $inv = makeDraft();
    $entryId = TimeEntry::first()->id;
    expect(TimeEntry::find($entryId)->invoice_id)->toBe($inv->id);

    $this->delete("/invoices/{$inv->id}")->assertRedirect('/invoices');

    expect(Invoice::find($inv->id))->toBeNull();
    expect(InvoiceLine::where('invoice_id', $inv->id)->count())->toBe(0);
    expect(TimeEntry::find($entryId)->invoice_id)->toBeNull(); // back to unbilled
});

test('DELETE /invoices/{id} works regardless of status (e.g. paid)', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'paid', 'paid_at' => now()]);

    $this->delete("/invoices/{$inv->id}")->assertRedirect('/invoices');

    expect(Invoice::find($inv->id))->toBeNull();
});

test('POST /invoices/{id}/paid marks a sent invoice paid', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'due_on' => now()->addDays(29)]);
    $this->post("/invoices/{$inv->id}/paid")->assertRedirect();
    expect($inv->fresh()->status)->toBe('paid');
});

test('POST /invoices/{id}/void voids and releases entries', function () {
    $inv = makeDraft();
    $this->post("/invoices/{$inv->id}/void")->assertRedirect();
    expect($inv->fresh()->status)->toBe('void');
    expect(TimeEntry::unbilled()->billable()->count())->toBe(1);
});

test('POST /invoices/{id}/send issues the draft', function () {
    BusinessProfile::current()->update(['qr_iban' => 'CH4431999123000889012', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    $this->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    Contact::factory()->for($this->client)->create(['is_default' => true]);
    Mail::fake();
    $inv = makeDraft();
    $this->post("/invoices/{$inv->id}/send")
        ->assertRedirect()
        ->assertSessionMissing('error')
        ->assertSessionHasNoErrors();
    expect($inv->fresh()->status)->toBe('sent');
    Mail::assertSent(InvoiceMail::class);
})->group('browsershot');

test('POST /invoices/{id}/send keeps draft when client email is missing', function () {
    $this->client->update(['email' => null]);
    $inv = makeDraft();

    $this->post("/invoices/{$inv->id}/send")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($inv->fresh()->status)->toBe('draft');
    expect($inv->events()->where('kind', 'sent')->count())->toBe(0);
});

test('GET /invoices/new without a client renders the client picker (not a 404)', function () {
    Client::factory()->create(['name' => 'Pickable Co']);

    $this->get('/invoices/new')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Create')
            ->where('client', null)
            ->has('clients'));
});
