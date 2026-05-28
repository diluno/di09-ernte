@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
@endphp

Guten Tag{{ $invoice->client->contact_name ? ' ' . $invoice->client->contact_name : '' }}

Wir möchten Sie freundlich an die noch offene Rechnung {{ $invoice->number }} erinnern.

Rechnungsbetrag: {!! $fmt((int) $invoice->total_rappen) !!}
Fällig seit: {{ optional($invoice->due_on)->format('d.m.Y') }}

Falls die Zahlung bereits unterwegs ist, betrachten Sie diese Nachricht bitte als gegenstandslos.

Freundliche Grüsse
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
