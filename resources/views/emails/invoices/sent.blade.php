@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
@endphp

Guten Tag{{ $invoice->client->contact_name ? ' ' . $invoice->client->contact_name : '' }}

Anbei senden wir Ihnen die Rechnung {{ $invoice->number }} als PDF.

Rechnungsbetrag: {!! $fmt((int) $invoice->total_rappen) !!}
Fällig am: {{ optional($invoice->due_on)->format('d.m.Y') }}

Vielen Dank.

Freundliche Grüsse
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
