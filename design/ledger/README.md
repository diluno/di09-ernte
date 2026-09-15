# Handoff: Ernte — "Ledger" redesign (Invoices list + Invoice detail)

## Overview
A visual overhaul of Ernte's invoicing screens in the **Ledger** direction: Swiss-print tone — cream stock, hairline ink rules, one red, tabular numerals. Two screens are covered: the Invoices index (`/invoices`) and the Invoice detail (`/invoices/{number}`). Data, routes, and behaviors match the existing Vue/Inertia pages; only presentation changes, plus a consolidated "more actions" menu on the detail page.

## About the Design Files
The `.dc.html` files in this bundle are **design references created in HTML** — prototypes showing intended look and behavior, not production code. Recreate them in the existing codebase: Laravel + Inertia + Vue 3, custom CSS with tokens in `resources/css/tokens.css` and `resources/css/base.css` (no Tailwind). Map the values below onto that token system — replace the current paper theme values rather than adding a parallel theme. Fonts must stay in `resources/fonts/`; the prototype runtime (`support.js`) and copied assets were dropped when the bundle moved into `design/ledger/`; the `.dc.html` files reference fonts via `assets/`, so point them at `../../resources/fonts/` if you need to open them standalone.

## Fidelity
**High-fidelity.** Colors, type sizes, spacing, and states are final. Recreate pixel-perfectly. The prototype uses a system monospace fallback; production keeps `--font-mono: 'Iosevka Aile', …` as today.

## Screens / Views

### 1. Invoices list (`Ernte Invoices.dc.html`, route `/invoices`)
**Purpose:** overview of all invoices with money summary, monthly issued chart, status filter, and a table that opens the detail page.

**Shell (shared by both screens)**
- App grid: `grid-template-columns: 240px 1fr; grid-template-rows: 52px 1fr`. No statusbar (the current 24px footer is dropped).
- Background `#f7f3ea`, ink `#141210`, body font Beausite 14px.
- **Topbar** (52px, `padding: 0 32px`, `border-bottom: 1px solid #141210`, `gap: 24px`): wordmark (leaf pixel icon 16px + "ernte" 15px/700, `letter-spacing: -0.01em`) followed by `/ business-name` in `#7a7367` 400. Center: "Jump to…" in mono 12px `#7a7367` with a `⌘K` kbd (`border: 1px solid #c9c2b3; padding: 1px 6px`). Right: running-timer chip — mono 12px, `border: 1px solid #141210; padding: 5px 10px 5px 12px; gap: 10px`, 8px red dot `#c8341f`, project name, bold elapsed time, 9px square stop glyph (`background: currentColor`). Hover inverts: `background: #141210; color: #f7f3ea`. Then user name 13px.
- **Sidebar** (`border-right: 1px solid #141210; padding-top: 28px`):
  - Nav items: `display:flex; gap:12px; padding: 9px 32px; font-size: 15px; border-bottom: 1px solid #e3dccd`. Pixel icon 14px inherits color. Label flex:1; count in mono 12px `#7a7367`. Inactive color `#7a7367`/400; active `#141210`/600 with `background: #efe9dc`. Hover `background: #efe9dc; color: #141210`.
  - "Pinned" section label: 11px, `letter-spacing: .14em`, uppercase, `#7a7367`, `padding: 32px 32px 8px`. Pinned rows `padding: 6px 32px; font-size: 14px`, hover `#efe9dc`.
  - Bottom block (`border-top: 1px solid #141210; padding: 24px 32px`): "This week" label (same label style), value mono 34px `letter-spacing: -0.03em` with `/ 40 h` in 14px `#7a7367`; 7 week bars (`height: 24px; gap: 2px; border-bottom: 1px solid #141210`), past days `#141210`, today `#c8341f`, empty days no fill.

**Page head** (`padding: 36px 40px 24px; border-bottom: 1px solid #141210`, flex space-between, align-end)
- Crumb: mono 12px uppercase `letter-spacing: .08em` `#7a7367` — "Invoices · FY 2026 · 42 documents".
- Title `h1`: 64px / 600 / `letter-spacing: -0.04em` / `line-height: .95`, `margin-top: 10px`.
- Actions (`gap: 8px`): secondary button "↓ Paid PDFs" — mono 12px, `padding: 10px 14px; border: 1px solid #141210`, hover `background: #efe9dc`; primary "+ New invoice" — mono 12px, `background: #141210; color: #f7f3ea; padding: 11px 18px`, hover `#2e2b26`.

