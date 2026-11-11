@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $invoice->primaryRecipientName();
@endphp

{{ $contactName ? __('mail.greeting_named', ['name' => $contactName]) : __('mail.greeting') }}

{{ __('mail.invoice_intro', ['number' => $invoice->number]) }}

@if ($invoice->title)
{{ __('mail.subject_label') }}: {{ $invoice->title }}
@endif
{{ __('mail.invoice_amount') }}: {!! $fmt((int) $invoice->total_rappen) !!}
{{ __('mail.due_on') }}: {{ optional($invoice->due_on)->format('d.m.Y') }}

{{ __('mail.thanks') }}

{{ __('mail.regards') }}
@if ($profile->sender_name)
{{ $profile->sender_name }}
@endif
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
