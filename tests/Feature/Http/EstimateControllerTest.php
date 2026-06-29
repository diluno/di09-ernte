<?php

use App\Mail\EstimateMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimatePdfRenderer;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
});

function makeDraftEstimate(): Estimate
{
    return app(EstimateBuilder::class)->createDraft(
        client: test()->client,
        project: test()->project,
        lines: [['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500]],
    );
}

test('GET /estimates renders Estimates/Index with rows + stats + counts', function () {
    $est = Estimate::factory()->sent()->create([
        'client_id' => $this->client->id, 'number' => 'OF-2026-001',
        'valid_until' => now()->addDays(10)->toDateString(), 'total_rappen' => 100_50,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $est->id, 'hours' => 5.5]);

    $this->get('/estimates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estimates/Index')
            ->where('estimates.total', 1)
            ->where('estimates.per_page', 50)
            ->has('estimates.data', 1, fn (Assert $r) => $r
                ->where('number', 'OF-2026-001')
                ->where('client.name', 'Atlas Robotics')
                ->where('total', 100.5)
                ->where('hours', 5.5)
                ->where('status', 'sent')
                ->where('expired', false)
                ->etc())
            ->has('stats', fn (Assert $s) => $s->has('open')->has('accepted_ytd')->has('acceptance_rate')->etc())
            ->has('counts', fn (Assert $c) => $c->where('all', 1)->has('draft')->has('sent')->has('accepted')->has('declined')->has('expired')->etc())
            ->where('filters.filter', 'all'));
});

test('GET /estimates paginates at 50 rows per page', function () {
    Estimate::factory()->count(51)->create(['client_id' => $this->client->id]);

    $this->get('/estimates')
        ->assertInertia(fn (Assert $page) => $page
            ->where('estimates.total', 51)
            ->where('estimates.per_page', 50)
            ->where('estimates.current_page', 1)
            ->has('estimates.data', 50));

    $this->get('/estimates?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('estimates.current_page', 2)
            ->has('estimates.data', 1));
});

test('unauthenticated /estimates redirects to login', function () {
    auth()->logout();
    $this->get('/estimates')->assertRedirect('/login');
});

test('GET /estimates/new renders the editor with clients and projects', function () {
    $this->get('/estimates/new')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estimates/Create')
            ->has('clients')
            ->has('projects'));
});

test('POST /estimates creates a draft from submitted lines and redirects to its detail', function () {
    $res = $this->post('/estimates', [
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'title' => 'Partnerschaft auf Augenhöhe',
        'notes' => 'Quote for Q3 work.',
        'lines' => [
            ['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500],
        ],
    ]);

    $estimate = Estimate::latest('id')->first();
    $res->assertRedirect("/estimates/{$estimate->number}");
    expect($estimate->lines)->toHaveCount(1);
    expect($estimate->total_rappen)->toBe(31350); // 29000 + 8.10% = 31349, rounded to nearest 5
    expect($estimate->title)->toBe('Partnerschaft auf Augenhöhe');
    expect($estimate->notes)->toBe('Quote for Q3 work.');
});

test('POST /estimates accepts a long notes field (full specification, over the old 5000 limit)', function () {
    $notes = str_repeat('Spezifikation Zeile. ', 400); // 8400 chars

    $res = $this->post('/estimates', [
        'client_id' => $this->client->id,
        'notes' => $notes,
        'lines' => [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000]],
    ]);

    $res->assertSessionHasNoErrors();
    // TrimStrings middleware strips the trailing whitespace; the body is stored intact.
    expect(Estimate::latest('id')->first()->notes)->toBe(rtrim($notes));
    expect(strlen($notes))->toBeGreaterThan(5000);
});

test('POST /estimates requires at least one line', function () {
    $this->post('/estimates', ['client_id' => $this->client->id, 'lines' => []])
        ->assertSessionHasErrors('lines');
});

