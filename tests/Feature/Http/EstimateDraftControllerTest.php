<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateDrafter;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->actingAs(User::factory()->create());
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
});

test('POST /estimates/draft returns the drafted proposal without persisting anything', function () {
    $this->mock(EstimateDrafter::class)
        ->shouldReceive('draft')
        ->once()
        ->andReturn([
            'title' => 'Hofladen Website',
            'notes' => 'Hosting nicht enthalten.',
            'lines' => [
                ['description' => 'Konzept', 'hours' => 4.0, 'rate' => 145],
                ['description' => 'Umsetzung', 'hours' => 12.5, 'rate' => 145],
            ],
        ]);

    $this->postJson('/estimates/draft', [
        'brief' => 'Neue Website für den Hofladen mit sechs Seiten und Bestellformular.',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
    ])
        ->assertOk()
        ->assertJsonPath('title', 'Hofladen Website')
        ->assertJsonPath('lines.1.description', 'Umsetzung')
        ->assertJsonPath('lines.1.hours', 12.5);

    expect(\App\Models\Estimate::count())->toBe(0);
});

test('POST /estimates/draft rejects a brief that is too short', function () {
    $this->postJson('/estimates/draft', ['brief' => 'nope', 'client_id' => $this->client->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('brief');
});

test('POST /estimates/draft surfaces a drafting failure as a 422 message', function () {
    $this->mock(EstimateDrafter::class)
        ->shouldReceive('draft')
        ->andThrow(new RuntimeException('ANTHROPIC_API_KEY is not configured.'));

    $this->postJson('/estimates/draft', [
        'brief' => 'Neue Website für den Hofladen mit sechs Seiten.',
        'client_id' => $this->client->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Could not draft this estimate: ANTHROPIC_API_KEY is not configured.');
});
