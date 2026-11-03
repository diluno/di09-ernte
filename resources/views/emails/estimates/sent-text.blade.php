@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $estimate->client->defaultRecipients()[0]['name'] ?? null;
@endphp

{{ $contactName ? __('mail.greeting_named', ['name' => $contactName]) : __('mail.greeting') }}

{{ __('mail.estimate_intro', ['number' => $estimate->number]) }}

@if ($estimate->title)
{{ __('mail.subject_label') }}: {{ $estimate->title }}
@endif
{{ __('mail.estimate_amount') }}: {!! $fmt((int) $estimate->total_rappen) !!}
{{ __('mail.valid_until') }}: {{ optional($estimate->valid_until)->format('d.m.Y') }}

{{ __('mail.estimate_closing') }}

{{ __('mail.regards') }}
@if ($profile->sender_name)
{{ $profile->sender_name }}
@endif
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
