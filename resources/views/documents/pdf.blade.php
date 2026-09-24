{{--
  Shared document sheet for invoices ("Rechnung") and estimates ("Offerte").

  Geometry is in millimetres so the printed sheet matches Swiss letter
  conventions: sender line and recipient block sit in the C5 window zone
  (20mm from the left, 45mm from the top), the payment part fills the bottom
  105mm of a short invoice. Chrome renders the same HTML for the in-app
  preview (scaled to fit the sheet frame) and for the PDF.

  $doc      Invoice|Estimate
  $kind     'invoice'|'estimate'
  $profile  BusinessProfile
  $qrBillHtml  string|null  (invoices only)
--}}
@php
  $isInvoice = $kind === 'invoice';
  $label = $isInvoice ? 'Rechnung' : 'Offerte';

  $chf = fn ($rappen) => number_format($rappen / 100, 2, '.', '’');
  $rateLabel = fn ($rate) => rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');
  $hours = fn ($h) => number_format((float) $h, 1, '.', '’');
  $date = fn ($d) => $d?->format('d.m.Y') ?? '';

  $client = $doc->client;

  $untilLabel = $isInvoice ? 'Fällig' : 'Gültig bis';
  $until = $isInvoice ? $doc->due_on : $doc->valid_until;
  $termsDays = ($isInvoice && $doc->issued_on && $doc->due_on) ? (int) $doc->issued_on->diffInDays($doc->due_on) : null;

  $showPeriod = $isInvoice && $doc->period_start && $doc->period_end && ! $doc->period_start->isSameDay($doc->period_end);
  $anyExempt = $doc->lines->contains(fn ($l) => (bool) ($l->vat_exempt ?? false));

  $senderLine = implode(' · ', array_filter([
      $profile->name, $profile->address_line_1, trim(($profile->postal_code ?? '').' '.($profile->city ?? '')),
  ]));

  // ── Estimates only: structured scope (sections, titled lines, shared rate). ──
  // Lines without a section form one unnamed group, so estimates written
  // before sections existed render as a single plain list.
  $groups = $isInvoice ? [] : \App\Support\EstimateScope::groups($doc);
  $hasSections = ! $isInvoice && \App\Support\EstimateScope::hasNamedSections($groups);
  $uniformRate = $isInvoice ? null : \App\Support\EstimateScope::uniformRateRappen($doc);
  $showRateColumn = $isInvoice || ($uniformRate === null && $doc->lines->isNotEmpty());
  $detailCols = $showRateColumn ? 4 : 3;
  // Whole francs as "15’600.–", otherwise "5’127.30".
  $money = fn ($rappen) => $rappen % 100 === 0
      ? number_format(intdiv((int) $rappen, 100), 0, '.', '’').'.–'
      : number_format($rappen / 100, 2, '.', '’');
  // Totals block: invoices keep "1’234.00"; estimates match the rest of the sheet.
  $sum = fn ($rappen) => $isInvoice ? $chf($rappen) : $money($rappen);
  $effort = fn ($h) => rtrim(rtrim(number_format((float) $h, 2, '.', '’'), '0'), '.').' h';
  $assumptions = $isInvoice ? [] : array_values(array_filter((array) ($doc->assumptions ?? []), fn ($a) => filled($a)));
