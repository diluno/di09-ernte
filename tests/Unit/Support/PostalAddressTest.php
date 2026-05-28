<?php

use App\Support\PostalAddress;

test('splits a Swiss street + PLZ/city address', function () {
    expect(PostalAddress::parse("Stauffacherstrasse 101\n8004 Zürich"))->toBe([
        'address_line_1' => 'Stauffacherstrasse 101',
        'address_line_2' => null,
        'postal_code' => '8004',
        'city' => 'Zürich',
    ]);
});

test('keeps a leading company/extra line as address_line_2', function () {
    expect(PostalAddress::parse("Atlas AG\nBahnhofstrasse 1\n8001 Zürich"))->toBe([
        'address_line_1' => 'Atlas AG',
        'address_line_2' => 'Bahnhofstrasse 1',
        'postal_code' => '8001',
        'city' => 'Zürich',
    ]);
});

test('handles a CH- prefixed postal code', function () {
    $a = PostalAddress::parse("Rue du Test 5\nCH-1003 Lausanne");
    expect($a['postal_code'])->toBe('1003');
    expect($a['city'])->toBe('Lausanne');
});

test('ignores a trailing country line', function () {
    $a = PostalAddress::parse("Bahnhofstrasse 1\n8001 Zürich\nSwitzerland");
    expect($a['address_line_1'])->toBe('Bahnhofstrasse 1');
    expect($a['postal_code'])->toBe('8001');
    expect($a['city'])->toBe('Zürich');
});

test('normalises CRLF line endings', function () {
    $a = PostalAddress::parse("Bahnhofstrasse 1\r\n8001 Zürich");
    expect($a['address_line_1'])->toBe('Bahnhofstrasse 1');
    expect($a['city'])->toBe('Zürich');
});

test('falls back to a joined single line when no postal code is found', function () {
    expect(PostalAddress::parse("Somewhere\nover the rainbow"))->toBe([
        'address_line_1' => 'Somewhere, over the rainbow',
        'address_line_2' => null,
        'postal_code' => null,
        'city' => null,
    ]);
});

test('returns all-null for empty input', function () {
    expect(PostalAddress::parse(null))->toBe([
        'address_line_1' => null,
        'address_line_2' => null,
        'postal_code' => null,
        'city' => null,
    ]);
});

test('truncates an over-long single line to 255 characters', function () {
    $a = PostalAddress::parse(str_repeat('x', 400));
    expect(mb_strlen($a['address_line_1']))->toBe(255);
});
