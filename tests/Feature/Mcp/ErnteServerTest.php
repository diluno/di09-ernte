<?php

use App\Mcp\Servers\ErnteServer;
use App\Mcp\Tools\AcceptEstimate;
use App\Mcp\Tools\ConvertEstimateToInvoice;
use App\Mcp\Tools\CreateEstimate;
use App\Mcp\Tools\CreateInvoice;
use App\Mcp\Tools\GetInvoice;
use App\Mcp\Tools\ListInvoices;
use App\Mcp\Tools\SendInvoice;
use App\Mcp\Tools\GetEstimate;
use App\Mcp\Tools\ListClients;
use App\Mcp\Tools\ListEstimates;
use App\Mcp\Tools\SendEstimate;
use App\Mcp\Tools\UpdateEstimate;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimateLifecycle;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
});

function draft(): Estimate
{
    return app(EstimateBuilder::class)->createDraft(
        client: test()->client,
        project: test()->project,
        lines: [['description' => 'Konzept', 'hours' => 4.0, 'rate_rappen' => 14500]],
    );
}

test('the endpoint is closed to unauthenticated callers', function () {
    $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();
});

test('an authenticated caller can list the tools over HTTP', function () {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], ['Accept' => 'application/json, text/event-stream']);

    $response->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name');
    expect($names)->toContain('list_clients', 'draft_estimate_lines', 'create_estimate', 'send_estimate');
});

test('list_clients resolves a name to an id, with projects and contacts', function () {
    Contact::factory()->create(['client_id' => $this->client->id, 'email' => 'ops@atlas.test', 'is_default' => true]);

    ErnteServer::actingAs($this->user)
        ->tool(ListClients::class, ['search' => 'Atlas'])
        ->assertOk()
        ->assertSee('Atlas Robotics')
        ->assertSee('ops@atlas.test');
});

test('create_estimate persists a draft and computes the totals itself', function () {
    $response = ErnteServer::actingAs($this->user)->tool(CreateEstimate::class, [
        'client_id' => $this->client->id,
        'title' => 'Relaunch',
        'lines' => [
            ['description' => 'Konzept', 'hours' => 4, 'rate' => 145],
            ['description' => 'Umsetzung', 'hours' => 10, 'rate' => 145],
        ],
    ]);

    $response->assertOk();

    $estimate = Estimate::latest('id')->first();
    expect($estimate->status)->toBe('draft')
        ->and($estimate->lines)->toHaveCount(2)
        ->and($estimate->subtotal_rappen)->toBe(14 * 14500);
});

test('create_estimate rejects an unknown client instead of writing anything', function () {
    ErnteServer::actingAs($this->user)
        ->tool(CreateEstimate::class, ['client_id' => 99999, 'lines' => [['description' => 'x', 'hours' => 1, 'rate' => 100]]])
        ->assertHasErrors();

    expect(Estimate::count())->toBe(0);
});

test('create_estimate rejects a project belonging to another client', function () {
    $otherProject = Project::factory()->create();

    ErnteServer::actingAs($this->user)
        ->tool(CreateEstimate::class, [
            'client_id' => $this->client->id,
            'project_id' => $otherProject->id,
            'lines' => [['description' => 'x', 'hours' => 1, 'rate' => 100]],
        ])
        ->assertHasErrors();

    expect(Estimate::count())->toBe(0);
});

test('create_estimate shares numeric and length validation with the web form', function () {
    ErnteServer::actingAs($this->user)
        ->tool(CreateEstimate::class, [
            'client_id' => $this->client->id,
            'title' => str_repeat('x', 256),
            'lines' => [['description' => 'x', 'hours' => -1, 'rate' => 100]],
        ])
        ->assertHasErrors();

    expect(Estimate::count())->toBe(0);
});

test('create_estimate preserves fractional franc rates', function () {
    ErnteServer::actingAs($this->user)
        ->tool(CreateEstimate::class, [
            'client_id' => $this->client->id,
            'lines' => [['description' => 'x', 'hours' => 1, 'rate' => 145.5]],
        ])
        ->assertOk()
        ->assertSee('145.5');

    expect(Estimate::first()->lines()->first()->rate_rappen)->toBe(14550);
});

test('get_estimate returns the lines for a known number', function () {
    $estimate = draft();

    ErnteServer::actingAs($this->user)
        ->tool(GetEstimate::class, ['number' => $estimate->number])
        ->assertOk()
        ->assertSee('Konzept');
});

test('get_estimate errors on an unknown number rather than guessing', function () {
    ErnteServer::actingAs($this->user)
        ->tool(GetEstimate::class, ['number' => 'OF-1999-001'])
        ->assertHasErrors();
});

test('list_estimates filters by status', function () {
    draft();
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'number' => 'OF-2026-900']);

    ErnteServer::actingAs($this->user)
        ->tool(ListEstimates::class, ['status' => 'sent'])
        ->assertOk()
        ->assertSee('OF-2026-900');
});

