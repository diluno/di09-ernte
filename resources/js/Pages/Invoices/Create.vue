<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, default: null },
  project: { type: Object, default: null },
  period: { type: Object, default: null },
  entries: { type: Array, default: () => [] },
  suggested_lines: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] }, // populated only in picker mode (no client yet)
});

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
  key: i, description: l.description, hours: l.hours, rate: l.rate, vat_exempt: l.vat_exempt,
})));
let nextKey = props.suggested_lines.length;

function addLine() { lines.value.push({ key: nextKey++, description: '', hours: 0, rate: props.project?.rate_rappen ? Math.round(props.project.rate_rappen / 100) : 0, vat_exempt: false }); }
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

const VAT_RATE = 8.1;
const taxableRappen = computed(() => lines.value.filter((l) => !l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const exemptRappen = computed(() => lines.value.filter((l) => l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const subtotalRappen = computed(() => taxableRappen.value + exemptRappen.value);
const vatRappen = computed(() => Math.round(taxableRappen.value * VAT_RATE / 100));
const totalRappen = computed(() => subtotalRappen.value + vatRappen.value);

const form = useForm({});
function save() {
  form.transform(() => ({
    client_id: props.client.id,
    project_id: props.project?.id ?? null,
    period_start: from.value,
    period_end: to.value,
    entry_ids: selectedIds.value,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_exempt: !!l.vat_exempt,
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
  <div v-if="!client" style="padding: 0 28px 28px; max-width: 460px">
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

  <div v-else style="padding: 0 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Lines</h3>
      <table class="table">
        <thead>
          <tr>
            <th class="pad-l">Description</th>
            <th class="num" style="width: 80px">Hours</th>
            <th class="num" style="width: 100px">Rate</th>
            <th class="num" style="width: 120px">Amount</th>
            <th style="width: 60px">MwSt</th>
            <th style="width: 70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="l.key">
            <td class="pad-l"><AutoTextarea v-model="l.description" class="cell-input" placeholder="description" /></td>
            <td class="num"><input v-model="l.hours" type="number" min="0" step="0.25" class="cell-input num" /></td>
            <td class="num"><input v-model="l.rate" type="number" min="0" class="cell-input num" /></td>
            <td class="num strong">{{ fmtMoney(Math.round(Number(l.hours) * Number(l.rate) * 100)) }}</td>
            <td><label style="display: flex; gap: 4px; align-items: center"><input type="checkbox" v-model="l.vat_exempt" /><span class="dim" style="font-size: var(--fs-xs)">exempt</span></label></td>
            <td>
              <button class="icon-btn" title="move up" @click="moveUp(i)"><Icon name="chevron-up" /></button>
              <button class="icon-btn" title="remove" @click="removeLine(l.key)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one or widen the period.</td></tr>
        </tbody>
      </table>
      <button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>

      <h3 class="section-title" style="margin-top: 28px">Entries in period</h3>
      <div style="display: flex; gap: 12px; align-items: end; margin-bottom: 12px">
        <label class="field"><span>From</span><input type="date" v-model="from" @change="reloadPeriod" /></label>
        <label class="field"><span>To</span><input type="date" v-model="to" @change="reloadPeriod" /></label>
        <span class="dim" style="font-size: var(--fs-xs)">Changing the period re-queries billable, unbilled entries. Lines above are not auto-updated — edit them to match.</span>
      </div>
      <table class="table">
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

    <aside>
      <h3 class="section-title">Totals</h3>
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ VAT_RATE }}%</div><div class="v">{{ fmtMoney(vatRappen) }}</div>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        {{ selectedIds.length }} entr(y/ies) will be attached to this invoice and removed from "unbilled".
        Server recomputes all amounts on save.
      </p>
      <div v-if="Object.keys(form.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 12px">
        {{ Object.values(form.errors).join(' · ') }}
      </div>
    </aside>
  </div>
</template>

<style scoped>
.cell-input { width: 100%; border: 1px solid transparent; background: transparent; padding: 4px 6px; font-family: inherit; color: var(--ink); }
.cell-input:focus { outline: none; border-color: var(--accent); background: var(--paper); }
.cell-input.num { text-align: right; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field input, .field select { border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.field input:focus, .field select:focus { outline: none; border-color: var(--accent); }
</style>
