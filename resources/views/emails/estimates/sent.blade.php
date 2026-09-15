@extends('emails.layout')

@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $estimate->client->defaultRecipients()[0]['name'] ?? null;
@endphp

@section('title', "Offerte {$estimate->number}")
@section('kind', 'Offerte')

@section('content')
  <p>Guten Tag{{ $contactName ? ' ' . $contactName : '' }}</p>

  <p>Anbei senden wir Ihnen unsere Offerte {{ $estimate->number }} als PDF.</p>

  @include('emails.partials.meta', ['rows' => [
      ['label' => 'Offerte', 'value' => $estimate->number],
      ...($estimate->title ? [['label' => 'Betreff', 'value' => $estimate->title, 'wrap' => true]] : []),
      ['label' => 'Offertbetrag', 'value' => $fmt((int) $estimate->total_rappen), 'strong' => true],
      ['label' => 'Gültig bis', 'value' => optional($estimate->valid_until)->format('d.m.Y') ?? '—'],
  ]])

  <p>Wir freuen uns auf Ihre Rückmeldung.</p>

  <p style="margin:0;">Freundliche Grüsse<br>@if ($profile->sender_name){{ $profile->sender_name }}<br>@endif{{ $profile->name }}</p>
@endsection
