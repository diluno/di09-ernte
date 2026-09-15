@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $invoice->client->defaultRecipients()[0]['name'] ?? null;
@endphp

Guten Tag{{ $contactName ? ' ' . $contactName : '' }}

Anbei senden wir Ihnen die Rechnung {{ $invoice->number }} als PDF.

@if ($invoice->title)
Betreff: {{ $invoice->title }}
@endif
Rechnungsbetrag: {!! $fmt((int) $invoice->total_rappen) !!}
Fällig am: {{ optional($invoice->due_on)->format('d.m.Y') }}

Vielen Dank.

Freundliche Grüsse
@if ($profile->sender_name)
{{ $profile->sender_name }}
@endif
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
