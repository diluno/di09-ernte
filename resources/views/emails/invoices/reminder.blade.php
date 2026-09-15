@extends('emails.layout')

@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $invoice->client->defaultRecipients()[0]['name'] ?? null;
@endphp

@section('title', "Zahlungserinnerung Rechnung {$invoice->number}")
@section('kind', 'Zahlungserinnerung')

@section('content')
  <p>Guten Tag{{ $contactName ? ' ' . $contactName : '' }}</p>

  <p>Wir möchten Sie freundlich an die noch offene Rechnung {{ $invoice->number }} erinnern.</p>

  @include('emails.partials.meta', ['rows' => [
      ['label' => 'Rechnung', 'value' => $invoice->number],
      ...($invoice->title ? [['label' => 'Betreff', 'value' => $invoice->title, 'wrap' => true]] : []),
      ['label' => 'Rechnungsbetrag', 'value' => $fmt((int) $invoice->total_rappen), 'strong' => true],
      ['label' => 'Fällig seit', 'value' => optional($invoice->due_on)->format('d.m.Y') ?? '—', 'red' => true],
  ]])

  <p>Falls die Zahlung bereits unterwegs ist, betrachten Sie diese Nachricht bitte als gegenstandslos.</p>

  <p style="margin:0;">Freundliche Grüsse<br>{{ $profile->name }}</p>
@endsection
