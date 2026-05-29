<?php

use App\Support\LineTotals;

test('compute taxes every line at the document rate', function () {
    $totals = LineTotals::compute([10000, 5000], 8.10);

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(1215);   // 8.10% of 15000
    expect($totals['total_rappen'])->toBe(16215);
});

test('compute matches the original VAT formula', function () {
    $totals = LineTotals::compute([29000], 8.10);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'total_rappen' => 31349,
    ]);
});

test('compute with a zero rate yields no VAT', function () {
    $totals = LineTotals::compute([10000], 0.0);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 10000,
        'vat_rappen' => 0,
        'total_rappen' => 10000,
    ]);
});
