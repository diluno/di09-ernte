<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import { activeVatRates, defaultVatCode, totalsForLines, vatLabelForCode } from '@/formatters/vat.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients: { type: Array, default: () => [] },
  projects: { type: Array, default: () => [] },
  default_vat_rate: { type: Number, default: 8.1 },
  vat_rates: { type: Array, default: () => [] },
});

const clientId = ref(null);
const projectId = ref(null);
const title = ref('');
const notes = ref('');
const cadence = ref('monthly');
const nextRunOn = ref(new Date().toISOString().slice(0, 10));
const autoSend = ref(false);

const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);
watch(clientId, () => { projectId.value = null; });

const lines = ref([]);
let nextKey = 0;
function addLine() {
  lines.value.push({
    key: nextKey++,
    description: '',
    hours: 1,
    rate: selectedProject.value?.rate ?? 0,
    vat_code: defaultVatCode(props.vat_rates, nextRunOn.value),
  });
}
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }
addLine();

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtRate(rate) { return Number(rate).toFixed(2).replace(/\.?0+$/, ''); }

const vatOptions = computed(() => activeVatRates(props.vat_rates, nextRunOn.value));
const totals = computed(() => totalsForLines(lines.value, props.vat_rates, nextRunOn.value));
const subtotalRappen = computed(() => totals.value.subtotal);
const vatRappen = computed(() => totals.value.vat);
const totalRappen = computed(() => totals.value.total);

const canSave = computed(() => clientId.value && lines.value.length > 0 && nextRunOn.value);

const form = useForm({});
function save() {
  form.transform(() => ({
    client_id: clientId.value,
    project_id: projectId.value || null,
    title: title.value || null,
    notes: notes.value || null,
    cadence: cadence.value,
    next_run_on: nextRunOn.value,
    auto_send: autoSend.value,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_code: l.vat_code,
    })),
  })).post('/recurring-invoices');
}
</script>

<template>
  <Head title="New recurring schedule" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/recurring-invoices">~ / recurring</Link><span class="ascii-dot">/</span><span>new</span></div>
      <h1 class="page-title">New recurring schedule</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/recurring-invoices" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Create schedule</button>
    </div>
  </div>

  <div style="padding: 0 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Title</h3>
      <input v-model="title" class="cell-input" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px; margin-bottom: 20px" placeholder="e.g. Hosting — {period}  (the {period} placeholder becomes &quot;June 2026&quot;, &quot;Q2 2026&quot;, …)" />

      <h3 class="section-title">Client</h3>
      <div style="display: flex; gap: 12px; margin-bottom: 20px">
        <label class="field" style="flex: 1">
          <span>Client</span>
          <select v-model="clientId">
            <option :value="null" disabled>Select a client…</option>
            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="field" style="flex: 1">
          <span>Project (optional)</span>
          <select v-model="projectId" :disabled="!clientId">
            <option :value="null">—</option>
            <option v-for="p in clientProjects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>
      </div>

      <h3 class="section-title">Schedule</h3>
      <div style="display: flex; gap: 12px; margin-bottom: 20px">
        <label class="field" style="flex: 1">
          <span>Cadence</span>
          <select v-model="cadence">
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="half-yearly">Half-yearly</option>
            <option value="yearly">Yearly</option>
          </select>
        </label>
        <label class="field" style="flex: 1">
          <span>First run on</span>
          <input v-model="nextRunOn" type="date" />
        </label>
      </div>
      <label style="display: flex; gap: 8px; align-items: center; margin-bottom: 20px">
        <input type="checkbox" v-model="autoSend" />
        <span class="dim" style="font-size: var(--fs-sm)">Auto-send: email the invoice to the client on generation (otherwise it waits as a draft for review).</span>
      </label>

      <h3 class="section-title">Lines</h3>
      <table class="table">
        <thead>
          <tr>
            <th class="pad-l">Description</th>
            <th class="num" style="width: 80px">Hours</th>
            <th class="num" style="width: 100px">Rate</th>
            <th class="num" style="width: 120px">Amount</th>
            <th style="width: 130px">MwSt</th>
            <th style="width: 70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="l.key">
            <td class="pad-l"><AutoTextarea v-model="l.description" class="cell-input" placeholder="description" /></td>
            <td class="num"><input v-model="l.hours" type="number" min="0" step="0.25" class="cell-input num" /></td>
            <td class="num"><input v-model="l.rate" type="number" min="0" class="cell-input num" /></td>
            <td class="num strong">{{ fmtMoney(Math.round(Number(l.hours) * Number(l.rate) * 100)) }}</td>
            <td>
              <select v-model="l.vat_code" class="cell-input">
                <option v-for="rate in vatOptions" :key="rate.code" :value="rate.code">{{ vatLabelForCode(vat_rates, rate.code, nextRunOn) }}</option>
              </select>
            </td>
            <td>
              <button class="icon-btn" title="move up" @click="moveUp(i)"><Icon name="chevron-up" /></button>
              <button class="icon-btn" title="remove" @click="removeLine(l.key)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one to start.</td></tr>
        </tbody>
      </table>
      <button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input" rows="3" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px" placeholder="Optional notes copied to every generated invoice…"></textarea>
    </div>

    <aside>
      <h3 class="section-title">Per-invoice totals</h3>
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <template v-for="row in totals.breakdown" :key="row.rate">
          <div class="label">MwSt {{ fmtRate(row.rate) }}%</div><div class="v">{{ fmtMoney(row.vat_rappen) }}</div>
        </template>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        Each cycle generates an invoice with these lines. The server recomputes amounts. A past first-run date is snapped forward to the next future cycle — no back-billing.
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
.field select, .field input { border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.field select:focus, .field input:focus { outline: none; border-color: var(--accent); }
</style>