**Stats row** (4 equal columns, `border-bottom: 1px solid #141210`; cells `padding: 20px 40px 22px; border-right: 1px solid #e3dccd` except last)
- Label 12px uppercase `letter-spacing: .08em` `#7a7367`; value mono 40px `letter-spacing: -0.03em; line-height: 1; margin-top: 10px`, unit "CHF" 14px `#7a7367` `margin-left: 6px`; footnote 12px `#7a7367` `margin-top: 8px`.
- Overdue cell: label, value, and footnote all `#c8341f`.
- Content: Outstanding / Overdue / Paid YTD / Avg days to pay (from `InvoiceProjections::stats()`).

**Monthly chart** (`padding: 20px 40px 18px; border-bottom: 1px solid #e3dccd`; grid `repeat(12, 1fr) 120px`)
- Each month cell: `border-left: 1px solid #e3dccd; padding-left: 10px; gap: 8px`. Month label 11px uppercase `letter-spacing: .1em` `#7a7367` (current month `#141210`, cell `background: #f1ece1`). Bars stacked bottom-up in a 56px box: open (sent) `#c8341f` on top of paid `#141210`, each 22px wide, 2px gap; heights scale linearly to the nice max (same `niceScale` as `InvoiceBarChart.vue`). Total below in mono 12px (`—` when 0). Hover `background: #efe9dc`.
- Last column (`border-left: 1px solid #141210; padding-left: 14px`, bottom-aligned): legend swatches 10px (Paid `#141210`, Open `#c8341f`) and year sum `Σ 155’360` in mono 12px.
- Year prev/next arrows are not shown in the mock; keep the existing behavior by making the month label row clickable or reuse the current arrow buttons styled like the secondary button.

**Filter row** (`padding: 16px 40px; border-bottom: 1px solid #141210; gap: 24px; font-size: 13px`)
- Tabs All / Draft / Sent / Overdue / Paid with counts. Active: 600, `border-bottom: 1px solid #141210; padding-bottom: 2px`. Inactive `#7a7367`, hover `#141210` + `border-bottom-color: #c9c2b3`. Overdue tab always `#c8341f`.
- Search: `margin-left: auto; min-width: 220px`, mono 12px `#7a7367`, `border-bottom: 1px solid #c9c2b3`, placeholder "filter…", trailing kbd `/` (`border: 1px solid #c9c2b3; padding: 0 5px; font-size: 11px`). Bind `/` to focus it.

**Table** (`width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums`)
- Header cells: 11px uppercase `letter-spacing: .12em` `#7a7367` 400, `padding: 12px`, `border-bottom: 1px solid #e3dccd`; first `padding-left: 40px`, last `padding-right: 40px`. Widths: No. 130, Subject 260, Client 220, Issued 120, Due 120, Hours 80, CHF 150, Status 130. Active sort column ("Issued ↓") in `#141210`.
- Rows: height 46px, `border-bottom: 1px solid #e3dccd`, `cursor: pointer`, hover `background: #efe9dc`, click → `/invoices/{number}`.
  - No.: mono 13px. Subject: 15px, single-line ellipsis; falls back to `—`. Client: `#7a7367`, ellipsis. Issued/Due/Hours: mono 13px `#7a7367` right-aligned. CHF: mono 14px right-aligned, 600 for non-paid, 400 for paid. Status: 12px uppercase `letter-spacing: .08em`, mark + word.
  - Per-status: **draft** — ink `#7a7367`, subject italic, mark `○`. **sent** — ink `#141210`, mark `◐`. **overdue** — row `background: #f6e6e1`, first cell `box-shadow: inset 3px 0 0 #c8341f`, due date and status `#c8341f`, mark `●`. **paid** — ink `#5a554b`, status `#7a7367`, mark `●`.
- Pagination: keep existing component; restyle buttons as the secondary button.

### 2. Invoice detail (`Ernte Invoice Detail.dc.html`, route `/invoices/{number}`)
**Purpose:** review one invoice, act on it (PDF, mark paid, reminders, void, delete), see recipients, activity, linked time.

