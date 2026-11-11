# Ledger building blocks

Shared classes in `resources/css/base.css` (tokens in `tokens.css`) for converting the remaining pages to the Ledger look. Reference pages: `Pages/Invoices/Index.vue`, `Pages/Invoices/Show.vue`. Values and type sizes: `design/ledger/README.md`.

Rules that hold everywhere:

- Radius 0, hairlines only: `--rule` (ink) for structure, `--border` between rows/cells.
- Red (`--red`, `.is-red`) means overdue, expired, needs attention, or destructive. Never "outstanding" or "open" money, and never a zero.
- One `.btn.primary` per screen (page head *or* form footer, not both).
- Horizontal padding is `var(--gutter)` (40px, 20px below 900px). Do not hard-code 40px or put `padding: 20px 28px` on wrappers.
- No page-level `<style scoped>` copies of `.field`, `.input`, `.err`, `.badge`, `.btn.xs`. Delete them when converting a page.

## Page head

Crumb, 64px title (it scales down on narrow windows), actions on the right. The actions wrap under the title when there is no room.

```html
<div class="page-head">
  <div>
    <div class="crumb"><Link href="/estimates">Estimates</Link><span>/</span><span>{{ e.number }}</span></div>
    <h1 class="page-title"><span>{{ title }}</span><span class="meta">◐ sent</span></h1>
  </div>
  <div class="page-actions">
    <a :href="pdf_url" class="btn"><Icon name="download" />PDF</a>
    <button class="btn primary">Accept</button>
    <ActionMenu :items="menuItems" :disabled="pending" />
  </div>
</div>
```

`.meta.is-red` turns the status red. Crumbs are links to parents, not `~ / path` strings.

## Stats strip

The strip goes directly under the page head (or the filter/quarter bar), never inside a padded column. Use four cells. It becomes 2×2 below 1200px and one column below 600px; the hairlines are handled for any count.

```html
<div class="stats">
  <div class="stat">
    <div class="label">Open</div>
    <div class="val">{{ fmtMoney(stats.open, { whole: true }) }}<span class="unit">CHF</span></div>
    <div class="foot">{{ stats.open_count }} sent</div>
  </div>
  <div class="stat" :class="{ 'is-red': stats.expired_count > 0 }">…</div>
</div>
```

`.val` is mono 40px. Put the currency in `.unit` and keep it out of the number. Only add `.is-red` conditionally, and only for overdue/expired counts above zero.

## Filter row

```html
<div class="filter-row">
  <button v-for="t in TABS" class="chip" :class="{ 'is-red': t.red }" :aria-pressed="filter === t.id" @click="setFilter(t.id)">
    {{ t.label }} <span class="dim">{{ t.count }}</span>
  </button>
  <label class="search"><input v-model="q" placeholder="filter…" aria-label="Filter" data-filter-input /><span class="kbd sm">/</span></label>
</div>
```

## Tables

Always wrap a data table in `.table-wrap`. It scrolls sideways when the columns do not fit. Below 900px it keeps a 680px minimum width. The first cell gets `.pad-l` and the last gets `.pad-r`, which aligns them with the gutter.

```html
<div class="table-wrap">
  <table class="table table--docs">
    <thead><tr><th class="pad-l" style="width: 130px">No.</th><th>Client</th><th class="num" style="width: 150px">CHF</th><th class="row-actions pad-r"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      <tr v-for="r in rows" class="is-clickable" :class="`is-${r.status}`" @click="rowClick($event, url(r))">
        <td class="pad-l code"><Link :href="url(r)" class="row-link">{{ r.number }}</Link></td>
        <td class="trunc">{{ r.client.name }}</td>
        <td class="money">{{ fmtMoney(r.total) }}</td>
        <td class="row-actions"><ActionMenu size="sm" :items="itemsFor(r)" :label="`Actions for ${r.number}`" /></td>
      </tr>
      <tr v-if="!rows.length"><td colspan="4" class="is-empty">No estimates match this filter.</td></tr>
    </tbody>
  </table>
</div>
```

