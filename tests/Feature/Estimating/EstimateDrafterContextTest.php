<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Services\Estimating\EstimateDrafter;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
});

/** Reach the private prompt builder — the context it assembles is the thing under test. */
function promptFor(Client $client): string
{
    $method = new ReflectionMethod(EstimateDrafter::class, 'userPrompt');

    return $method->invoke(app(EstimateDrafter::class), 'Neue Website für den Hofladen.', $client, null);
}

function estimateWithLines(Client $client, string $title, string $status, array $lines): Estimate
{
    $estimate = Estimate::factory()->create([
        'client_id' => $client->id, 'title' => $title, 'status' => $status,
    ]);
    foreach ($lines as $i => [$description, $hours, $rate]) {
        EstimateLine::factory()->create([
            'estimate_id' => $estimate->id, 'description' => $description,
            'hours' => $hours, 'rate_rappen' => $rate * 100, 'sort_order' => $i,
        ]);
    }

    return $estimate;
}

test('the prompt carries estimates from other clients as general house style', function () {
    $target = Client::factory()->create(['name' => 'Hofladen Berg', 'short_code' => 'HB']);
    $other = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);

    estimateWithLines($other, 'Atlas Relaunch', 'accepted', [['Konzept & IA', 6, 145], ['Umsetzung', 20, 145]]);

    $prompt = promptFor($target);

    expect($prompt)
        ->toContain('Recent estimates for other clients')
        ->toContain('Atlas Relaunch')
        ->toContain('Atlas Robotics')
        ->toContain('Konzept & IA — 6h @ 145');
});

test('accepted estimates are marked so the model can weight them', function () {
    $target = Client::factory()->create(['name' => 'Hofladen Berg', 'short_code' => 'HB']);
    $other = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    estimateWithLines($other, 'Atlas Relaunch', 'accepted', [['Konzept', 6, 145]]);

    expect(promptFor($target))->toContain('Atlas Relaunch — Atlas Robotics [accepted]');
});

test('the client’s own estimates are listed separately from everyone else’s', function () {
    $target = Client::factory()->create(['name' => 'Hofladen Berg', 'short_code' => 'HB']);
    $other = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);

    estimateWithLines($target, 'Hofladen Shop', 'sent', [['Bestellformular', 8, 160]]);
    estimateWithLines($other, 'Atlas Relaunch', 'sent', [['Konzept', 6, 145]]);

    $prompt = promptFor($target);

    expect($prompt)
        ->toContain('Past estimates for Hofladen Berg')
        ->toContain('Bestellformular — 8h @ 160');

    // The client's own estimate must not be repeated in the "other clients" block.
    $ownBlock = substr($prompt, strpos($prompt, 'Past estimates for Hofladen Berg'), strpos($prompt, 'Recent estimates for other clients') - strpos($prompt, 'Past estimates for Hofladen Berg'));
    expect($ownBlock)->toContain('Hofladen Shop');
    expect(substr($prompt, strpos($prompt, 'Recent estimates for other clients')))->not->toContain('Hofladen Shop');
});

test('lines are grouped per estimate rather than flattened into one list', function () {
    $target = Client::factory()->create(['name' => 'Hofladen Berg', 'short_code' => 'HB']);
    estimateWithLines($target, 'Phase One', 'sent', [['Konzept', 4, 145], ['Design', 10, 145]]);
    estimateWithLines($target, 'Phase Two', 'sent', [['Umsetzung', 20, 145]]);

    $prompt = promptFor($target);

    expect($prompt)->toContain("Phase One\n  - Konzept — 4h @ 145\n  - Design — 10h @ 145");
    expect($prompt)->toContain("Phase Two\n  - Umsetzung — 20h @ 145");
});

test('a brand new client still gets studio-wide grounding', function () {
    $fresh = Client::factory()->create(['name' => 'Neu AG', 'short_code' => 'NA']);
    $other = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    estimateWithLines($other, 'Atlas Relaunch', 'accepted', [['Konzept', 6, 145]]);

    $prompt = promptFor($fresh);

    expect($prompt)
        ->not->toContain('Past estimates for Neu AG')
        ->toContain('Recent estimates for other clients');
});
