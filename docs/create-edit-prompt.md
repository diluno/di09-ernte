# Prompt for Claude Code — ernte invoice/estimate create & edit screens

Improve readability and usability of the invoice and estimate create/edit
screens. The core problem: editable fields are invisible until focused, so users
can't tell what's editable. Make every input visibly editable at rest, give the
line-item editor real affordances, and make the "billable entries" picker read as
secondary to the lines it feeds. Keep the layout, palette, and totals logic as-is.

## Files (all four share the same patterns)

- `resources/js/Pages/Invoices/Create.vue`
- `resources/js/Pages/Invoices/Edit.vue`
- `resources/js/Pages/Estimates/Create.vue`
- `resources/js/Pages/Estimates/Edit.vue`

Each has a near-identical `<style scoped>` block and the same line-item `<table>`.

## Root cause

The scoped style in every file is:

```css
.cell-input { width: 100%; border: 1px solid transparent; background: transparent; padding: 4px 6px; ... }
.cell-input:focus { outline: none; border-color: var(--accent); background: var(--paper); }
```

A transparent border + transparent background means a text input looks exactly
like static table text. That's the whole problem.

## Edits

### 1. Replace the `<style scoped>` block in ALL FOUR files

Use this block (identical in each — the `.framed` and `.detail-row` rules are only
used by the Edit screens but are harmless where unused):

```css
<style scoped>
/* Editable line-item cells: visible at rest, clear focus ring. */
.cell-input {
  width: 100%;
  border: 1px solid transparent;
  background: var(--bg-2);
  padding: 8px 10px;
  font-family: inherit;
  color: var(--ink);
  border-radius: 3px;
}
.cell-input:hover { border-color: var(--border); }
.cell-input:focus {
  outline: none;
  border-color: var(--accent);
  background: var(--paper);
  box-shadow: 0 0 0 3px color-mix(in oklch, var(--accent) 14%, transparent);
}
.cell-input.num { text-align: right; font-variant-numeric: tabular-nums; }

/* Standalone framed fields (title / notes / dates). */
.cell-input.framed { background: var(--paper); border-color: var(--border-strong); }

/* Labelled select/date/text fields above the table. */
.field { display: flex; flex-direction: column; gap: 5px; font-size: var(--fs-sm); color: var(--ink-2); }
.field > span { font-size: var(--fs-xs); letter-spacing: 0.04em; text-transform: uppercase; color: var(--ink-3); }
.field input, .field select {
  border: 1px solid var(--border-strong);
  background: var(--paper);
  padding: 9px 11px;
  font-family: inherit;
  color: var(--ink);
  border-radius: 3px;
}
.field input:focus, .field select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px color-mix(in oklch, var(--accent) 14%, transparent);
}
.detail-row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; border-bottom: 1px solid var(--border); }
</style>
```

This alone fixes the biggest issue. The remaining edits are polish.

### 2. Make the line-table row taller and the cells padded (base.css, global)

The line editors reuse the global `.table`, whose rows are `height: var(--row-h)`
(tight). Add a modifier so document tables breathe, and add it to the line `<table>`
in all four files (`class="table table--lines"`):

In `resources/css/base.css`, after the `.table .pad-r` rule (~line 625):

```css
/* Editable document line tables: roomier than data tables. */
.table--lines tbody td { height: auto; padding-top: 6px; padding-bottom: 6px; }
.table--lines tbody tr { cursor: default; }
.table--lines tbody tr:hover td { background: transparent; color: inherit; }
```

(The default `.table` hover tint fights with focused inputs; this neutralizes it
for editable tables.)

### 3. Stronger remove button (base.css, global)

The line "remove" uses `.icon-btn` with `<Icon name="close" />`, which looks the
same as "move up". Add a danger hover so deletion reads as destructive. In
`base.css` after the `.icon-btn:hover` rule (~line 488):

```css
.icon-btn--danger:hover { background: color-mix(in oklch, var(--red) 14%, transparent); color: var(--red); }
```

Then in all four files, add the modifier to the remove button only:

```html
<button class="icon-btn icon-btn--danger" title="remove" @click="removeLine(...)"><Icon name="close" /></button>
```

(Leave the move-up button as plain `.icon-btn`.)

### 4. Make the "entries in period" picker read as secondary (Invoices/Create.vue only)

That screen has two tables — the editable **Lines** and the read-only **Entries in
period** checklist — and they look identical, so it's unclear the second feeds the
first. Give the entries table `class="table table--picker"` and add to base.css:

```css
.table--picker { font-size: var(--fs-sm); }
.table--picker thead th, .table--picker tbody td { background: var(--bg-2); }
.table--picker tbody tr:hover td { background: var(--bg-hover); }
```

Optionally add, next to the "Entries in period" `<h3 class="section-title">`, a
small count of selected entries so the relationship is explicit — the page already
computes `selectedIds`:

```html
<span class="dim" style="font-size: var(--fs-xs)">{{ selectedIds.length }} selected</span>
```

## Don't touch

- The totals / VAT logic (`totalsForLines`, `roundTotalRappen`) and the `useForm`
  submit transforms — only styling and the small markup class/label additions above.
- The picker-mode "Choose a client" step in `Invoices/Create.vue`.

## Expected result

In all four create/edit screens, line-item description/hours/rate fields show a
soft filled background at rest, a border on hover, and an accent focus ring — so
it's obvious they're editable. Rows are roomier, "remove" turns red on hover, and
on the invoice create screen the billable-entries checklist clearly reads as a
secondary source that feeds the lines above.
