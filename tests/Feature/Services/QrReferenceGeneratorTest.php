<?php

use App\Services\Invoicing\QrReferenceGenerator;

beforeEach(function () {
    $this->svc = app(QrReferenceGenerator::class);
});

test('reference is exactly 27 digits', function () {
    $ref = $this->svc->generate(42);
    expect(strlen($ref))->toBe(27);
    expect($ref)->toMatch('/^\d{27}$/');
});

test('reference embeds the invoice id in the right-most digits before the check digit', function () {
    $ref = $this->svc->generate(42);
    $payload = substr($ref, 0, 26);
    expect(substr($payload, -6))->toBe('000042');
});

test('the trailing check digit is the mod-10 recursive of the first 26', function () {
    $ref = $this->svc->generate(42);
    $payload = substr($ref, 0, 26);
    $check = (int) substr($ref, -1);

    expect($check)->toBe($this->svc->checkDigit($payload));
});

test('checkDigit known vector: empty string yields 0', function () {
    expect($this->svc->checkDigit(''))->toBe(0);
});

test('checkDigit known vector: Swiss spec example', function () {
    // From the Swiss Implementation Guidelines QR-bill.
    // Payload (26 digits): 21000000000313947143000901
    // Expected check digit: 7
    expect($this->svc->checkDigit('21000000000313947143000901'))->toBe(7);
});

test('two references for the same id are deterministic', function () {
    expect($this->svc->generate(42))->toBe($this->svc->generate(42));
});
