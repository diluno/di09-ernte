<?php

use App\Mcp\Servers\ErnteServer;
use App\Mcp\Tools\CreateRecurringInvoice;
use App\Mcp\Tools\GetRecurringInvoice;
use App\Mcp\Tools\ListRecurringInvoices;
use App\Mcp\Tools\PauseRecurringInvoice;
use App\Mcp\Tools\ResumeRecurringInvoice;
use App\Mcp\Tools\RunRecurringInvoice;
use App\Mcp\Tools\UpdateRecurringInvoice;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    Mail::fake();
    Carbon::setTestNow('2026-05-29');
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics']);
    Contact::factory()->for($this->client)->create(['name' => 'Ops', 'email' => 'ops@atlas.test', 'is_default' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function mcpSchedule(array $overrides = []): RecurringInvoice
{
    $s = RecurringInvoice::factory()->create($overrides + [
        'client_id' => test()->client->id, 'title' => 'Hosting – {period}', 'cadence' => 'monthly',
        'anchor_day' => 1, 'next_run_on' => '2026-06-01', 'vat_rate' => 8.10, 'auto_send' => false,
        'paused_at' => null,
    ]);
    RecurringInvoiceLine::factory()->for($s, 'recurringInvoice')->create(['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 12000, 'sort_order' => 0]);

    return $s;
}

test('the recurring tools are registered', function () {
    $this->actingAs($this->user, 'sanctum');
    $names = collect($this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
        ->json('result.tools'))->pluck('name');

    expect($names)->toContain(
        'list_recurring_invoices', 'get_recurring_invoice', 'create_recurring_invoice',
        'update_recurring_invoice', 'pause_recurring_invoice', 'resume_recurring_invoice', 'run_recurring_invoice',
    );
});

test('create_recurring_invoice saves a schedule in francs, snaps a past start forward, generates nothing', function () {
    ErnteServer::actingAs($this->user)->tool(CreateRecurringInvoice::class, [
        'client_id' => $this->client->id,
        'title' => 'Hosting – {period}',
        'cadence' => 'monthly',
        'next_run_on' => '2026-01-10',
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate' => 120.5]],
    ])->assertOk()->assertSee('2026-06-10')->assertSee('ops@atlas.test');

    $s = RecurringInvoice::with('lines')->sole();
    expect($s->anchor_day)->toBe(10)
        ->and($s->next_run_on->toDateString())->toBe('2026-06-10')
        ->and($s->auto_send)->toBeFalse()
        ->and($s->lines->first()->rate_rappen)->toBe(12050)
        ->and(Invoice::count())->toBe(0);
});

test('create_recurring_invoice validates the cadence and lines', function () {
    ErnteServer::actingAs($this->user)->tool(CreateRecurringInvoice::class, [
        'client_id' => $this->client->id, 'cadence' => 'weekly', 'next_run_on' => '2026-06-01', 'lines' => [],
    ])->assertHasErrors();

    expect(RecurringInvoice::count())->toBe(0);
});

test('list and get show the schedule with the next invoice title and period', function () {
    $s = mcpSchedule();
    mcpSchedule(['paused_at' => now(), 'title' => 'Paused one']);

    ErnteServer::actingAs($this->user)->tool(ListRecurringInvoices::class, ['state' => 'active'])
        ->assertOk()->assertSee('Hosting – {period}')->assertDontSee('Paused one');

    ErnteServer::actingAs($this->user)->tool(GetRecurringInvoice::class, ['id' => $s->id])
        ->assertOk()->assertSee('Hosting – Juni 2026')->assertSee('2026-06-30')->assertSee('120');

    ErnteServer::actingAs($this->user)->tool(GetRecurringInvoice::class, ['id' => 9999])->assertHasErrors();
});

test('update_recurring_invoice changes only what is passed', function () {
    $s = mcpSchedule();

    ErnteServer::actingAs($this->user)->tool(UpdateRecurringInvoice::class, [
        'id' => $s->id, 'auto_send' => true,
    ])->assertOk();

    $s->refresh()->load('lines');
    expect($s->auto_send)->toBeTrue()
        ->and($s->title)->toBe('Hosting – {period}')
        ->and($s->next_run_on->toDateString())->toBe('2026-06-01')
        ->and($s->lines)->toHaveCount(1);

    ErnteServer::actingAs($this->user)->tool(UpdateRecurringInvoice::class, [
        'id' => $s->id,
        'cadence' => 'quarterly',
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate' => 300], ['description' => 'Support', 'hours' => 2, 'rate' => 150]],
    ])->assertOk();

    $s->refresh()->load('lines');
    expect($s->cadence)->toBe('quarterly')
        ->and($s->lines->pluck('rate_rappen')->all())->toBe([30000, 15000]);
});

test('update_recurring_invoice drops the project when moving to another client', function () {
    $project = Project::factory()->create(['client_id' => $this->client->id]);
    $s = mcpSchedule(['project_id' => $project->id]);
    $other = Client::factory()->create();

    ErnteServer::actingAs($this->user)->tool(UpdateRecurringInvoice::class, ['id' => $s->id, 'client_id' => $other->id])->assertOk();

    expect($s->fresh()->client_id)->toBe($other->id)->and($s->fresh()->project_id)->toBeNull();
});

test('pause and resume; resume never backfills', function () {
    $s = mcpSchedule();

    ErnteServer::actingAs($this->user)->tool(PauseRecurringInvoice::class, ['id' => $s->id])->assertOk();
    expect($s->fresh()->isPaused())->toBeTrue();
    ErnteServer::actingAs($this->user)->tool(PauseRecurringInvoice::class, ['id' => $s->id])->assertHasErrors();

    Carbon::setTestNow('2026-08-15');
    ErnteServer::actingAs($this->user)->tool(ResumeRecurringInvoice::class, ['id' => $s->id])->assertOk();
    expect($s->fresh()->isPaused())->toBeFalse()
        ->and($s->fresh()->next_run_on->toDateString())->toBe('2026-09-01');
});

test('run_recurring_invoice generates a draft and advances the schedule', function () {
    $s = mcpSchedule();

    ErnteServer::actingAs($this->user)->tool(RunRecurringInvoice::class, ['id' => $s->id])
        ->assertOk()->assertSee('Generated draft invoice')->assertSee('Hosting – Juni 2026');

    $invoice = Invoice::sole();
    expect($invoice->status)->toBe('draft')
        ->and($invoice->recurring_invoice_id)->toBe($s->id)
        ->and($s->fresh()->next_run_on->toDateString())->toBe('2026-07-01');
    Mail::assertNothingSent();
});

test('run_recurring_invoice refuses a paused schedule', function () {
    $s = mcpSchedule(['paused_at' => now()]);

    ErnteServer::actingAs($this->user)->tool(RunRecurringInvoice::class, ['id' => $s->id])->assertHasErrors();
    expect(Invoice::count())->toBe(0);
});
