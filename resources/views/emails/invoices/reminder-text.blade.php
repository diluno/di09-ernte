@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $invoice->primaryRecipientName();
@endphp

{{ $contactName ? __('mail.greeting_named', ['name' => $contactName]) : __('mail.greeting') }}

{{ __('mail.reminder_intro', ['number' => $invoice->number]) }}

@if ($invoice->title)
{{ __('mail.subject_label') }}: {{ $invoice->title }}
@endif
{{ __('mail.invoice_amount') }}: {!! $fmt((int) $invoice->total_rappen) !!}
{{ __('mail.overdue_since') }}: {{ optional($invoice->due_on)->format('d.m.Y') }}

{{ __('mail.reminder_disregard') }}

{{ __('mail.regards') }}
@if ($profile->sender_name)
{{ $profile->sender_name }}
@endif
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
