@extends('emails.layout')

@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
    $contactName = $invoice->client->defaultRecipients()[0]['name'] ?? null;
@endphp

@section('title', "Rechnung {$invoice->number}")
@section('kind', 'Rechnung')

@section('content')
  <p>Guten Tag{{ $contactName ? ' ' . $contactName : '' }}</p>

  <p>Anbei senden wir Ihnen die Rechnung {{ $invoice->number }} als PDF.</p>

  @include('emails.partials.meta', ['rows' => [
      ['label' => 'Rechnung', 'value' => $invoice->number],
      ['label' => 'Rechnungsbetrag', 'value' => $fmt((int) $invoice->total_rappen), 'strong' => true],
      ['label' => 'Fällig am', 'value' => optional($invoice->due_on)->format('d.m.Y') ?? '—'],
  ]])

  <p>Vielen Dank.</p>

  <p style="margin:0;">Freundliche Grüsse<br>{{ $profile->name }}</p>
@endsection
