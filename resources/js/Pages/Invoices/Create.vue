<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import { totalsForLines } from '@/formatters/vat.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, default: null },
  project: { type: Object, default: null },
  period: { type: Object, default: null },
  entries: { type: Array, default: () => [] },
  suggested_lines: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] }, // populated only in picker mode (no client yet)
  vat_rates: { type: Array, default: () => [] },
});

// The chosen client's contacts (client entries carry a `contacts` key in picker mode,
// or the loaded client itself carries it once selected).
const selectedClientContacts = computed(() => props.client?.contacts ?? []);

// Picker mode: no client chosen yet — let the user pick one, then reload with ?client=.
const picked = ref(null);
function chooseClient() {
  if (picked.value) router.get('/invoices/new', { client: picked.value });
}

// Period: reload the page with new from/to to re-query eligible entries.
const from = ref(props.period?.start ?? '');
const to = ref(props.period?.end ?? '');
function reloadPeriod() {
  router.get('/invoices/new', {
    client: props.client.id,
    project: props.project?.id || undefined,
    from: from.value,
    to: to.value,
  }, { preserveState: false });
}

// Entry checklist: all selected by default.
const selected = reactive(Object.fromEntries(props.entries.map((e) => [e.id, true])));
const selectedIds = computed(() => props.entries.filter((e) => selected[e.id]).map((e) => e.id));

// Editable lines, seeded from the server's suggested grouping.
const lines = ref(props.suggested_lines.map((l, i) => ({
  key: i, description: l.description, hours: l.hours, rate: l.rate, vat_exempt: false,
})));
let nextKey = props.suggested_lines.length;

function addLine() {
  lines.value.push({
    key: nextKey++,
    description: '',
    hours: 0,
    rate: props.project?.rate_rappen ? Math.round(props.project.rate_rappen / 100) : 0,
    vat_exempt: false,
  });
}
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtRate(rate) { return Number(rate).toFixed(2).replace(/\.?0+$/, ''); }

const totals = computed(() => totalsForLines(lines.value, props.vat_rates, to.value));
const subtotalRappen = computed(() => totals.value.subtotal);
const totalRappen = computed(() => totals.value.total);

const form = useForm({
  recipients: selectedClientContacts.value.filter((c) => c.is_default).map(({ name, email }) => ({ name, email })),
});
const title = ref('');

function save() {
  form.transform(() => ({
    client_id: props.client.id,
    title: title.value || null,
    project_id: props.project?.id ?? null,
    period_start: from.value,
    period_end: to.value,
    entry_ids: selectedIds.value,
    recipients: form.recipients,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_exempt: Boolean(l.vat_exempt),
    })),
  })).post('/invoices');
}
</script>