test('POST /estimates rejects a project belonging to a different client', function () {
    $otherClient = Client::factory()->create(['name' => 'Other Co', 'short_code' => 'OC']);
    $otherProject = Project::factory()->create(['client_id' => $otherClient->id, 'billable' => true, 'rate_rappen' => 10000]);

    $this->post('/estimates', [
        'client_id' => $this->client->id,
        'project_id' => $otherProject->id,
        'lines' => [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 14500]],
    ])->assertSessionHasErrors('project_id');
});

test('GET /estimates/{number} renders Estimates/Show with estimate + lines + events', function () {
    $est = makeDraftEstimate();

    $this->get("/estimates/{$est->number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estimates/Show')
            ->where('estimate.number', $est->number)
            ->where('estimate.status', 'draft')
            ->where('estimate.title', $est->title)
            ->has('estimate.lines', 1)
            ->has('events', 1, fn (Assert $e) => $e->where('kind', 'created')->etc())
            ->where('preview_url', "/estimates/{$est->number}/preview"));
});

test('GET /estimates/{number}/edit renders Estimates/Edit prefilled for a draft', function () {
    $est = makeDraftEstimate();

    $this->get("/estimates/{$est->number}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estimates/Edit')
            ->where('estimate.number', $est->number)
            ->where('estimate.client_id', $est->client_id)
            ->where('estimate.project_id', $est->project_id)
            ->has('estimate.lines', 1)
            ->has('clients')
            ->has('projects'));
});

test('GET /estimates/{number}/edit redirects when the estimate is not a draft', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent']);

    $this->get("/estimates/{$est->number}/edit")
        ->assertRedirect("/estimates/{$est->number}");
});

test('PATCH /estimates/{id} can change the client and project of a draft', function () {
    $est = makeDraftEstimate();
    $newClient = Client::factory()->create(['name' => 'New Co', 'short_code' => 'NC']);
    $newProject = Project::factory()->create(['client_id' => $newClient->id, 'billable' => true, 'rate_rappen' => 12000]);

    $this->patch("/estimates/{$est->id}", [
        'client_id' => $newClient->id,
        'project_id' => $newProject->id,
        'lines' => [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000]],
    ])->assertRedirect("/estimates/{$est->number}");

    $est->refresh();
    expect($est->client_id)->toBe($newClient->id);
    expect($est->project_id)->toBe($newProject->id);
});

test('PATCH /estimates/{id} rejects a project belonging to a different client', function () {
    $est = makeDraftEstimate();
    $otherClient = Client::factory()->create(['name' => 'Other Co', 'short_code' => 'OC']);
    $otherProject = Project::factory()->create(['client_id' => $otherClient->id, 'billable' => true, 'rate_rappen' => 10000]);

    $this->patch("/estimates/{$est->id}", [
        'project_id' => $otherProject->id,
        'lines' => [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000]],
    ])->assertSessionHasErrors('project_id');
});

test('GET /estimates/{number}/preview returns raw HTML (not Inertia)', function () {
    $est = makeDraftEstimate();
    $res = $this->get("/estimates/{$est->number}/preview");
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/html');
    $res->assertSee($est->number, false);
});

test('GET /estimates/{number}/pdf streams draft PDFs without caching', function () {
    $est = makeDraftEstimate();

    $this->mock(EstimatePdfRenderer::class, function ($mock) use ($est) {
        $mock->shouldReceive('pdfBytes')
            ->once()
            ->with(Mockery::on(fn ($estimate) => $estimate->is($est)))
            ->andReturn('%PDF-draft');
    });

    $this->get("/estimates/{$est->number}/pdf")
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('content-type', 'application/pdf')
        ->assertStreamedContent('%PDF-draft');

    expect($est->fresh()->pdf_path)->toBeNull();
});

