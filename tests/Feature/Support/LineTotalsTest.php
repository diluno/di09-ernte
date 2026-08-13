<?php

use App\Support\LineTotals;

test('compute taxes every line at the document rate', function () {
    $totals = LineTotals::compute([10000, 5000], 8.10);

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(1215);   // 8.10% of 15000
    expect($totals['rounding_rappen'])->toBe(0);  // 16215 already on a 5-rappen boundary
    expect($totals['total_rappen'])->toBe(16215);
});

test('compute excludes VAT-exempt lines from the VAT base but not the subtotal', function () {
    $totals = LineTotals::compute([10000, 5000], 8.10, [false, true]);

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(810);    // 8.10% of the 10000 taxable line only
    expect($totals['total_rappen'])->toBe(15810);
});

test('compute with all lines exempt yields no VAT', function () {
    $totals = LineTotals::compute([10000, 5000], 8.10, [true, true]);

    expect($totals['vat_rappen'])->toBe(0);
    expect($totals['total_rappen'])->toBe(15000);
});

test('compute rounds the grand total up to the nearest 5 rappen', function () {
    // 29000 + 2349 VAT = 31349 -> rounds to 31350 (+1)
    $totals = LineTotals::compute([29000], 8.10);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
    ]);
});

test('compute rounds the grand total down to the nearest 5 rappen', function () {
    // subtotal+vat = 10286 -> rounds to 10285 (-1)
    $totals = LineTotals::compute([10286], 0.0);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 10286,
        'vat_rappen' => 0,
        'rounding_rappen' => -1,
        'total_rappen' => 10285,
    ]);
});

test('compute with a zero rate yields no VAT', function () {
    $totals = LineTotals::compute([10000], 0.0);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 10000,
        'vat_rappen' => 0,
        'rounding_rappen' => 0,
        'total_rappen' => 10000,
    ]);
});

test('compute always reconciles subtotal + vat + rounding to total', function () {
    foreach ([[12345], [9999, 1], [48000], [33333, 11111]] as $amounts) {
        $t = LineTotals::compute($amounts, 8.10);
        expect($t['subtotal_rappen'] + $t['vat_rappen'] + $t['rounding_rappen'])
            ->toBe($t['total_rappen']);
        expect($t['total_rappen'] % 5)->toBe(0);
    }
});