- **Page head**: crumb "Invoices / 2026-0039 · Client" (mono 12px uppercase; "Invoices" link `border-bottom: 1px solid #c9c2b3`, hover `#141210`). Title = invoice title (fallback "Invoice #number") at 64px, with inline status in mono 12px uppercase `letter-spacing: .12em` (`● overdue · 2 days` in `#c8341f`; other statuses `#7a7367`), `gap: 22px`, baseline aligned.
- **Actions** (`gap: 8px`, right): secondary "PDF" with pixelarticons `download` 13px; primary "Mark paid" with `check` 13px (icon `gap: 8px`); 40px square icon button with `more-horizontal` 14px (`border: 1px solid #141210`, open state `background: #efe9dc`). Menu: absolute below-right (`top: calc(100% + 6px)`), `min-width: 200px; background: #f7f3ea; border: 1px solid #141210; box-shadow: 0 10px 24px rgba(20,18,16,.10)`; items `padding: 11px 16px; font-size: 14px; border-bottom: 1px solid #e3dccd`, hover `#efe9dc`; last item "Delete…" in `#c8341f`, hover `#f6e6e1`. Items: Pause/Resume reminders, Send reminder now, Void invoice, Delete…. Primary action varies by status as in `Show.vue` (draft: Send by email + Edit/Mark as sent in menu; sent: Mark paid; paid/void: no primary).
- **Stats row**: same style as list. Total (`incl. 8.1% VAT`), Issued (`14 Aug`, footnote year · channel), Due (red when overdue, footnote `terms 30 · N days late`), Hours (footnote `N linked entries · rate`).
- **Body**: grid `1fr 340px`, fills remaining height (`min-height: 0`).
  - Left pane: `padding: 32px 40px; background: #efe9dc; overflow-y: auto`, content centered top. Document sheet 640px wide: `background: #fbf9f4; border: 1px solid #141210; padding: 44px 48px; box-shadow: 0 1px 0 #c9c2b3, 0 14px 34px rgba(20,18,16,.08); gap: 28px`. In production this remains the PDF preview iframe — style the iframe frame like the sheet (border, shadow) and let it be the scroll region.
  - Right aside: `border-left: 1px solid #141210; padding: 28px 32px; overflow-y: auto; gap: 32px`. Section heads 11px uppercase `letter-spacing: .14em` `#7a7367`, `border-bottom: 1px solid #141210; padding-bottom: 8px`. Rows `padding: 10px 0; border-bottom: 1px solid #e3dccd`, 14px with mono 12px `#7a7367` secondary line. Sections: Recipients (name + email), Activity (grid `96px 1fr; gap: 12px`; time mono 12px; overdue-stamped event and its time in `#c8341f`; trailing italic "Next reminder scheduled" row), Linked time (project + hours mono, then `N entries · date range` with "View →" link `border-bottom: 1px solid #141210`).

## Interactions & Behavior
- List row click → detail route. Hover tint `#efe9dc` on rows, nav, pinned, month cells, buttons (120ms ease is fine; none in mock).
- Filter tabs and search keep existing Inertia `router.get` behavior; `/` focuses search.
- More-actions menu: toggles on click; close on outside click and `Esc`. Destructive items keep existing `window.confirm` copy.
- Timer chip: click opens `/timer`; stop square stops the timer (stopPropagation as today).
- Panes on detail scroll independently; page itself does not scroll.
- Desktop only (≥1280px). Below that, the existing responsive rules may stay.

## State Management
- Unchanged: `invoices`, `stats`, `counts`, `filters`, `invoiceChart`, `invoice`, `events`, `linked_entries`, `preview_url`, `pdf_url` props.
- New local UI state on detail: `menuOpen: boolean`.
- Recipients section reads `invoice.recipients` (contacts) — expose via `InvoiceProjections::detail()` if not already present.

## Design Tokens (proposed values for `tokens.css`, paper theme)
- `--paper: #f7f3ea` · `--paper-2: #efe9dc` · `--paper-3: #f1ece1` (current-month tint) · `--sheet: #fbf9f4`
- `--ink: #141210` · `--ink-2: #5a554b` · `--ink-3: #7a7367`
- `--border: #e3dccd` · `--border-strong: #c9c2b3` · rule = `--ink` (1px hairline for structural lines)
- `--red: #c8341f` · `--red-bg: #f6e6e1` · `--ink-hover: #2e2b26`
- Forest/rust/gold accents are retired on these screens; red is the only chroma (overdue, open bars, today bar, timer dot).
- Type: Beausite 400/600/700 — 64px title (-0.04em), 15px nav/subject, 14px body, 13px meta, 12px labels (.08em upper), 11px section labels (.12–.14em upper). Mono: 40px stats (-0.03em), 34px week total, 13px table numerics, 12px crumbs/kbd/buttons.
- Spacing: 40px page gutter, 32px sidebar gutter, 46px table row, 52px topbar, 240px sidebar, 340px detail aside.
- Radius: 0 everywhere. Shadows: sheet `0 1px 0 #c9c2b3, 0 14px 34px rgba(20,18,16,.08)`; menu `0 10px 24px rgba(20,18,16,.10)`.

## Assets
- Fonts: `BeausiteClassicWeb-Clear.woff` (400), `BeausiteClassicWeb-Semibold.woff` (600–700) — already in `resources/fonts/`.
- Icons: pixelarticons via existing `Icon.vue` map — `leaf, briefcase, clock, users, receipt, edit, repeat`; add `download`, `check` (already mapped), `more-horizontal`.

## Files
- `Ernte Invoices.dc.html` — Invoices list.
- `Ernte Invoice Detail.dc.html` — Invoice detail with actions menu.
- `assets/` — fonts and favicon copied from the repo.
