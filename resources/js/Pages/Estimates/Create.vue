<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients:  { type: Array, default: () => [] },
  projects: { type: Array, default: () => [] }, // { id, name, client_id, rate }
});

const clientId = ref(null);
const projectId = ref(null);
const title = ref('');
const notes = ref('');

// Projects belonging to the selected client.
const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);

// Reset the project when the client changes.
watch(clientId, () => { projectId.value = null; });

// Editable lines (manual entry).
const lines = ref([]);
let nextKey = 0;
function addLine() {
  lines.value.push({ key: nextKey++, description: '', hours: 0, rate: selectedProject.value?.rate ?? 0, vat_exempt: false });
}
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }

// Seed one empty line on mount for convenience.
addLine();

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

const VAT_RATE = 8.1;
const taxableRappen = computed(() => lines.value.filter((l) => !l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const exemptRappen = computed(() => lines.value.filter((l) => l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const subtotalRappen = computed(() => taxableRappen.value + exemptRappen.value);
const vatRappen = computed(() => Math.round(taxableRappen.value * VAT_RATE / 100));
const totalRappen = computed(() => subtotalRappen.value + vatRappen.value);

const canSave = computed(() => clientId.value && lines.value.length > 0);

const form = useForm({});
function save() {
  form.transform(() => ({
    client_id: clientId.value,
    project_id: projectId.value || null,
    title: title.value || null,
    notes: notes.value || null,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_exempt: !!l.vat_exempt,
    })),
  })).post('/estimates');
}
</script>

<template>
  <Head title="New estimate" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">~ / estimates</Link><span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">New estimate</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/estimates" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Create draft</button>
    </div>
  </div>

  <div style="padding: 0 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Title</h3>
      <input v-model="title" class="cell-input" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px; margin-bottom: 20px" placeholder="e.g. Partnerschaft auf Augenhöhe — shown at the top of the PDF" />

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
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one to start.</td></tr>
        </tbody>
      </table>
      <button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input" rows="3" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px" placeholder="Optional notes shown on the estimate PDF…"></textarea>
    </div>

    <aside>
      <h3 class="section-title">Totals</h3>
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ VAT_RATE }}%</div><div class="v">{{ fmtMoney(vatRappen) }}</div>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        Server recomputes all amounts on save. The estimate is created as a draft; send it to stamp the validity date and email the client.
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
.field select { border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.field select:focus { outline: none; border-color: var(--accent); }
</style>
