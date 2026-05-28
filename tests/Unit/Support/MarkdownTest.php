<?php

use App\Support\Markdown;

test('renders bold emphasis', function () {
    expect(Markdown::toHtml('Stundensatz: **CHF 160.–**'))
        ->toContain('<strong>CHF 160.–</strong>');
});

test('renders a horizontal rule from ---', function () {
    expect(Markdown::toHtml("Liebe Grüsse\n\n---\n\nSpezifikationen"))
        ->toContain('<hr');
});

test('renders bullet lists', function () {
    $html = Markdown::toHtml("Die Website umfasst:\n\n- Informationsbereich\n- Checklisten-System");

    expect($html)->toContain('<ul>');
    expect($html)->toContain('<li>Informationsbereich</li>');
    expect($html)->toContain('<li>Checklisten-System</li>');
});

test('preserves single line breaks as <br>', function () {
    expect(Markdown::toHtml("Liebe Aline\nBesten Dank für eure Anfrage"))
        ->toContain('Liebe Aline<br>');
});

test('escapes raw HTML to prevent injection', function () {
    $html = Markdown::toHtml('Hallo <script>alert(1)</script>');

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');
});

test('returns an empty string for empty input', function () {
    expect(Markdown::toHtml(''))->toBe('');
});
