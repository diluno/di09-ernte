@extends('emails.layout')

@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $invoice->primaryRecipientName();
@endphp

@section('title', __('mail.reminder_subject', ['number' => $invoice->number]))
@section('kind', __('mail.reminder_kind'))

@section('content')
  <p>{{ $contactName ? __('mail.greeting_named', ['name' => $contactName]) : __('mail.greeting') }}</p>

  <p>{{ __('mail.reminder_intro', ['number' => $invoice->number]) }}</p>

  @include('emails.partials.meta', ['rows' => [
      ['label' => __('mail.invoice_kind'), 'value' => $invoice->number],
      ...($invoice->title ? [['label' => __('mail.subject_label'), 'value' => $invoice->title, 'wrap' => true]] : []),
      ['label' => __('mail.invoice_amount'), 'value' => $fmt((int) $invoice->total_rappen), 'strong' => true],
      ['label' => __('mail.overdue_since'), 'value' => optional($invoice->due_on)->format('d.m.Y') ?? '—', 'red' => true],
  ]])

  <p>{{ __('mail.reminder_disregard') }}</p>

  <p style="margin:0;">{{ __('mail.regards') }}<br>@if ($profile->sender_name){{ $profile->sender_name }}<br>@endif{{ $profile->name }}</p>
@endsection
