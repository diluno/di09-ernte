<?php

use App\Services\Harvest\HarvestApi;
use App\Services\Harvest\HarvestApiException;
use Illuminate\Support\Facades\Http;

function api(): HarvestApi
{
    return new HarvestApi('tok-123', 'acct-456', 'https://api.harvestapp.com/v2', 'ernte-test');
}

test('sends the required auth headers', function () {
    Http::fake(['*/clients*' => Http::response(['clients' => [], 'next_page' => null])]);

    api()->clients();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer tok-123')
            && $request->hasHeader('Harvest-Account-Id', 'acct-456')
            && $request->hasHeader('User-Agent', 'ernte-test');
    });
});

test('follows pagination via next_page and concatenates rows', function () {
    Http::fake(['*/projects*' => Http::sequence()
        ->push(['projects' => [['id' => 1], ['id' => 2]], 'next_page' => 2])
        ->push(['projects' => [['id' => 3]], 'next_page' => null])]);

    $rows = api()->projects();

    expect($rows)->toHaveCount(3);
    expect($rows->pluck('id')->all())->toBe([1, 2, 3]);
});

test('throws HarvestApiException on a non-2xx response', function () {
    Http::fake(['*/invoices*' => Http::response(['error' => 'unauthorized'], 401)]);

    expect(fn () => api()->invoices())->toThrow(HarvestApiException::class);
});

test('retries once on 429 then succeeds', function () {
    Http::fake(['*/estimates*' => Http::sequence()
        ->push(['error' => 'rate limited'], 429, ['Retry-After' => '0'])
        ->push(['estimates' => [['id' => 9]], 'next_page' => null], 200)]);

    $rows = api()->estimates();

    expect($rows)->toHaveCount(1);
    expect($rows->first()['id'])->toBe(9);
});
