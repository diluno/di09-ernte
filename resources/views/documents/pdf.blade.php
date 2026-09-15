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
  $date = fn ($d) => $d?->format('d.m.Y') ?? '—';

  $client = $doc->client;
  $recipients = $doc->recipients ?: $client->defaultRecipients();
  $attn = $recipients[0]['name'] ?? null;
  if ($attn && mb_strtolower(trim($attn)) === mb_strtolower(trim($client->name))) { $attn = null; }

  $untilLabel = $isInvoice ? 'Fällig' : 'Gültig bis';
  $until = $isInvoice ? $doc->due_on : $doc->valid_until;
  $termsDays = ($isInvoice && $doc->issued_on && $doc->due_on) ? (int) $doc->issued_on->diffInDays($doc->due_on) : null;

  $showPeriod = $isInvoice && $doc->period_start && $doc->period_end && ! $doc->period_start->isSameDay($doc->period_end);
  $anyExempt = $doc->lines->contains(fn ($l) => (bool) ($l->vat_exempt ?? false));

  $senderLine = implode(' · ', array_filter([
      $profile->name, $profile->address_line_1, trim(($profile->postal_code ?? '').' '.($profile->city ?? '')),
  ]));
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
    .address .attn { color: var(--ink-2); }
    .meta { font-family: var(--mono); font-size: 8.5pt; line-height: 1.7; color: var(--ink-3); display: grid; grid-template-columns: max-content 1fr; gap: 0 5mm; align-content: start; }
    .meta b { color: var(--ink); font-weight: 600; }
    .meta .kind { grid-column: 1 / -1; font-family: var(--sans); font-size: 8pt; letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3); margin-bottom: 1.5mm; }
    .meta .is-red { color: var(--red); }

    /* ── Title ── */
    h1 { font-size: 20pt; font-weight: 600; letter-spacing: -0.02em; line-height: 1.1; margin: 0 0 5mm; padding-bottom: 3mm; border-bottom: 1px solid var(--ink); }

    /* ── Lines ── */
    table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    thead th { text-align: left; font-size: 7.5pt; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-3); font-weight: 400; padding: 0 0 2.5mm; border-bottom: 1px solid var(--border); }
    thead th.num, tbody td.num { text-align: right; }
    tbody td { padding: 2.5mm 0; border-bottom: 1px solid var(--border); vertical-align: top; }
    tbody td.num { font-family: var(--mono); font-size: 9pt; color: var(--ink-3); white-space: nowrap; }
    tbody td.amount { font-family: var(--mono); font-size: 10pt; color: var(--ink); }
    tbody td.num, tbody td.amount { padding-left: 4mm; }
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
    .notes { margin-top: 8mm; font-size: 10pt; line-height: 1.5; }
    .notes p { margin: 0 0 2.5mm; }
    .notes ul, .notes ol { margin: 0 0 2.5mm; padding-left: 5mm; }
    .notes li { margin-bottom: .8mm; }
    .notes hr { border: none; border-top: 1px solid var(--border); margin: 5mm 0; }
    .notes h1, .notes h2, .notes h3 { font-size: 10.5pt; margin: 4mm 0 1.5mm; padding: 0; border: 0; letter-spacing: 0; }
    .foot { margin-top: 5mm; padding-top: 3mm; border-top: 1px solid var(--border); font-size: 8.5pt; color: var(--ink-3); display: flex; justify-content: space-between; gap: 6mm; }
    .foot b { color: var(--ink); font-weight: 600; }

    /* ── Payment part: full 210mm width, kept whole, pushed to the sheet bottom. ── */
    /* separator (5mm) + payment part (105mm) = 110mm; no extra margin so a short
       invoice still fits the 287mm sheet with the slip at the bottom edge. */
    .qr { break-inside: avoid; page-break-inside: avoid; flex: 0 0 auto; }
    .qr .qr-bill { margin: 0 auto; }
  </style>