- Cell classes: `code` (mono id), `subject` (15px, ellipsis), `trunc`/`dim` (muted, ellipsis), `num` (mono, right-aligned, muted; add `.is-red` for a late date), `money` (mono, right-aligned, 600), `strong`.
- Row classes: `is-clickable` (pointer; needs `rowClick` and a real `.row-link` in the first cell), `is-overdue` (red tint and left bar), `is-draft` (muted, italic subject), `is-paid` (quiet).
- `.table--docs` uses a fixed layout, so give every `<th>` a width except the column that should absorb the slack.
- `.row-actions` is the trailing cell for an `ActionMenu size="sm"` or one or two `.btn.sm` buttons. Clicks on buttons and menu items inside a clickable row do not trigger navigation.

## Actions menu: `Components/ActionMenu.vue`

This is the "…" button with a `.menu`. Use it for anything that is not the single primary action, and always for destructive actions. It has keyboard support (↓/↑/Home/End, Esc returns focus to the button) and closes on an outside click, scroll or resize. It is positioned `fixed`, so it is not clipped inside a scrolling `.table-wrap`.

```js
const menuItems = computed(() => [
  { label: 'Edit', href: `/recurring-invoices/${s.id}/edit` },
  s.paused ? { label: 'Resume', action: () => resume(s) } : { label: 'Pause', action: () => pause(s) },
  { label: 'Generate now', action: () => run(s) },
  { separator: true },                       // ink rule before the destructive group
  { label: 'Delete…', action: () => destroy(s), danger: true },
]);
```

Props: `items` (falsy entries are skipped), `size` (`'md'` for 40px in `.page-actions`, `'sm'` for 28px in rows), `label` (accessible name, default "More actions"), `disabled` (disables the action items, e.g. while `usePendingAction` is pending). Item fields: `label`, `href` (Inertia link) or `action` (function), `danger`, `disabled`, `separator`. Keep the existing `window.confirm` copy inside the action functions.

## Buttons

| Class | Use |
|---|---|
| `.btn` | Secondary action: ink outline, mono 12px |
| `.btn.primary` | The one main action: solid ink |
| `.btn.ghost` | Tertiary action, e.g. Cancel or "+ Add task": no border until hover |
| `.btn.danger` | Destructive action outside a menu (rare; prefer a menu item with `danger: true`) |
| `.btn.sm` | Compact size for any variant: table rows, ledger heads, inline toolbars |
| `.btn.icon` / `.btn.icon.sm` | Square icon-only button, 40px or 28px; needs an `aria-label` |

A disabled button of any variant is drawn as a grey outline with no fill, so a solid ink button is always enabled. Add a `title` that explains why the button is disabled. Do not use `.btn.xs` or inline `style="color: var(--red)"`.

```html
<button class="btn primary" :disabled="form.processing">Save</button>
<Link href="/clients" class="btn ghost">Cancel</Link>
<button class="btn sm" @click="confirm(line)">Confirm</button>
```

## Status badges

The status is shown as a mark plus an uppercase word, with no box. The colour comes from the status class.

```html
<span class="badge dot" :class="status">{{ status }}</span>   <!-- draft ○, sent ◐, paid ✓, overdue ● red -->
<span class="badge dot paused">paused</span>                    <!-- ‖, muted -->
<span class="mono-tag">auto-send</span>                         <!-- neutral boxed tag for a property, not a status -->
```

Modifiers: `draft`, `sent`, `paid`, `overdue`/`expired`/`declined`/`over` (red), `active`, `accepted`, `archived`/`void`, `paused`, `warn`, `filed`, `working`, `attention` (red).

## Empty state

Use it for a whole page or section that has no rows yet. For a filter with no results inside a table, use `td.is-empty` instead (see Tables).

```html
<div v-if="!schedules.length" class="empty-state">
  <h2 class="empty-state__title">No recurring schedules yet</h2>
  <p>Bill retainers, hosting or maintenance automatically every month, quarter or year.</p>
  <div class="empty-state__actions"><Link href="/recurring-invoices/new" class="btn primary">New schedule</Link></div>
</div>
```

(`.ledger-empty` is the same block without the title and actions, used on Bank.)

## Form page

