<?php

use App\Models\Estimate;
use App\Support\EstimateProjections;
use App\Support\LineTotals;

test('building an estimate persists rounded total and rounding adjustment', function () {
    $estimate = Estimate::factory()->create(['vat_rate' => 8.10]);
    $estimate->lines()->create([
        'description' => 'Work',
        'hours' => 1,
        'rate_rappen' => 29000,
        'amount_rappen' => 29000,
        'sort_order' => 0,
    ]);

    // Recompute via the same path update() uses.
    $totals = LineTotals::compute([29000], 8.10);
    $estimate->subtotal_rappen = $totals['subtotal_rappen'];
    $estimate->vat_rappen = $totals['vat_rappen'];
    $estimate->rounding_rappen = $totals['rounding_rappen'];
    $estimate->total_rappen = $totals['total_rappen'];
    $estimate->save();

    expect($estimate->fresh()->rounding_rappen)->toBe(1);
    expect($estimate->fresh()->total_rappen)->toBe(31350);
});

test('detail projection exposes the rounding amount in CHF', function () {
    $estimate = Estimate::factory()->create([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
        'vat_rate' => 8.10,
    ]);

    $detail = EstimateProjections::detail($estimate);

    expect($detail['rounding'])->toBe(0.01);
    expect($detail['total'])->toBe(313.5);
});