test('update_estimate replaces the whole line set and recomputes totals', function () {
    $estimate = draft();

    ErnteServer::actingAs($this->user)->tool(UpdateEstimate::class, [
        'number' => $estimate->number,
        'lines' => [['description' => 'Nur noch eine Position', 'hours' => 2, 'rate' => 200]],
    ])->assertOk();

    $estimate->refresh();
    expect($estimate->lines)->toHaveCount(1)
        ->and($estimate->subtotal_rappen)->toBe(2 * 20000);
});

test('update_estimate refuses to edit anything that is not a draft', function () {
    $sent = Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'number' => 'OF-2026-901']);

    ErnteServer::actingAs($this->user)
        ->tool(UpdateEstimate::class, ['number' => $sent->number, 'title' => 'nope'])
        ->assertHasErrors();

    expect($sent->fresh()->title)->not->toBe('nope');
});

test('send_estimate will not send an estimate that does not exist', function () {
    ErnteServer::actingAs($this->user)
        ->tool(SendEstimate::class, ['number' => 'OF-1999-001'])
        ->assertHasErrors();
});

test('accept and convert move a sent estimate through to a draft invoice', function () {
    $estimate = draft();
    app(EstimateLifecycle::class)->markSent($estimate);

    ErnteServer::actingAs($this->user)
        ->tool(AcceptEstimate::class, ['number' => $estimate->number])
        ->assertOk();

    expect($estimate->fresh()->status)->toBe('accepted');

    ErnteServer::actingAs($this->user)
        ->tool(ConvertEstimateToInvoice::class, ['number' => $estimate->number])
        ->assertOk();

    expect($estimate->fresh()->converted_invoice_id)->not->toBeNull();
});

test('convert refuses an estimate that has not been accepted', function () {
    $estimate = draft();

    ErnteServer::actingAs($this->user)
        ->tool(ConvertEstimateToInvoice::class, ['number' => $estimate->number])
        ->assertHasErrors();
});

test('create_invoice persists a draft from explicit lines and computes totals', function () {
    ErnteServer::actingAs($this->user)->tool(CreateInvoice::class, [
        'client_id' => $this->client->id,
        'title' => 'Wartung',
        'lines' => [
            ['description' => 'Hosting', 'hours' => 1, 'rate' => 120.5, 'vat_exempt' => false],
            ['description' => 'Support', 'hours' => 2, 'rate' => 145],
        ],
    ])->assertOk();

    $invoice = Invoice::latest('id')->first();
    expect($invoice->status)->toBe('draft')
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->subtotal_rappen)->toBe(12050 + 2 * 14500);
});

test('create_invoice bills unbilled time in the period and links the entries', function () {
    $entry = TimeEntry::factory()->create([
        'project_id' => $this->project->id,
        'description' => 'Umsetzung',
        'started_at' => '2026-08-10 09:00:00',
        'ended_at' => '2026-08-10 11:00:00',
    ]);
    $outside = TimeEntry::factory()->create([
        'project_id' => $this->project->id,
        'started_at' => '2026-07-10 09:00:00',
        'ended_at' => '2026-07-10 10:00:00',
    ]);

    ErnteServer::actingAs($this->user)->tool(CreateInvoice::class, [
        'client_id' => $this->client->id,
        'from_time_entries' => true,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ])->assertOk()->assertSee('Umsetzung');

    $invoice = Invoice::latest('id')->first();
    expect($entry->fresh()->invoice_id)->toBe($invoice->id)
        ->and($outside->fresh()->invoice_id)->toBeNull()
        ->and($invoice->subtotal_rappen)->toBe(2 * 14500);
});

test('create_invoice errors when there is no unbilled time', function () {
    ErnteServer::actingAs($this->user)
        ->tool(CreateInvoice::class, ['client_id' => $this->client->id, 'from_time_entries' => true, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31'])
        ->assertHasErrors();

    expect(Invoice::count())->toBe(0);
});

test('create_invoice requires lines unless billing time, and rejects foreign projects', function () {
    ErnteServer::actingAs($this->user)
        ->tool(CreateInvoice::class, ['client_id' => $this->client->id])
        ->assertHasErrors();

    ErnteServer::actingAs($this->user)
        ->tool(CreateInvoice::class, [
            'client_id' => $this->client->id,
            'project_id' => Project::factory()->create()->id,
            'lines' => [['description' => 'x', 'hours' => 1, 'rate' => 100]],
        ])
        ->assertHasErrors();

    expect(Invoice::count())->toBe(0);
});

test('list_invoices filters by status and get_invoice returns lines', function () {
    $sent = Invoice::factory()->sent()->create(['client_id' => $this->client->id]);

    ErnteServer::actingAs($this->user)
        ->tool(ListInvoices::class, ['status' => 'sent'])
        ->assertOk()
        ->assertSee($sent->number);

    ErnteServer::actingAs($this->user)
        ->tool(GetInvoice::class, ['number' => 'nope'])
        ->assertHasErrors();
});

test('send_invoice refuses unknown and non-draft invoices', function () {
    $sent = Invoice::factory()->sent()->create(['client_id' => $this->client->id]);

    ErnteServer::actingAs($this->user)
        ->tool(SendInvoice::class, ['number' => 'nope'])
        ->assertHasErrors();

    ErnteServer::actingAs($this->user)
        ->tool(SendInvoice::class, ['number' => $sent->number])
        ->assertHasErrors();
});
