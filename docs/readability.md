# Prompt for Claude Code — ernte UI readability fix

Improve UI readability in the ernte web app: stop rendering everything in
JetBrains Mono. Use Beausite (the brand sans, already in the repo) for text/UI
and reserve the mono font for data only.

## Context

The app currently sets `font-family: var(--font-mono)` on `html, body` in
`resources/css/app.css`, so headings, nav, labels, tables, and body copy are all
monospace — that's what makes it hard to read. The Beausite woffs already exist at
`resources/fonts/BeausiteClassicWeb-Clear.woff` (400) and
`BeausiteClassicWeb-Semibold.woff` (600/700) but are only registered for PDF
output in `resources/views/partials/pdf-fonts.blade.php`. Wire them up for the web
and flip the default.

Keep everything else (layout, palette, spacing, density, dark mode) exactly as-is.

## Edits

### 1. `resources/css/tokens.css`

Add a sans token next to `--font-mono` (inside the final `:root` block):

```css
--font-sans: 'Beausite', 'Helvetica Neue', Arial, system-ui, sans-serif;
```

### 2. `resources/css/app.css`

Register Beausite for the web (Vite will fingerprint/serve the woffs) and switch
the body to sans:

```css
@font-face {
  font-family: 'Beausite';
  font-weight: 400; font-style: normal; font-display: swap;
  src: url('../fonts/BeausiteClassicWeb-Clear.woff') format('woff');
}
@font-face {
  font-family: 'Beausite';
  font-weight: 600 700; font-style: normal; font-display: swap;
  src: url('../fonts/BeausiteClassicWeb-Semibold.woff') format('woff');
}
```

Then change the `html, body` rule's `font-family: var(--font-mono);` →
`font-family: var(--font-sans);` (leave `font-size: var(--fs-sm)` etc. unchanged).

### 3. `resources/css/base.css`

Keep monospace only for data/codes/identifiers. Add this rule anywhere after the
reset:

```css
.kbd, .mono-tag, .crumb, .statusbar, .running-timer,
.timer-display, .stat .val, .budget-cell .label, .task-num,
.invoice-h, .invoice-line .num, .invoice-totals,
.entry-row .dur, .entry-row .billable, .avatar, .proj-glyph,
.table tbody td.num, .table thead th.num {
  font-family: var(--font-mono);
}
```

Tables already carry `font-variant-numeric: tabular-nums`; this just restores the
mono face on numeric/code cells while text cells (project names, clients,
descriptions, nav) become sans.

## Don't touch

- The PDF font setup in `resources/views/partials/pdf-fonts.blade.php`.
- The JetBrains Mono `<link>` in `resources/views/app.blade.php` (fonts.bunny.net)
  — it's still used for the mono data face.

## Expected result

Headings and prose render in Beausite sans, while the timer clock, money/hours,
rates, project codes, kbd hints, and the status bar stay monospace.
