<?php

use App\Models\InvoiceCounter;
use App\Services\Invoicing\InvoiceNumberer;

beforeEach(function () {
    $this->svc = app(InvoiceNumberer::class);
});

test('first number for a fresh year is YYYY-001', function () {
    expect($this->svc->nextFor(2026))->toBe('2026-001');
});

test('numbers increment sequentially within a year', function () {
    $this->svc->nextFor(2026);
    $this->svc->nextFor(2026);
    expect($this->svc->nextFor(2026))->toBe('2026-003');
});

test('years have independent counters', function () {
    $this->svc->nextFor(2026);
    $this->svc->nextFor(2026);
    expect($this->svc->nextFor(2027))->toBe('2027-001');
    expect($this->svc->nextFor(2026))->toBe('2026-003');
});

test('counter row is created/updated atomically', function () {
    $this->svc->nextFor(2026);
    $row = InvoiceCounter::find(2026);
    expect($row)->last_n->toBe(1);
});

test('counter persists across many allocations', function () {
    for ($i = 0; $i < 15; $i++) {
        $this->svc->nextFor(2026);
    }
    expect(InvoiceCounter::find(2026)->last_n)->toBe(15);
});