</head>
<body>
<div class="sheet">
  <div class="body">
    <div class="logo">{!! \App\Support\GenerativeLogo::inlineSvg(crc32((string) $doc->number), color: '#141210') !!}</div>
    <div class="sender">{{ $senderLine }}</div>

    <div class="window">
      <div class="address">
        <div class="name">{{ $client->name }}</div>
        @if ($attn)<div class="attn">z.Hd. {{ $attn }}</div>@endif
        @if ($client->address_line_1)<div>{{ $client->address_line_1 }}</div>@endif
        @if ($client->address_line_2)<div>{{ $client->address_line_2 }}</div>@endif
        <div>{{ trim(($client->postal_code ?? '').' '.($client->city ?? '')) }}</div>
      </div>
      <div class="meta">
        <span class="kind">{{ $label }}</span>
        <span>Nr.</span><b>{{ $doc->number }}</b>
        <span>Datum</span><b>{{ $date($doc->issued_on) }}</b>
        <span>{{ $untilLabel }}</span><b @class(['is-red' => $isInvoice && $doc->overdue])>{{ $date($until) }}</b>
        @if ($doc->project)<span>Projekt</span><span>{{ $doc->project->name }}</span>@endif
        @if ($showPeriod)<span>Periode</span><span>{{ $doc->period_start->format('d.m.') }} – {{ $doc->period_end->format('d.m.Y') }}</span>@endif
        @if ($profile->uid)<span>UID</span><span>{{ $profile->uid }}</span>@endif
      </div>
    </div>

    <h1>{{ $doc->title ?: $label.' '.$doc->number }}</h1>

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
    @if ($anyExempt)<div class="exempt-note">* ohne MwSt</div>@endif

    <div class="totals">
      <span class="l">Zwischensumme</span><span class="v">{{ $chf($doc->subtotal_rappen) }}</span>
      <span class="l">MwSt {{ $rateLabel($doc->vat_rate) }}%</span><span class="v">{{ $chf($doc->vat_rappen) }}</span>
      @if ($doc->rounding_rappen != 0)
        <span class="l">Rundung</span><span class="v">{{ $chf($doc->rounding_rappen) }}</span>
      @endif
      <span class="grand-l">Total CHF</span><span class="v grand">{{ $chf($doc->total_rappen) }}</span>
    </div>

    @if ($doc->notes)
      <div class="notes">{!! \App\Support\Markdown::toHtml($doc->notes) !!}</div>
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
  // before Chrome prints. Units: the header block, each line row (tables are
  // split with a repeated head), each block after the table, the slip.
  document.fonts.ready.then(function () {
    var sheet = document.querySelector('.sheet'), body = document.querySelector('.body'), qr = document.querySelector('.qr');
    var mm = document.body.offsetWidth / 210, PAGE = 297 * mm, BAND = 12 * mm, AVAIL = PAGE - BAND;
    var label = @json($label.' '.$doc->number);

    var table = body.querySelector('table'), rows = [].slice.call(table.tBodies[0].rows);
    var head = [body.querySelector('.logo'), body.querySelector('.sender'), body.querySelector('.window'), body.querySelector('h1')];
    var after = [].slice.call(body.children).filter(function (el) { return head.indexOf(el) < 0 && el !== table; });
    var units = [];
    units.push({ nodes: head, h: table.offsetTop - head[0].offsetTop });
    var theadH = table.tHead.offsetHeight;
    rows.forEach(function (r, i) {
      var next = rows[i + 1];
      units.push({ row: r, h: (next ? next.offsetTop : table.offsetHeight) - r.offsetTop });
    });
    after.forEach(function (el, i) {
      if (el.classList.contains('notes')) {
        [].slice.call(el.children).forEach(function (c, j, all) {
          var n = all[j + 1];
          units.push({ nodes: [c], notes: true, h: (n ? n.offsetTop : el.offsetHeight) - c.offsetTop });
        });
        return;
      }
      var n = after[i + 1];
      units.push({ nodes: [el], h: (n ? n.offsetTop : body.offsetHeight) - el.offsetTop });
    });

    var pages = [], cur = null, y = 0, curTable = null, curNotes = null;
    function newPage() {
      cur = document.createElement('div'); cur.className = 'page';
      cur.content = document.createElement('div'); cur.content.className = 'content';
      cur.appendChild(cur.content); pages.push(cur); y = 0; curTable = null; curNotes = null;
    }
    newPage();
    units.forEach(function (u) {
      if (y > 0 && y + u.h > AVAIL) newPage();
      if (u.row) {
        if (!curTable) {
          curTable = table.cloneNode(false);
          curTable.appendChild(table.tHead.cloneNode(true));
          curTable.appendChild(document.createElement('tbody'));
          cur.content.appendChild(curTable); y += theadH; curNotes = null;
        }
        curTable.tBodies[0].appendChild(u.row);
      } else if (u.notes) {
        if (!curNotes) { curNotes = document.createElement('div'); curNotes.className = 'notes'; cur.content.appendChild(curNotes); }
        curNotes.appendChild(u.nodes[0]); curTable = null;
      } else {
        u.nodes.forEach(function (n) { cur.content.appendChild(n); }); curTable = null; curNotes = null;
      }
      y += u.h;
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
