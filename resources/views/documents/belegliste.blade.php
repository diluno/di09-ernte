<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
@include('partials.pdf-fonts')
<style>
  @page { size: A4; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Beausite', 'Helvetica Neue', Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 8.5pt; line-height: 1.35; color: #141210; }
  header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid #141210; padding-bottom: 3mm; }
  h1 { margin: 0; font-size: 20pt; font-weight: 600; letter-spacing: -0.02em; line-height: 1; }
  .meta { font-size: 8pt; color: #5a554b; text-align: right; }
  .summary { margin: 3mm 0 0; font-size: 8.5pt; color: #5a554b; }
  .summary strong { color: #c8341f; font-weight: 600; }
  h2 { margin: 7mm 0 1mm; font-size: 12pt; font-weight: 600; }
  h2 small { display: block; margin-top: 0.5mm; font-size: 8pt; font-weight: 400; color: #5a554b; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  thead { display: table-header-group; }
  th { text-align: left; font-size: 7pt; font-weight: 400; letter-spacing: .08em; text-transform: uppercase; color: #7a7367; padding: 1.5mm 2mm 1.5mm 0; border-bottom: 1px solid #141210; }
  td { padding: 1.6mm 2mm 1.6mm 0; border-bottom: 1px solid #e3dccd; vertical-align: top; overflow-wrap: anywhere; }
  tr { break-inside: avoid; }
  .nr { width: 9mm; font-weight: 600; font-variant-numeric: tabular-nums; }
  .date { width: 13mm; color: #5a554b; font-variant-numeric: tabular-nums; }
  .amount { width: 23mm; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; padding-right: 4mm; }
  .amount small { display: block; font-size: 7pt; color: #7a7367; }
  .doc { width: 27%; }
  .note { width: 21%; }
  th.amount { text-align: right; }
  .missing .doc, .missing .nr { color: #c8341f; }
  .quiet { color: #7a7367; }
</style>
</head>
<body>
  <header>
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $company }}<br>Stand {{ $written }}</div>
  </header>
  <p class="summary">
    {{ $count }} Zeilen.
    @if ($missing > 0)
      <strong>{{ $missing }} ohne Beleg.</strong>
    @else
      Alle Belege vorhanden.
    @endif
    Die Nummer entspricht dem Präfix des Dateinamens im Ordner.
  </p>

  @foreach ($sections as $section)
    <h2>{{ $section['title'] }}@if ($section['meta'])<small>{{ $section['meta'] }}</small>@endif</h2>
    <table>
      <thead>
        <tr>
          <th class="nr">Nr.</th>
          <th class="date">Datum</th>
          <th>{{ $section['card'] ? 'Händler' : 'Buchung' }}</th>
          <th class="amount">CHF</th>
          <th class="doc">Beleg</th>
          <th class="note">Bemerkung</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($section['rows'] as $row)
          <tr class="{{ $row['status'] === 'missing' ? 'missing' : '' }}">
            <td class="nr">{{ $row['number'] }}</td>
            <td class="date">{{ $row['date'] }}</td>
            <td>{{ $row['text'] }}</td>
            <td class="amount">{{ $row['amount'] }}@if ($row['original'])<small>{{ $row['original'] }}</small>@endif</td>
            <td class="doc">@foreach ($row['documents'] as $document)<div>{{ $document }}</div>@endforeach</td>
            <td class="note">{{ $row['note'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach
</body>
</html>
