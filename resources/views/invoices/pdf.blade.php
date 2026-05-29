<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Rechnung {{ $invoice->number }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 40px; font-size: 12px; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 36px; }
    .label { font-size: 9px; letter-spacing: .08em; text-transform: uppercase; color: #6b6b6b; margin-bottom: 4px; }
    h1 { font-size: 22px; margin: 4px 0 0; }
    .doc-title { font-size: 16px; font-weight: 600; margin-top: 8px; max-width: 380px; }
    .cols { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-bottom: 28px; }
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 9px; letter-spacing: .08em; text-transform: uppercase; color: #6b6b6b; border-bottom: 1px solid #1a1a1a; padding: 8px 0; }
    thead th.num, tbody td.num { text-align: right; }
    tbody td { padding: 8px 0; border-bottom: 1px solid #e8e1d4; }
    .line-desc p { margin: 0; }
    .line-desc p + p { margin-top: 6px; }
    .line-desc ul, .line-desc ol { margin: 4px 0 0; padding-left: 18px; }
    .line-desc li { margin-bottom: 2px; }
    .totals { margin-top: 18px; width: 280px; margin-left: auto; display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; }
    .totals .v { text-align: right; }
    .totals .grand { font-weight: 700; font-size: 16px; border-top: 1px solid #1a1a1a; padding-top: 8px; }
    .totals .grand-l { border-top: 1px solid #1a1a1a; padding-top: 8px; font-weight: 600; }
    .qr { margin-top: 48px; border-top: 1px dashed #999; padding-top: 12px; }
    .notes { margin-top: 28px; font-size: 12px; color: #1a1a1a; line-height: 1.5; }
    .notes p { margin: 0 0 10px; }
    .notes ul, .notes ol { margin: 0 0 10px; padding-left: 20px; }
    .notes li { margin-bottom: 3px; }
    .notes hr { border: none; border-top: 1px solid #e8e1d4; margin: 18px 0; }
    .notes h1, .notes h2, .notes h3 { font-size: 13px; margin: 16px 0 6px; }
    .foot { margin-top: 28px; font-size: 10px; color: #6b6b6b; }
  </style>
	</head>
	@php
	  $money = fn ($rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
	  $rateLabel = fn ($rate) => rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');
	@endphp
<body>
  <div class="head">
    <div>
      <div class="label">Rechnung</div>
      <h1>#{{ $invoice->number }}</h1>
      @if ($invoice->title)<div class="doc-title">{{ $invoice->title }}</div>@endif
    </div>
    <div style="text-align: right">
      <div style="font-weight: 700">{{ $profile->name }}</div>
      <div style="color: #6b6b6b">{{ $profile->address_line_1 }}</div>
      <div style="color: #6b6b6b">{{ $profile->postal_code }} {{ $profile->city }}</div>
      @if ($profile->uid)<div style="color: #6b6b6b">{{ $profile->uid }}</div>@endif
    </div>
  </div>

  <div class="cols">
    <div>
      <div class="label">Rechnung an</div>
      <div style="font-weight: 600">{{ $invoice->client->name }}</div>
      <div style="color: #3d3d3d">{{ $invoice->client->contact_name }}</div>
      <div style="color: #6b6b6b">{{ $invoice->client->address_line_1 }}</div>
      <div style="color: #6b6b6b">{{ $invoice->client->postal_code }} {{ $invoice->client->city }}</div>
    </div>
    <div>
      <div class="label">Ausgestellt</div>
      <div style="font-weight: 600">{{ $invoice->issued_on?->format('d.m.Y') ?? '—' }}</div>
      <div class="label" style="margin-top: 14px">Fällig</div>
      <div style="font-weight: 600">{{ $invoice->due_on?->format('d.m.Y') ?? '—' }}</div>
    </div>
    <div>
      @if ($invoice->project)
        <div class="label">Projekt</div>
        <div style="font-weight: 600">{{ $invoice->project->name }}</div>
      @endif
      <div class="label" style="margin-top: 14px">Periode</div>
      <div style="color: #3d3d3d">{{ $invoice->period_start?->format('d.m.') }} – {{ $invoice->period_end?->format('d.m.Y') }}</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Beschreibung</th>
        <th class="num" style="width: 70px">Stunden</th>
        <th class="num" style="width: 90px">Ansatz</th>
        <th class="num" style="width: 110px">Betrag</th>
      </tr>
    </thead>
	    <tbody>
	      @foreach ($invoice->lines as $line)
	        <tr>
	          <td class="line-desc">{!! \App\Support\Markdown::toHtml($line->description) !!}</td>
	          <td class="num">{{ number_format((float) $line->hours, 2) }}</td>
	          <td class="num">{{ $money($line->rate_rappen) }}</td>
	          <td class="num">{{ $money($line->amount_rappen) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

	  <div class="totals">
	    <div>Zwischensumme</div><div class="v">{{ $money($invoice->subtotal_rappen) }}</div>
	    <div>MwSt {{ $rateLabel($invoice->vat_rate) }}%</div><div class="v">{{ $money($invoice->vat_rappen) }}</div>
	    <div class="grand-l">Total</div><div class="v grand">{{ $money($invoice->total_rappen) }}</div>
	  </div>

  @if ($invoice->notes)
    <div class="notes">{!! \App\Support\Markdown::toHtml($invoice->notes) !!}</div>
  @endif

  <div class="foot">
    <div>Zahlbar innert 30 Tagen.</div>
    @if ($profile->email)<div>{{ $profile->email }}</div>@endif
  </div>

  <div class="qr">
    {!! $qrBillHtml !!}
  </div>
</body>
</html>