<template>
  <Head :title="client ? `New invoice for ${client.name}` : 'New invoice'" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">~ / invoices</Link><span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">
        New invoice
        <span v-if="client" class="meta">{{ client.name }}<span v-if="project" class="ascii-dot">·</span><span v-if="project">{{ project.name }}</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/invoices" class="btn ghost">Cancel</Link>
      <button v-if="client" class="btn primary" :disabled="form.processing || lines.length === 0" @click="save">Create draft</button>
    </div>
  </div>

  <!-- Picker mode: choose a client first. -->
  <div v-if="!client" style="padding: 20px 28px 28px; max-width: 460px">
    <h3 class="section-title">Choose a client</h3>
    <p class="dim" style="font-size: var(--fs-sm); margin: 0 0 12px">
      Pick the client to invoice — you'll then select billable, unbilled entries for the period.
      To start from a specific project, use the <strong>+ Invoice</strong> button on a project or client instead.
    </p>
    <label class="field" style="margin-bottom: 12px">
      <span>Client</span>
      <select v-model="picked">
        <option :value="null" disabled>Select a client…</option>
        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
    </label>
    <button class="btn primary" :disabled="!picked" @click="chooseClient">Continue</button>
    <p v-if="clients.length === 0" class="muted" style="font-size: var(--fs-sm); margin-top: 12px">
      No active clients. <Link href="/clients/create">Create one first.</Link>
    </p>
  </div>

  <div v-else class="doc-grid" style="padding: 20px 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Document</h3>
      <input v-model="title" class="cell-input framed" style="margin-bottom: 24px" placeholder="Title shown on the invoice PDF" />

      <h3 class="section-title">Lines</h3>
      <div class="lines-card">
      <table class="table table--lines">
        <thead>
          <tr>
            <th class="pad-l">Description</th>
            <th class="num" style="width: 80px">Hours</th>
            <th class="num" style="width: 100px">Rate</th>
            <th class="num" style="width: 120px">Amount</th>
            <th style="width: 52px; text-align: center" title="Charge MwSt on this line">MwSt</th>
            <th style="width: 70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="l.key">
            <td class="pad-l"><AutoTextarea v-model="l.description" class="cell-input" placeholder="description" /></td>
            <td class="num"><input v-model="l.hours" type="number" min="0" step="0.25" class="cell-input num" /></td>
            <td class="num"><input v-model="l.rate" type="number" min="0" class="cell-input num" /></td>
            <td class="num strong">{{ fmtMoney(Math.round(Number(l.hours) * Number(l.rate) * 100)) }}</td>
            <td style="text-align: center">
              <input type="checkbox" :checked="!l.vat_exempt" title="Charge MwSt on this line"
                     @change="l.vat_exempt = !$event.target.checked" />
            </td>
            <td>
              <button class="icon-btn" title="move up" @click="moveUp(i)"><Icon name="chevron-up" /></button>
              <button class="icon-btn icon-btn--danger" title="remove" @click="removeLine(l.key)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one or widen the period.</td></tr>
        </tbody>
      </table>
      <button class="add-line" @click="addLine"><span style="font-family: var(--font-mono)">+</span> Add line</button>
      </div>

      <h3 class="section-title" style="margin-top: 28px">Recipients</h3>
      <div class="field">
        <RecipientPicker :contacts="selectedClientContacts" v-model="form.recipients" />
      </div>

      <h3 class="section-title" style="margin-top: 28px">Entries in period<span class="dim" style="font-size: var(--fs-xs)">{{ selectedIds.length }} selected</span></h3>
      <div style="display: flex; gap: 12px; align-items: end; margin-bottom: 12px">
        <label class="field"><span>From</span><input type="date" v-model="from" @change="reloadPeriod" /></label>
        <label class="field"><span>To</span><input type="date" v-model="to" @change="reloadPeriod" /></label>
        <span class="dim" style="font-size: var(--fs-xs)">Changing the period re-queries billable, unbilled entries. Lines above are not auto-updated — edit them to match.</span>
      </div>
      <table class="table table--picker">
        <thead><tr><th class="pad-l check"></th><th>Entry</th><th>Project</th><th class="num">Hours</th></tr></thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id">
            <td class="pad-l check"><input type="checkbox" v-model="selected[e.id]" /></td>
            <td>{{ e.description }}</td>
            <td class="dim">{{ e.project.code }}</td>
            <td class="num">{{ e.hours.toFixed(2) }}h</td>
          </tr>
          <tr v-if="entries.length === 0"><td colspan="4" class="pad-l muted" style="padding: 16px">No billable, unbilled entries in this period.</td></tr>
        </tbody>
      </table>
    </div>

    <aside class="summary-card">
      <div class="summary-head">Totals</div>
      <div class="summary-body">
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ fmtRate(totals.rate) }}%</div><div class="v">{{ fmtMoney(totals.vat) }}</div>
        <template v-if="totals.rounding !== 0">
          <div class="label">Rundung</div><div class="v">{{ fmtMoney(totals.rounding) }}</div>
        </template>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <button class="btn primary" style="width: 100%; justify-content: center; margin-top: 16px"
              :disabled="form.processing || lines.length === 0" @click="save">
        Create draft
      </button>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        {{ selectedIds.length }} entr(y/ies) will be attached to this invoice and removed from "unbilled".
        Server recomputes all amounts on save.
      </p>
      <div v-if="Object.keys(form.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 12px">
        {{ Object.values(form.errors).join(' · ') }}
      </div>
      </div>
    </aside>
  </div>
</template>

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
