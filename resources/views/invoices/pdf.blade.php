<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>Rechnung {{ $invoice->number }}</title></head>
<body>
  <h1>Rechnung #{{ $invoice->number }}</h1>
  <p>{{ $invoice->client->name }}</p>
  <table>
    <tbody>
      @foreach ($invoice->lines as $line)
        <tr><td>{{ $line->description }}</td><td>{{ number_format($line->amount_rappen / 100, 2) }}</td></tr>
      @endforeach
    </tbody>
  </table>
  <p>Total: CHF {{ number_format($invoice->total_rappen / 100, 2) }}</p>
  {!! $qrBillHtml !!}
</body>
</html>
