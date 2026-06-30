# Prompt for Claude Code — ernte invoice/estimate create & edit: LAYOUT follow-up

The previous pass landed the CSS (`.cell-input` visibility, `.icon-btn--danger`,
`.table--lines`, `.table--picker`). What's still missing is the **structural
layout** from the target mockup: the line editor is still a bare table and the
totals are a plain `<aside>`. This pass frames them as cards and makes the summary
sticky. Markup + a little global CSS only — do NOT touch totals/VAT logic or the
`useForm` transforms.

## Files

- `resources/js/Pages/Invoices/Create.vue`
- `resources/js/Pages/Invoices/Edit.vue`
- `resources/js/Pages/Estimates/Create.vue`
- `resources/js/Pages/Estimates/Edit.vue`

(Estimates mirror Invoices — same structure, different labels/routes.)

## 1. Global CSS — add to `resources/css/base.css`

After the existing `.table--picker` rules (~line 636):

```css
/* ── Document editor: framed line card + sticky summary ── */

/* Roomier editable rows (was 6px). */
.table--lines tbody td { padding-top: 9px; padding-bottom: 9px; }

/* Frame the line table + its add-line footer as one card. */
.lines-card { border: 1px solid var(--border); border-radius: 4px; overflow: hidden; }
.lines-card .table { margin: 0; }
.lines-card .table thead th { background: var(--bg-2); }
.lines-card .table--lines tbody tr:last-child td { border-bottom: 0; }
.lines-card .add-line {
  width: 100%; display: flex; align-items: center; gap: 8px;
  padding: 11px 16px; background: var(--paper); border: 0;
  border-top: 1px solid var(--border);
  color: var(--ink-2); font: inherit; font-size: var(--fs-sm);
  cursor: pointer; text-align: left;
}
.lines-card .add-line:hover { background: var(--bg-hover); color: var(--ink); }

/* Sticky summary card. */
.doc-grid { align-items: start; }
.summary-card {
  border: 1px solid var(--border); border-radius: 4px; overflow: hidden;
  position: sticky; top: 88px;
}
.summary-card .summary-head {
  margin: 0; padding: 14px 16px;
  background: var(--bg-2); border-bottom: 1px solid var(--border);
  font-size: var(--fs-xs); letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-3);
}
.summary-card .summary-body { padding: 16px 18px; }

@media (max-width: 1140px) {
  .doc-grid { grid-template-columns: 1fr !important; }
  .summary-card { position: static; max-width: 440px; }
}
```

## 2. Per-file markup changes

### a. Tag the grid wrapper

Add `class="doc-grid"` to the main two-column grid `<div>` (the one with
`display: grid; grid-template-columns: … 360px`). Leave its inline `grid-template`
as-is — the media query overrides it.

- Invoices/Create.vue: `<div v-else style="…grid-template-columns: 1fr 360px…">` → add `class="doc-grid"`.
- Edit.vue + both Estimates: same, on their `<div style="…grid-template-columns: minmax(0, 1fr) 360px…">`.

### b. Frame the Lines table as a card

Wrap the `<table class="table table--lines">` **and** the `+ Add line` button in a
`.lines-card`, and convert the button into an inline footer. Replace this block:

```html
<table class="table table--lines">
  …thead/tbody unchanged…
</table>
<button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>
```

with:

```html
<div class="lines-card">
  <table class="table table--lines">
    …thead/tbody unchanged…
  </table>
  <button class="add-line" @click="addLine"><span style="font-family: var(--font-mono)">+</span> Add line</button>
</div>
```

(Keep the `<thead>`, the `v-for` row, and the empty-state `<tr>` exactly as they
are. Only the wrapper + the add button change.)

### c. Restructure the totals `<aside>` into a sticky card

Replace the opening of the aside — this:

```html
<aside>
  <h3 class="section-title">Totals</h3>
  <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
```

with:

```html
<aside class="summary-card">
  <div class="summary-head">Totals</div>
  <div class="summary-body">
    <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
```

Then add ONE extra closing `</div>` before `</aside>` to close `.summary-body`
(it now wraps the totals grid, the helper `<p>`, and the error block).

**On Edit.vue and Estimates/Edit.vue only:** the aside has a second
`<h3 class="section-title" style="margin-top: 24px">Details</h3>` and a details
block. Those should live inside `.summary-body` too — since you've wrapped the
whole aside body in one `.summary-body`, just make sure the single extra `</div>`
goes at the very end (after the error block), so everything is inside it. The
`Details` heading can stay `.section-title` (it'll show its trailing rule line,
which is fine mid-card), or change it to a plain
`<div class="summary-head" style="border-top:1px solid var(--border)">Details</div>`
for consistency — your call.

### d. Move the primary action into the summary (nice-to-have)

To match the mockup, add a full-width primary button at the bottom of
`.summary-body`, right after the totals grid:

```html
<button class="btn primary" style="width: 100%; justify-content: center; margin-top: 16px"
        :disabled="form.processing || lines.length === 0" @click="save">
  {{ /* Create.vue */ 'Create draft' /* Edit.vue: 'Save changes' */ }}
</button>
```

Use the literal label per screen (`Create draft` on Create, `Save changes` on
Edit; the Estimate equivalents). The header button stays too — both trigger
`save()`.

## 3. Don't touch

- `totalsForLines` / `roundTotalRappen` and the `useForm` transforms.
- The picker-mode "Choose a client" branch and the "Entries in period" table in
  Invoices/Create.vue (its `.table--picker` styling already landed) — leave its
  markup as-is; it sits in the left column, outside the summary card.

## Expected result

The line items sit in a single bordered card with a shaded header row and an
inline "+ Add line" footer; rows are a touch roomier. The Totals panel is a
bordered card with a shaded header that sticks to the top while you scroll the
lines, and carries its own primary action. On viewports under 1140px the summary
drops below the editor instead of being crushed.