The page head has no primary button. The form body is limited to 980px wide and uses the gutter. Sections are separated by hairlines. The sticky footer holds exactly one primary action.

```html
<form class="form-page" @submit.prevent="submit">
  <div class="form-body">
    <section class="form-section">
      <h2 class="section-title">Client</h2>
      <div class="form-grid">
        <label class="field span-2">
          <span>Name</span>
          <input v-model="form.name" class="input" required :aria-invalid="!!form.errors.name" />
          <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
        </label>
        <label class="field">
          <span>Language</span>
          <select v-model="form.locale" class="select"><option value="de">German</option></select>
          <small class="hint">Mails, PDFs and the QR bill.</small>
        </label>
        <label class="field is-check"><input type="checkbox" v-model="form.auto_send" /><span>Send automatically</span></label>
      </div>
    </section>
  </div>
  <div class="form-foot">
    <button type="submit" class="btn primary" :disabled="form.processing">Create client</button>
    <Link href="/clients" class="btn ghost">Cancel</Link>
    <span v-if="form.isDirty" class="form-foot__aside">Unsaved changes</span>
  </div>
</form>
```

- `.form-grid` has 2 columns. Use `.cols-3` for 3 columns. `.span-2` and `.span-all` widen a field. Below 760px the grid is one column.
- Fields: `.field` > first `<span>` is the label (11px uppercase). Then comes the control: `.input`, `.select` (flat, with a chevron) or `.textarea` (resizable). `.input.num` is for right-aligned mono numbers. After the control comes `.hint` (help) and/or `.error` (red). Set `aria-invalid` to get a red border.
- `.section-title` is the section heading (11px uppercase over an ink rule). A `<p class="hint">` directly under it serves as section intro text.
- `.settings-page` and `.settings-section` are older names for `.form-body` and `.form-section`. They have the same rules. Use the new names.

## Document editor (form with a summary aside)

Use this for Invoice and Estimate create/edit and the recurring form. The main column holds the line editor. A 340px sticky summary sits on the right and stacks below 1140px.

```html
<div class="doc-grid">
  <div>
    <h3 class="section-title">Lines</h3>
    <div class="lines-card"><table class="table table--lines">…</table><button class="add-line">+ Add line</button></div>
  </div>
  <aside class="summary-card">
    <div class="summary-head">Totals</div>
    <div class="summary-body"><TotalsCard … /></div>
  </aside>
</div>
```

`.doc-grid` now carries its own grid, gap and gutter padding. Remove the inline `style="padding: 20px 28px …; grid-template-columns: …"` when converting a page. If the editor has a sticky `.form-foot` with the primary action, do not repeat that action in the summary card.

## Detail page (main column + aside)

```html
<div class="detail-grid">
  <div class="detail-main">…sections with .section-title…</div>
  <aside class="detail-side">
    <h3 class="section-title">Details</h3>
    <dl class="kv"><dt>Client</dt><dd>…</dd></dl>
  </aside>
</div>
```

Aside rows can use `.side-row` (with `.sub`, `.split`, `.grid` + `.when`, `.meta`, `.is-muted`, `.is-red`) as on invoice detail. For a document preview with an aside, use `.invoice-page` > `.invoice-doc-wrap` (iframe `.invoice-sheet`) + `.invoice-side`. `detail-grid`, `invoice-page`, `receipt-page` and `timer-stage` all stack to one column below 1100px.

## Breakpoints

| Width | What changes |
|---|---|
| ≥ 1280 | Reference design |
| ≤ 1279 | The month chart scrolls sideways |
| ≤ 1199 | `.stats` becomes 2×2 |
| ≤ 1099 | Detail layouts stack |
| ≤ 899 | The sidebar becomes a drawer (topbar menu button), `--gutter` is 20px, tables scroll inside `.table-wrap` / `.ledger` |
| ≤ 760 | `.form-grid` becomes one column |
| ≤ 599 | `.stats` becomes one column; the topbar shows only the icon for "Jump to…" |

Row tools that appear only on hover (`.doc-tools`, `.ledger-actions`, `.note-actions`, …) also appear on `:focus-within` and are always visible on touch (`hover: none`). New hover-only tools need the same treatment.
