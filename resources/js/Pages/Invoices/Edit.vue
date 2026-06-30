<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import { roundTotalRappen } from '@/formatters/vat';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoice: { type: Object, required: true },
});

const title = ref(props.invoice.title ?? '');
const notes = ref(props.invoice.notes ?? '');
const periodStart = ref(props.invoice.period_start ?? '');
const periodEnd = ref(props.invoice.period_end ?? '');

let nextKey = 0;
const lines = ref(props.invoice.lines.map((line) => ({
  key: nextKey++,
  description: line.description,
  hours: line.hours,
  rate: Number(line.rate_rappen ?? 0) / 100,
})));

function addLine() {
  lines.value.push({
    key: nextKey++,
    description: '',
    hours: 0,
    rate: lines.value.length ? lines.value[lines.value.length - 1].rate : 0,
  });
}
function removeLine(key) { lines.value = lines.value.filter((line) => line.key !== key); }
function moveUp(index) {
  if (index > 0) {
    const current = lines.value;
    [current[index - 1], current[index]] = [current[index], current[index - 1]];
  }
}

if (lines.value.length === 0) addLine();

function lineAmountRappen(line) {
  return Math.round(Number(line.hours || 0) * Number(line.rate || 0) * 100);
}
function fmtMoney(rappen) {
  return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtRate(rate) {
  return Number(rate).toFixed(2).replace(/\.?0+$/, '');
}

const totals = computed(() => {
  const subtotal = lines.value.reduce((sum, line) => sum + lineAmountRappen(line), 0);
  const vat = Math.round((subtotal * Number(props.invoice.vat_rate || 0)) / 100);
  const total = roundTotalRappen(subtotal + vat);
  return { subtotal, vat, rounding: total - (subtotal + vat), total };
});
const canSave = computed(() => lines.value.length > 0);

const form = useForm({});
function save() {
  form.transform(() => ({
    title: title.value || null,
    notes: notes.value || null,
    period_start: periodStart.value || null,
    period_end: periodEnd.value || null,
    lines: lines.value.map((line) => ({
      description: line.description,
      hours: Number(line.hours),
      rate_rappen: Math.round(Number(line.rate) * 100),
    })),
  })).patch(`/invoices/${props.invoice.id}`);
}
</script>

<template>
  <Head :title="`Edit invoice #${invoice.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">~ / invoices</Link><span class="ascii-dot">/</span><Link :href="`/invoices/${invoice.number}`">#{{ invoice.number }}</Link><span class="ascii-dot">/</span><span>edit</span>
      </div>
      <h1 class="page-title">
        Edit invoice #{{ invoice.number }}
        <span class="meta">{{ invoice.client.name }}</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link :href="`/invoices/${invoice.number}`" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Save changes</button>
    </div>
  </div>

  <div class="doc-grid" style="padding: 20px 28px 28px; display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 28px">
    <div>
      <h3 class="section-title">Document</h3>
      <input v-model="title" class="cell-input framed" style="margin-bottom: 12px" placeholder="Title shown on the invoice PDF" />

      <div style="display: flex; gap: 12px; margin-bottom: 24px">
        <label class="field" style="flex: 1">
          <span>Period start</span>
          <input v-model="periodStart" type="date" />
        </label>
        <label class="field" style="flex: 1">
          <span>Period end</span>
          <input v-model="periodEnd" type="date" />
        </label>
      </div>

      <h3 class="section-title">Lines</h3>
      <div class="lines-card">
      <table class="table table--lines">
        <thead>
          <tr>
            <th class="pad-l">Description</th>
            <th class="num" style="width: 80px">Hours</th>
            <th class="num" style="width: 110px">Rate</th>
            <th class="num" style="width: 120px">Amount</th>
            <th style="width: 70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(line, index) in lines" :key="line.key">
            <td class="pad-l"><AutoTextarea v-model="line.description" class="cell-input" placeholder="description" /></td>
            <td class="num"><input v-model="line.hours" type="number" min="0" step="0.25" class="cell-input num" /></td>
            <td class="num"><input v-model="line.rate" type="number" min="0" step="0.01" class="cell-input num" /></td>
            <td class="num strong">{{ fmtMoney(lineAmountRappen(line)) }}</td>
            <td>
              <button class="icon-btn" title="move up" @click="moveUp(index)"><Icon name="chevron-up" /></button>
              <button class="icon-btn icon-btn--danger" title="remove" @click="removeLine(line.key)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="5" class="pad-l muted" style="padding: 16px">No lines. Add one to start.</td></tr>
        </tbody>
      </table>
      <button class="add-line" @click="addLine"><span style="font-family: var(--font-mono)">+</span> Add line</button>
      </div>

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input framed" rows="4" placeholder="Optional notes shown on the invoice PDF"></textarea>
    </div>

    <aside class="summary-card">
      <div class="summary-head">Totals</div>
      <div class="summary-body">
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(totals.subtotal) }}</div>
        <div class="label">MwSt {{ fmtRate(invoice.vat_rate) }}%</div><div class="v">{{ fmtMoney(totals.vat) }}</div>
        <template v-if="totals.rounding !== 0">
          <div class="label">Rundung</div><div class="v">{{ fmtMoney(totals.rounding) }}</div>
        </template>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totals.total) }}</div>
      </div>
      <button class="btn primary" style="width: 100%; justify-content: center; margin-top: 16px"
              :disabled="form.processing || !canSave" @click="save">
        Save changes
      </button>

      <h3 class="section-title" style="margin-top: 24px">Details</h3>
      <div style="font-size: var(--fs-sm); color: var(--ink-2)">
        <div class="detail-row"><span>Client</span><span class="muted">{{ invoice.client.name }}</span></div>
        <div v-if="invoice.project_name" class="detail-row"><span>Project</span><span class="muted">{{ invoice.project_name }}</span></div>
      </div>

      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        Server recomputes all amounts on save. Only draft invoices can be edited.
      </p>
      <div v-if="Object.keys(form.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 12px">
        {{ Object.values(form.errors).join(' / ') }}
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
