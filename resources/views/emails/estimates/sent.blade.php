@extends('emails.layout')

@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $estimate->client->defaultRecipients()[0]['name'] ?? null;
@endphp

@section('title', __('mail.estimate_subject', ['number' => $estimate->number]))
@section('kind', __('mail.estimate_kind'))

@section('content')
  <p>{{ $contactName ? __('mail.greeting_named', ['name' => $contactName]) : __('mail.greeting') }}</p>

  <p>{{ __('mail.estimate_intro', ['number' => $estimate->number]) }}</p>

  @include('emails.partials.meta', ['rows' => [
      ['label' => __('mail.estimate_kind'), 'value' => $estimate->number],
      ...($estimate->title ? [['label' => __('mail.subject_label'), 'value' => $estimate->title, 'wrap' => true]] : []),
      ['label' => __('mail.estimate_amount'), 'value' => $fmt((int) $estimate->total_rappen), 'strong' => true],
      ['label' => __('mail.valid_until'), 'value' => optional($estimate->valid_until)->format('d.m.Y') ?? '—'],
  ]])

  <p>{{ __('mail.estimate_closing') }}</p>

  <p style="margin:0;">{{ __('mail.regards') }}<br>@if ($profile->sender_name){{ $profile->sender_name }}<br>@endif{{ $profile->name }}</p>
@endsection