@endphp
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>{{ $label }} {{ $doc->number }}</title>
  @include('partials.pdf-fonts')
  <style>
    :root {
      --ink: #141210; --ink-2: #5a554b; --ink-3: #7a7367;
      --border: #e3dccd; --border-strong: #c9c2b3; --red: #c8341f;
      --sans: 'Beausite', 'Helvetica Neue', Arial, sans-serif;
      --mono: 'Iosevka Aile', ui-monospace, Menlo, monospace;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      font-family: var(--sans);
      color: var(--ink);
      font-size: 10.5pt;
      line-height: 1.45;
      width: 210mm;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    @page { size: A4; margin: 0; }
    /* The inline script below lays the document out into explicit A4 pages so
       the running page line never collides with content and the payment slip
       sits flush at the bottom edge of the last page. */
    .sheet { display: flex; flex-direction: column; }
    .body { padding: 0 20mm 6mm; }
    .page { position: relative; height: 297mm; display: flex; flex-direction: column; overflow: hidden; break-after: page; page-break-after: always; }
    .page:last-child { break-after: auto; page-break-after: auto; }
    .page > .content { padding: 0 20mm; }
    .page + .page > .content { padding-top: 12mm; }
    .page > .qr { margin-top: auto; }
    .page-foot { position: absolute; left: 20mm; right: 20mm; bottom: 8mm; display: flex; justify-content: space-between; font-family: var(--mono); font-size: 7.5pt; color: var(--ink-3); letter-spacing: .04em; }
    @media screen {
      /* In-app preview: fit the 210mm sheet into the ~640px frame. */
      html { overflow-x: hidden; }
      body { zoom: 0.78; background: #fbf9f4; }
      .page + .page { border-top: 1px dashed #c9c2b3; }
    }

    /* ── Header: logo is the one expressive element; everything else is set small. ── */
    .logo { padding: 12mm 0 0; height: 27mm; }
    .logo svg { display: block; height: 15mm; width: 100%; }
    .sender { font-family: var(--mono); font-size: 7.5pt; color: var(--ink-3); letter-spacing: .02em; height: 6mm; border-bottom: 1px solid var(--ink); }

    /* ── Window zone: 45mm from the page top, recipient left / meta right. ── */
    .window { display: grid; grid-template-columns: 90mm 1fr; gap: 10mm; padding: 8mm 0 4mm; min-height: 36mm; }
    .address { font-size: 10.5pt; line-height: 1.45; }
    .address .name { font-weight: 600; }
    .meta { font-family: var(--mono); font-size: 8.5pt; line-height: 1.7; color: var(--ink-3); display: grid; grid-template-columns: max-content 1fr; gap: 0 5mm; align-content: start; }
    .meta b { color: var(--ink); font-weight: 600; }
    .meta .kind { grid-column: 1 / -1; font-family: var(--sans); font-size: 8pt; letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3); margin-bottom: 1.5mm; }
    .meta .is-red { color: var(--red); }

    /* ── Title ── */
    .doc h1 { font-size: 20pt; font-weight: 600; letter-spacing: -0.02em; line-height: 1.1; margin: 0 0 5mm; padding-bottom: 3mm; border-bottom: 1px solid var(--ink); }

    /* ── Lines ── */
    .doc table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    .doc thead th { text-align: left; font-size: 7.5pt; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-3); font-weight: 400; padding: 0 0 2.5mm; border-bottom: 1px solid var(--border); }
    .doc thead th.num, .doc tbody td.num { text-align: right; }
    .doc tbody td { padding: 2.5mm 0; border-bottom: 1px solid var(--border); vertical-align: top; }
    .doc tbody td.num { font-family: var(--mono); font-size: 9pt; color: var(--ink-3); white-space: nowrap; }
    .doc tbody td.amount { font-family: var(--mono); font-size: 10pt; color: var(--ink); }
    .doc tbody td.num, .doc tbody td.amount { padding-left: 4mm; }
    .line-desc p { margin: 0; }
    .line-desc p + p { margin-top: 1.5mm; }
    .line-desc ul, .line-desc ol { margin: 1mm 0 0; padding-left: 5mm; }
    .line-desc li { margin-bottom: .5mm; }
    .exempt-mark { color: var(--ink-3); }
    .exempt-note { font-size: 8pt; color: var(--ink-3); margin-top: 2mm; }

    /* ── Totals ── */
    .totals { margin: 5mm 0 0 auto; width: 80mm; display: grid; grid-template-columns: 1fr max-content; gap: 1.5mm 6mm; font-family: var(--mono); font-size: 9pt; font-variant-numeric: tabular-nums; break-inside: avoid; page-break-inside: avoid; }
    .totals .l { color: var(--ink-3); }
    .totals .v { text-align: right; color: var(--ink); }
    .totals .grand-l, .totals .grand { border-top: 1px solid var(--ink); padding-top: 2mm; margin-top: 1mm; color: var(--ink); font-weight: 600; }
    .totals .grand { font-size: 12pt; }

    /* ── Notes / foot ── */
    /* Notes are markdown. ## is a chapter (starts a new page, see the pagination
       script), ### a section, #### a subsection; headings stay with what follows. */
    .notes { margin-top: 8mm; font-size: 10pt; line-height: 1.5; }
    .notes p { margin: 0 0 2.5mm; }
    .notes strong { font-weight: 600; }
    .notes a { color: inherit; text-decoration: underline; text-decoration-color: var(--border-strong); text-underline-offset: .6mm; }
    .notes ul, .notes ol { margin: 0 0 3mm; padding-left: 0; }
    .notes ol { padding-left: 5mm; }
    .notes ul { list-style: none; }
    .notes ul li { position: relative; padding-left: 4.5mm; }
    .notes ul li::before { content: '–'; position: absolute; left: 0; color: var(--ink-3); }
    .notes li { margin-bottom: .8mm; }
    .notes hr { border: none; border-top: 1px solid var(--border); margin: 6mm 0; }
    .notes h1, .notes h2 { font-size: 15pt; font-weight: 600; letter-spacing: -0.015em; line-height: 1.15; margin: 0 0 6mm; padding-bottom: 3mm; border-bottom: 1px solid var(--ink); }
    .notes h3 { font-size: 10.5pt; font-weight: 600; margin: 8mm 0 3mm; padding-bottom: 2mm; border-bottom: 1px solid var(--border); }
    .notes h4, .notes h5, .notes h6 { font-size: 10pt; font-weight: 600; margin: 5mm 0 1.5mm; }
    .notes h2 + h3 { margin-top: 0; }
    .notes h3 + h4 { margin-top: 0; }
    .notes table { margin: 1mm 0 4mm; font-size: 9.5pt; }
    .notes thead th { padding: 0 0 2mm; }
    .notes tbody td { padding: 1.6mm 4mm 1.6mm 0; }
    .notes tbody td:first-child { color: var(--ink-2); }
    .notes th[align="right"], .notes td[align="right"] { text-align: right; padding-right: 0; font-family: var(--mono); font-size: 9pt; font-variant-numeric: tabular-nums; }
    .notes th[align="right"] { font-family: var(--sans); font-size: 7.5pt; }
    .foot { margin-top: 5mm; padding-top: 3mm; border-top: 1px solid var(--border); font-size: 8.5pt; color: var(--ink-3); display: flex; justify-content: space-between; gap: 6mm; }
    .foot b { color: var(--ink); font-weight: 600; }

    /* ── Estimates: compact header, summary, package overview, sectioned scope. ── */
    .is-estimate .logo { padding-top: 10mm; height: 23mm; }
    .is-estimate .logo svg { height: 13mm; }
    .is-estimate .window { min-height: 30mm; padding-bottom: 8mm; }
    .is-estimate h1 { margin-bottom: 0; padding-bottom: 4mm; }

    .summary { display: grid; grid-template-columns: repeat(3, 1fr); border-bottom: 1px solid var(--border); margin: 8mm 0 8mm; }
    .summary .kpi { padding: 1mm 4mm 4mm; text-align: right; }
    .summary .kpi:first-child { padding-left: 0; }
    .summary .kpi:last-child { padding-right: 0; }
    .summary .kpi + .kpi { border-left: 1px solid var(--border); }
    .summary .kpi:first-child { text-align: left; }
    .summary .kpi span { display: block; font-size: 7.5pt; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-3); margin-bottom: 2mm; }
    .summary .kpi b { font-family: var(--mono); font-size: 13pt; font-weight: 600; font-variant-numeric: tabular-nums; }
    .summary .kpi.grand b { font-size: 15pt; }

    .caption { font-size: 7.5pt; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-3); margin: 0 0 2mm; font-weight: 400; }
    .doc table.overview { margin-bottom: 2mm; }
    .doc table.overview thead th, .doc table.detail thead th { padding-bottom: 3mm; }
    .doc table.overview tbody tr:first-child td { padding-top: 2.5mm; }
    .doc table.overview td { padding: 1.6mm 0; }
    .overview .ov-label { font-weight: 600; }
    .overview .ov-title { color: var(--ink-2); margin-left: 2mm; }
    .rate-note { font-family: var(--mono); font-size: 8.5pt; color: var(--ink-3); margin: 0 0 7mm; text-align: right; }
    .rate-note b { color: var(--ink); font-weight: 600; }
    .scope-h { margin: 0 0 4mm; font-size: 8pt; color: var(--ink-2); }
    .overview + .scope-h { margin-top: 9mm; }
    .rate-note + .scope-h { margin-top: 2mm; }
    .doc tbody tr.sec-total + tr.sec-head td { border-top: 0; }

    .doc tbody tr.sec-head td { background: #f3efe6; border-bottom: 1px solid var(--border-strong); border-top: 1px solid var(--border-strong); padding: 3mm 3mm; }
    .doc tbody tr.sec-head + tr td { padding-top: 3.5mm; }
    .sec-label { font-weight: 600; }
    .sec-title { color: var(--ink-2); }
    .sec-label + .sec-title::before { content: ' — '; }
    .sec-cont { display: none; color: var(--ink-3); font-size: 8.5pt; }
    tr.sec-head.is-cont .sec-cont { display: inline; }
    .doc tbody tr.item td { padding: 2.5mm 0; }
    .item-title { font-weight: 600; line-height: 1.3; }
    .item-desc { color: var(--ink-2); font-size: 9.5pt; line-height: 1.35; margin-top: .8mm; }
    .item-desc p { margin: 0; }
    .item-desc p + p { margin-top: 1mm; }
    .item-desc ul, .item-desc ol { margin: .5mm 0 0; padding-left: 5mm; }
    .doc tbody tr.sec-total td { padding: 2.5mm 0 7mm; border-bottom: 1px solid var(--ink); font-size: 9pt; }
    .doc tbody tr.sec-total td.sec-total-l { color: var(--ink-3); font-size: 8pt; letter-spacing: .08em; text-transform: uppercase; }
    .doc tbody tr.sec-total td.amount { font-weight: 600; }
    .is-estimate .doc tbody tr.item td.amount { font-weight: 500; }

    .assumptions { margin-top: 9mm; font-size: 9.5pt; line-height: 1.4; }
    .assumptions h3 { font-size: 10.5pt; font-weight: 600; margin: 0 0 3mm; padding-bottom: 2.5mm; border-bottom: 1px solid var(--border); }
    .assumption { position: relative; padding-left: 4.5mm; margin-bottom: 1.4mm; color: var(--ink-2); }
    .assumption::before { content: '–'; position: absolute; left: 0; color: var(--ink-3); }

    /* ── Payment part: full 210mm width, kept whole, pushed to the sheet bottom. ── */
    /* separator (5mm) + payment part (105mm) = 110mm; no extra margin so a short
       invoice still fits the 287mm sheet with the slip at the bottom edge. */
    .qr { break-inside: avoid; page-break-inside: avoid; flex: 0 0 auto; }
    .qr .qr-bill { margin: 0 auto; }
  </style>
</head>
<body>
<div @class(["sheet", "is-estimate" => ! $isInvoice])>
  <div class="body doc">
    <div class="logo">{!! \App\Support\GenerativeLogo::inlineSvg(crc32((string) $doc->number), color: '#141210') !!}</div>
    <div class="sender">{{ $senderLine }}</div>

    <div class="window">
      <div class="address">
        <div class="name">{{ $client->name }}</div>
        @if ($client->address_line_1)<div>{{ $client->address_line_1 }}</div>@endif
        @if ($client->address_line_2)<div>{{ $client->address_line_2 }}</div>@endif
        <div>{{ trim(($client->postal_code ?? '').' '.($client->city ?? '')) }}</div>
      </div>
      <div class="meta">
        <span class="kind">{{ $label }}</span>
        <span>Nr.</span><b>{{ $doc->number }}</b>
        @if ($doc->issued_on)<span>Datum</span><b>{{ $date($doc->issued_on) }}</b>@endif
        @if ($until)<span>{{ $untilLabel }}</span><b @class(['is-red' => $isInvoice && $doc->overdue])>{{ $date($until) }}</b>@endif
        @if ($doc->project)<span>Projekt</span><span>{{ $doc->project->name }}</span>@endif
        @if ($showPeriod)<span>Periode</span><span>{{ $doc->period_start->format('d.m.') }} – {{ $doc->period_end->format('d.m.Y') }}</span>@endif
        @if ($profile->uid)<span>UID</span><span>{{ $profile->uid }}</span>@endif
      </div>
    </div>

    <h1>{{ $doc->title ?: $label.' '.$doc->number }}</h1>

    @if ($isInvoice)
    <table>
      <thead>
        <tr>
          <th>Leistung</th>
          <th class="num" style="width: 16mm">Std.</th>
          <th class="num" style="width: 20mm">Ansatz</th>
          <th class="num" style="width: 26mm">CHF</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($doc->lines as $line)
          <tr>
            <td class="line-desc">{!! \App\Support\Markdown::toHtml($line->description) !!}</td>
            <td class="num">{{ $hours($line->hours) }}</td>
            <td class="num">{{ $chf($line->rate_rappen) }}</td>
            <td class="num amount">{{ $chf($line->amount_rappen) }}@if ($line->vat_exempt ?? false)<span class="exempt-mark">*</span>@endif</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    @else
      <div class="summary">
        <div class="kpi"><span>Aufwand</span><b>{{ $effort(\App\Support\EstimateScope::totalHours($doc)) }}</b></div>
        <div class="kpi"><span>Exkl. MwSt</span><b>CHF {{ $money($doc->subtotal_rappen) }}</b></div>
        <div class="kpi grand"><span>Inkl. MwSt</span><b>CHF {{ $money($doc->total_rappen) }}</b></div>
      </div>

      @if ($hasSections)
        <table class="overview" data-glue>
          <thead>
            <tr>
              <th>Übersicht</th>
              <th class="num" style="width: 22mm">Aufwand</th>
              <th class="num" style="width: 30mm">Betrag</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($groups as $g)
              <tr>
                <td>
                  @if ($g['section']?->label)<span class="ov-label">{{ $g['section']->label }}</span>@endif
                  @if ($g['section']?->title)<span @class(['ov-title' => (bool) $g['section']?->label, 'ov-label' => ! $g['section']?->label])>{{ $g['section']->title }}</span>@endif
                  @if (! $g['heading'])<span class="ov-label">Weitere Leistungen</span>@endif
                </td>
                <td class="num">{{ $effort($g['hours']) }}</td>
                <td class="num amount">CHF {{ $money($g['amount_rappen']) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
      @if ($uniformRate !== null)
        <div class="rate-note">Stundensatz: <b>CHF {{ $money($uniformRate) }}</b></div>
      @endif

      @if ($hasSections)<h2 class="caption scope-h" data-glue>Leistungen im Detail</h2>@endif
      <table class="detail">
        <thead>
          <tr>
            <th>Leistung</th>
            <th class="num" style="width: 20mm">Aufwand</th>
            @if ($showRateColumn)<th class="num" style="width: 20mm">Ansatz</th>@endif
            <th class="num" style="width: 28mm">Betrag</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($groups as $k => $g)
            @if ($g['heading'])
              <tr class="sec-head" data-section="{{ $k }}" data-glue>
                <td colspan="{{ $detailCols }}">
                  @if ($g['section']->label)<span class="sec-label">{{ $g['section']->label }}</span>@endif
                  @if ($g['section']->title)<span @class(['sec-title' => (bool) $g['section']->label, 'sec-label' => ! $g['section']->label])>{{ $g['section']->title }}</span>@endif
                  <span class="sec-cont">(Fortsetzung)</span>
                </td>
              </tr>
            @endif
            @foreach ($g['lines'] as $line)
              <tr class="item" data-section="{{ $k }}" @if ($g['heading'] && $loop->last) data-glue @endif>
                <td class="line-desc">
                  @if (filled($line->title))
                    <div class="item-title">{{ $line->title }}</div>
                    @if (filled($line->description))<div class="item-desc">{!! \App\Support\Markdown::toHtml($line->description) !!}</div>@endif
                  @else
                    {!! \App\Support\Markdown::toHtml($line->description) !!}
                  @endif
                </td>
                <td class="num">{{ $effort($line->hours) }}</td>
                @if ($showRateColumn)<td class="num">{{ $money($line->rate_rappen) }}</td>@endif
                <td class="num amount">{{ $money($line->amount_rappen) }}</td>
              </tr>
            @endforeach
            @if ($g['heading'])
              <tr class="sec-total" data-section="{{ $k }}">
                <td class="sec-total-l">Total {{ $g['section']->label ?: $g['section']->title }}</td>
                <td class="num">{{ $effort($g['hours']) }}</td>
                @if ($showRateColumn)<td></td>@endif
                <td class="num amount">{{ $money($g['amount_rappen']) }}</td>
              </tr>
            @endif
          @endforeach
        </tbody>
      </table>
    @endif
    @if ($anyExempt)<div class="exempt-note">* ohne MwSt</div>@endif

    <div class="totals">
      <span class="l">{{ $isInvoice ? 'Zwischensumme' : 'Total exkl. MwSt' }}</span><span class="v">{{ $sum($doc->subtotal_rappen) }}</span>
      <span class="l">MwSt {{ $rateLabel($doc->vat_rate) }}%</span><span class="v">{{ $sum($doc->vat_rappen) }}</span>
      @if ($doc->rounding_rappen != 0)
        <span class="l">Rundung</span><span class="v">{{ $sum($doc->rounding_rappen) }}</span>
      @endif
      <span class="grand-l">{{ $isInvoice ? 'Total CHF' : 'Total inkl. MwSt' }}</span><span class="v grand">{{ $sum($doc->total_rappen) }}</span>
    </div>

    @if ($assumptions)
      <div class="assumptions" data-split>
        <h3 data-glue>Grundlagen der Schätzung</h3>
        @foreach ($assumptions as $assumption)
          <div class="assumption">{{ $assumption }}</div>
        @endforeach
      </div>
    @endif

    @if ($doc->notes)
      <div class="notes" data-split>{!! \App\Support\Markdown::toHtml($doc->notes) !!}</div>
    @endif

    <div class="foot">
      <span>
        @if ($isInvoice)
          @if ($doc->due_on)Zahlbar bis <b>{{ $date($doc->due_on) }}</b>@if ($termsDays !== null) ({{ $termsDays }} Tage)@endif.@else Zahlbar innert 30 Tagen.@endif
        @else
          @if ($doc->valid_until)Dieses Angebot ist gültig bis <b>{{ $date($doc->valid_until) }}</b>.@endif
        @endif
      </span>
      <span>{{ implode(' · ', array_filter([$profile->name, $profile->email])) }}</span>
    </div>
  </div>

  @if ($isInvoice && $qrBillHtml)
    <div class="qr">{!! $qrBillHtml !!}</div>
  @endif
</div>
<script>
  // Paginate into explicit 297mm pages (see .page). Runs once fonts are in,
  // before Chrome prints.
  //
  // The body is flattened into atoms in document order: the header block,
  // each table row, each child of a [data-split] container, and every other
  // block. Heights come from the gap to the next atom, so margins are counted.
  // [data-glue] ties an atom to the next one (a section head to its first
  // item, a section's last item to its subtotal). Rows sharing
  // [data-section] form a section that moves to a fresh page as a whole when
  // it fits there and little room is left; otherwise it splits and the next
  // page repeats the column header and the section head marked "Fortsetzung".
  document.fonts.ready.then(function () {
    var sheet = document.querySelector('.sheet'), body = document.querySelector('.body'), qr = document.querySelector('.qr');
    function rect(el) { return el.getBoundingClientRect(); }
    var mm = rect(body).width / 210, PAGE = 297 * mm, BAND = 12 * mm, TOP = 12 * mm, AVAIL = PAGE - BAND;
    var label = @json($label.' '.$doc->number);

    var head = [body.querySelector('.logo'), body.querySelector('.sender'), body.querySelector('.window'), body.querySelector('h1')];
    var atoms = [];
    [].slice.call(body.children).forEach(function (el) {
      if (head.indexOf(el) >= 0) { atoms.push({ el: el, kind: 'head', top: rect(el).top }); return; }
      if (el.tagName === 'TABLE' && el.tBodies[0] && el.tBodies[0].rows.length) {
        var tableTop = rect(el).top;
        [].slice.call(el.tBodies[0].rows).forEach(function (r, i) {
          atoms.push({ el: r, kind: 'row', parent: el, glue: r.hasAttribute('data-glue'), section: r.getAttribute('data-section'), top: rect(r).top, startTop: i === 0 ? tableTop : null });
        });
        el.theadH = rect(el.tBodies[0].rows[0]).top - tableTop;
        if (el.hasAttribute('data-glue')) atoms[atoms.length - 1].glue = true;
        return;
      }
      if (el.hasAttribute('data-split') && el.children.length) {
        var boxTop = rect(el).top;
        [].slice.call(el.children).forEach(function (c, i) {
          // Headings, and a lead-in paragraph ending in ':', never end a page.
          var lead = /^H[1-6]$/.test(c.tagName) || (c.tagName === 'P' && /:\s*$/.test(c.textContent));
          atoms.push({ el: c, kind: 'child', parent: el, glue: lead || c.hasAttribute('data-glue'), chapter: el.classList.contains('notes') && /^H[12]$/.test(c.tagName), top: rect(c).top, startTop: i === 0 ? boxTop : null });
        });
        return;
      }
      atoms.push({ el: el, kind: 'block', glue: el.hasAttribute('data-glue'), top: rect(el).top });
    });
    var bodyBottom = rect(body).bottom;
    atoms.forEach(function (a, i) {
      var n = atoms[i + 1];
      a.h = (n ? (n.startTop !== null && n.startTop !== undefined ? n.startTop : n.top) : bodyBottom) - a.top;
    });

    // Units: the whole header is one; glued atoms merge with their successor.
    var units = [], u = null;
    atoms.forEach(function (a) {
      if (!u) u = { atoms: [], h: 0, section: null };
      u.atoms.push(a); u.h += a.h;
      if (u.section === null && a.section !== null && a.section !== undefined) u.section = a.section;
      var nextIsHead = a.kind === 'head' && atoms[atoms.indexOf(a) + 1] && atoms[atoms.indexOf(a) + 1].kind === 'head';
      if (!a.glue && !nextIsHead) { units.push(u); u = null; }
    });
    if (u) units.push(u);

    var sections = {};
    units.forEach(function (unit, i) {
      if (unit.section === null) return;
      var s = sections[unit.section] || (sections[unit.section] = { first: i, h: 0, head: null });
      s.h += unit.h;
      unit.atoms.forEach(function (a) { if (a.el.classList && a.el.classList.contains('sec-head')) s.head = a; });
    });

    var pages = [], cur = null, y = 0, pageStart = 0, openParent = null, openBox = null;
    function newPage() {
      cur = document.createElement('div'); cur.className = 'page';
      cur.content = document.createElement('div'); cur.content.className = 'content doc';
      cur.appendChild(cur.content); pages.push(cur);
      y = pageStart = pages.length > 1 ? TOP : 0; openParent = null; openBox = null;
    }
    function firstContainerAtom(unit) {
      for (var i = 0; i < unit.atoms.length; i++) if (unit.atoms[i].parent) return unit.atoms[i];
      return null;
    }
    // Extra height the unit costs on the current page: a repeated column
    // header when its table is not open here, plus a continuation head.
    function extra(unit, index) {
      var a = unit.atoms[0], cost = 0;
      if (a.kind === 'row' && openParent !== a.parent) {
        cost += a.parent.theadH;
        var s = unit.section !== null ? sections[unit.section] : null;
        if (s && s.first !== index && s.head) cost += s.head.h;
      }
      return cost;
    }
    // Start a shallow copy of a table / split container on the current page.
    function open(parent, kind) {
      if (openParent === parent) return;
      openBox = parent.cloneNode(false);
      if (kind === 'row') {
        openBox.appendChild(parent.tHead.cloneNode(true));
        openBox.appendChild(document.createElement('tbody'));
      }
      cur.content.appendChild(openBox); openParent = parent;
    }
    function place(a) {
      if (!a.parent) { cur.content.appendChild(a.el); openParent = null; openBox = null; return; }
      open(a.parent, a.kind);
      (a.kind === 'row' ? openBox.tBodies[0] : openBox).appendChild(a.el);
    }

    newPage();
    units.forEach(function (unit, index) {
      var s = unit.section !== null ? sections[unit.section] : null;
      if (s && s.first === index && y > pageStart) {
        var fresh = AVAIL - TOP;
        var roomLeft = AVAIL - y - extra(unit, index);
        if (s.h > roomLeft && s.h + (unit.atoms[0].parent ? unit.atoms[0].parent.theadH || 0 : 0) <= fresh && roomLeft < fresh / 3) newPage();
      }
      if (y > pageStart && (unit.atoms[0].chapter || y + unit.h + extra(unit, index) > AVAIL)) newPage();

      var a0 = unit.atoms[0];
      if (a0.kind === 'row' && openParent !== a0.parent) {
        y += a0.parent.theadH;
        if (s && s.first !== index && s.head) {
          open(a0.parent, 'row');
          var cont = s.head.el.cloneNode(true); cont.classList.add('is-cont');
          openBox.tBodies[0].appendChild(cont); y += s.head.h;
        }
      }
      unit.atoms.forEach(function (a) { place(a); });
      y += unit.h;
    });

    if (qr) {
      if (y + qr.offsetHeight > PAGE) newPage();
      cur.appendChild(qr); cur.slip = true;
    }
    pages.forEach(function (p, i) {
      if (p.slip) return;
      var f = document.createElement('div'); f.className = 'page-foot';
      f.innerHTML = '<span>' + label + '</span><span>Seite ' + (i + 1) + ' / ' + pages.length + '</span>';
      p.appendChild(f);
    });
    body.remove();
    pages.forEach(function (p) { sheet.appendChild(p); });
  });
</script>
</body>
</html>
