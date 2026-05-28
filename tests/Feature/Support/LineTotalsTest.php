<?php

use App\Support\LineTotals;

test('compute respects vat_exempt lines', function () {
    $totals = LineTotals::compute(
        lineAmounts: [10000, 5000],
        vatExempts: [false, true],
        vatRate: 8.10,
    );

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(810);   // 8.10% of the 10000 taxable line only
    expect($totals['total_rappen'])->toBe(15810);
});

test('compute with all taxable lines matches the original VAT formula', function () {
    $totals = LineTotals::compute(
        lineAmounts: [29000],
        vatExempts: [false],
        vatRate: 8.10,
    );

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen'      => 2349,
        'total_rappen'    => 31349,
    ]);
});
