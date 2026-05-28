@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
@endphp

Guten Tag{{ $estimate->client->contact_name ? ' ' . $estimate->client->contact_name : '' }}

Anbei senden wir Ihnen unsere Offerte {{ $estimate->number }} als PDF.

Offertbetrag: {!! $fmt((int) $estimate->total_rappen) !!}
Gültig bis: {{ optional($estimate->valid_until)->format('d.m.Y') }}

Wir freuen uns auf Ihre Rückmeldung.

Freundliche Grüsse
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
