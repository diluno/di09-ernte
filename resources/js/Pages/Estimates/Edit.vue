<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import EstimateScopeEditor from '@/Components/EstimateScopeEditor.vue';
import AssumptionsEditor from '@/Components/AssumptionsEditor.vue';
import { assumptionsPayload, flattenLines, sectionsPayload, seedAssumptions, seedSections } from '@/formatters/estimateScope.js';
import { totalsForLines } from '@/formatters/vat.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  estimate: { type: Object, required: true }, // { id, number, client_id, project_id, title, notes, assumptions, recipients, sections }
  clients:  { type: Array, default: () => [] }, // [{id,name,contacts}]
  projects: { type: Array, default: () => [] }, // { id, name, client_id, rate }
  vat_rates: { type: Array, default: () => [] },
});

const clientId = ref(props.estimate.client_id);
const projectId = ref(props.estimate.project_id);
const title = ref(props.estimate.title ?? '');
const notes = ref(props.estimate.notes ?? '');

// Projects belonging to the selected client.
const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);
const selectedClientContacts = computed(() => props.clients.find((c) => c.id === clientId.value)?.contacts ?? []);

// Reset the project when the client changes (skip the initial assignment above).
watch(clientId, (val, old) => { if (old !== undefined && val !== old) projectId.value = null; });

// Reset recipients to the newly-selected client's default contacts (skip the initial load).
watch(selectedClientContacts, (contacts, oldContacts) => {
  if (oldContacts === undefined) return;
  form.recipients = contacts.filter((c) => c.is_default).map(({ name, email }) => ({ name, email }));
});

// Editable scope, seeded from the existing estimate's sections and assumptions.
const defaultRate = computed(() => selectedProject.value?.rate ?? 0);
const sections = ref(seedSections(props.estimate.sections, defaultRate.value));
const assumptions = ref(seedAssumptions(props.estimate.assumptions));

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtRate(rate) { return Number(rate).toFixed(2).replace(/\.?0+$/, ''); }

const lines = computed(() => flattenLines(sections.value));
const totals = computed(() => totalsForLines(lines.value, props.vat_rates, props.estimate.tax_date));
const subtotalRappen = computed(() => totals.value.subtotal);
const totalRappen = computed(() => totals.value.total);

const canSave = computed(() => clientId.value && lines.value.length > 0);

const form = useForm({ recipients: props.estimate.recipients ?? [] });
function save() {
  form.transform(() => ({
    client_id: clientId.value,
    project_id: projectId.value || null,
    title: title.value || null,
    notes: notes.value || null,
    recipients: form.recipients,
    assumptions: assumptionsPayload(assumptions.value),
    sections: sectionsPayload(sections.value),
  })).patch(`/estimates/${props.estimate.id}`);
}
</script>

<template>
  <Head :title="`Edit estimate #${estimate.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">~ / estimates</Link><span class="ascii-dot">/</span><Link :href="`/estimates/${estimate.number}`">#{{ estimate.number }}</Link><span class="ascii-dot">/</span><span>edit</span>
      </div>
      <h1 class="page-title">Edit estimate #{{ estimate.number }}</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link :href="`/estimates/${estimate.number}`" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Save changes</button>
    </div>
  </div>

  <div class="doc-grid" style="padding: 20px 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
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

      <h3 class="section-title" style="margin-top: 28px">Scope</h3>
      <EstimateScopeEditor v-model="sections" :default-rate="defaultRate" />

      <h3 class="section-title" style="margin-top: 28px">Assumptions</h3>
      <AssumptionsEditor v-model="assumptions" />

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input" rows="3" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px" placeholder="Optional notes shown on the estimate PDF…"></textarea>

      <h3 class="section-title" style="margin-top: 28px">Recipients</h3>
      <div class="field">
        <RecipientPicker :contacts="selectedClientContacts" v-model="form.recipients" />
      </div>
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
              :disabled="form.processing || !canSave" @click="save">
        Save changes
      </button>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        Server recomputes all amounts on save. Only draft estimates can be edited.
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
