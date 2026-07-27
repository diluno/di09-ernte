<?php

use App\Mcp\Servers\ErnteServer;
use App\Mcp\Tools\AcceptEstimate;
use App\Mcp\Tools\ConvertEstimateToInvoice;
use App\Mcp\Tools\CreateEstimate;
use App\Mcp\Tools\GetEstimate;
use App\Mcp\Tools\ListClients;
use App\Mcp\Tools\ListEstimates;
use App\Mcp\Tools\SendEstimate;
use App\Mcp\Tools\UpdateEstimate;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;

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
    Laravel\Sanctum\Sanctum::actingAs($this->user);

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
    app(\App\Services\Estimating\EstimateLifecycle::class)->markSent($estimate);

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
