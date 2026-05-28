<?php

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