test('GET /estimates/{number}/pdf renders sent estimates fresh, ignoring the cached pdf_path', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent', 'pdf_path' => 'estimates/stale.pdf']);

    $this->mock(EstimatePdfRenderer::class, function ($mock) use ($est) {
        $mock->shouldReceive('pdfBytes')
            ->once()
            ->with(Mockery::on(fn ($estimate) => $estimate->is($est)))
            ->andReturn('%PDF-fresh');
        $mock->shouldNotReceive('pdf');
    });

    $this->get("/estimates/{$est->number}/pdf")
        ->assertOk()
        ->assertStreamed()
        ->assertStreamedContent('%PDF-fresh');

    // The frozen as-sent copy stays untouched for reminder emails.
    expect($est->fresh()->pdf_path)->toBe('estimates/stale.pdf');
});

test('PATCH /estimates/{id} edits a draft notes + lines and recomputes totals', function () {
    $est = makeDraftEstimate();
    $this->patch("/estimates/{$est->id}", [
        'title' => 'Neuer Titel',
        'notes' => 'Updated scope.',
        'lines' => [['description' => 'Edited', 'hours' => 1.0, 'rate_rappen' => 10000]],
    ])->assertRedirect("/estimates/{$est->number}");

    $est->refresh();
    expect($est->title)->toBe('Neuer Titel');
    expect($est->notes)->toBe('Updated scope.');
    expect($est->lines)->toHaveCount(1);
    expect($est->subtotal_rappen)->toBe(10000);
    expect($est->total_rappen)->toBe(10810);
});

test('PATCH is rejected once the estimate is not a draft', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent']);
    $this->patch("/estimates/{$est->id}", ['notes' => 'x'])->assertStatus(403);
});

test('DELETE /estimates/{id} deletes it and cascades its lines (any status)', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'accepted', 'decided_at' => now()]);

    $this->delete("/estimates/{$est->id}")->assertRedirect('/estimates');

    expect(Estimate::find($est->id))->toBeNull();
    expect(EstimateLine::where('estimate_id', $est->id)->count())->toBe(0);
});

test('POST /estimates/{id}/accept accepts a sent estimate', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);
    $this->post("/estimates/{$est->id}/accept")->assertRedirect();
    expect($est->fresh()->status)->toBe('accepted');
});

test('POST /estimates/{id}/decline declines a sent estimate', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);
    $this->post("/estimates/{$est->id}/decline")->assertRedirect();
    expect($est->fresh()->status)->toBe('declined');
});

test('POST /estimates/{id}/convert creates a linked draft invoice and redirects to it', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'accepted', 'decided_at' => now()]);

    $res = $this->post("/estimates/{$est->id}/convert");
    $res->assertSessionMissing('error');

    $invoice = Invoice::latest('id')->first();
    expect($invoice)->not->toBeNull();
    expect($est->fresh()->converted_invoice_id)->toBe($invoice->id);
    $res->assertRedirect("/invoices/{$invoice->number}");
});

test('POST /estimates/{id}/send keeps draft when client email is missing', function () {
    $this->client->update(['email' => null]);
    $est = makeDraftEstimate();

    $this->post("/estimates/{$est->id}/send")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($est->fresh()->status)->toBe('draft');
    expect($est->events()->where('kind', 'sent')->count())->toBe(0);
});

test('POST /estimates/{id}/mark-sent issues a draft without emailing', function () {
    $est = makeDraftEstimate();
    Mail::fake();

    $this->post("/estimates/{$est->id}/mark-sent")->assertRedirect();

    expect($est->fresh()->status)->toBe('sent');
    Mail::assertNothingSent();
});

test('POST /estimates/{id}/send issues the draft', function () {
    BusinessProfile::current()->update(['address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    $this->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    Mail::fake();
    $est = makeDraftEstimate();

    $this->post("/estimates/{$est->id}/send")
        ->assertRedirect()
        ->assertSessionMissing('error')
        ->assertSessionHasNoErrors();

    expect($est->fresh()->status)->toBe('sent');
    Mail::assertSent(EstimateMail::class);
})->group('browsershot');
