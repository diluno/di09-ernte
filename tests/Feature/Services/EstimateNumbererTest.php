<?php

use App\Models\EstimateCounter;
use App\Services\Estimating\EstimateNumberer;

test('allocates sequential, prefixed, zero-padded numbers per year', function () {
    $numberer = app(EstimateNumberer::class);

    expect($numberer->nextFor(2026))->toBe('OF-2026-001');
    expect($numberer->nextFor(2026))->toBe('OF-2026-002');
    expect($numberer->nextFor(2026))->toBe('OF-2026-003');
});

test('sequences are independent per year', function () {
    $numberer = app(EstimateNumberer::class);

    expect($numberer->nextFor(2026))->toBe('OF-2026-001');
    expect($numberer->nextFor(2027))->toBe('OF-2027-001');
    expect($numberer->nextFor(2026))->toBe('OF-2026-002');
});

test('counter row is created/updated atomically', function () {
    app(EstimateNumberer::class)->nextFor(2026);
    $row = EstimateCounter::find(2026);
    expect($row)->last_n->toBe(1);
});

test('counter persists across many allocations', function () {
    $numberer = app(EstimateNumberer::class);
    for ($i = 0; $i < 15; $i++) {
        $numberer->nextFor(2026);
    }
    expect(EstimateCounter::find(2026)->last_n)->toBe(15);
});
