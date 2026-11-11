<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import EstimateScopeEditor from '@/Components/EstimateScopeEditor.vue';
import AssumptionsEditor from '@/Components/AssumptionsEditor.vue';
import { assumptionsPayload, flattenLines, sectionsPayload, seedAssumptions, seedSections } from '@/formatters/estimateScope.js';
import TotalsCard from '@/Components/TotalsCard.vue';
import { useDocumentTotals } from '@/composables/useDocumentTotals.js';
import { useDirtySnapshot, useUnsavedGuard } from '@/composables/useUnsavedGuard.js';

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

const lines = computed(() => flattenLines(sections.value));
const totals = useDocumentTotals(lines, { catalog: () => props.vat_rates, date: () => props.estimate.tax_date });

const canSave = computed(() => clientId.value && lines.value.length > 0);
const saveBlockedReason = computed(() => {
  if (!clientId.value) return 'Select a client first';
  if (!lines.value.length) return 'Add at least one line';
  return undefined;
});

// Language of the PDF and the mail.
const locale = ref(props.estimate.locale ?? 'de');

const form = useForm({ recipients: props.estimate.recipients ?? [] });
const payload = () => ({
  client_id: clientId.value,
  project_id: projectId.value || null,
  locale: locale.value,
  title: title.value || null,
  notes: notes.value || null,
  recipients: form.recipients,
  assumptions: assumptionsPayload(assumptions.value),
  sections: sectionsPayload(sections.value),
});

const { isDirty } = useDirtySnapshot(payload);
useUnsavedGuard(isDirty);

function save() {
  if (form.processing || !canSave.value) return;
  form.transform(payload).patch(`/estimates/${props.estimate.id}`);
}

// Enter in a single-line field (a line title, a rate…) must not save; only
// the footer button submits.
function blockImplicitSubmit(e) {
  if (e.target instanceof HTMLInputElement && e.target.type !== 'checkbox') e.preventDefault();
}

// Errors without an inline slot (scope rows, recipient rows, …).
const INLINE = ['client_id', 'project_id', 'locale', 'title', 'notes', 'recipients'];
const otherErrors = computed(() => Object.entries(form.errors).filter(([k]) => !INLINE.includes(k)).map(([, v]) => v));
</script>

<template>
  <Head :title="`Edit estimate #${estimate.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">Estimates</Link><span>/</span><Link :href="`/estimates/${estimate.number}`">{{ estimate.number }}</Link><span>/</span><span>Edit</span>
      </div>
      <h1 class="page-title">Edit estimate #{{ estimate.number }}</h1>
    </div>
  </div>

  <form class="form-page" novalidate @submit.prevent="save" @keydown.enter="blockImplicitSubmit">
    <div class="doc-grid estimate-doc-grid">
      <div class="estimate-form-main">
        <section class="form-section">
          <h2 class="section-title">Estimate</h2>
          <div class="form-grid cols-3">
            <label class="field span-all">
              <span>Title</span>
              <input v-model="title" class="input" :aria-invalid="!!form.errors.title" placeholder="e.g. Partnerschaft auf Augenhöhe" />
              <small v-if="form.errors.title" class="error">{{ form.errors.title }}</small>
              <small v-else class="hint">Shown at the top of the PDF.</small>
            </label>
            <label class="field">
              <span>Client</span>
              <select v-model="clientId" class="select" :aria-invalid="!!form.errors.client_id">
                <option :value="null" disabled>Select a client…</option>
                <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <small v-if="form.errors.client_id" class="error">{{ form.errors.client_id }}</small>
            </label>
            <label class="field">
              <span>Project (optional)</span>
              <select v-model="projectId" class="select" :disabled="!clientId" :aria-invalid="!!form.errors.project_id">
                <option :value="null">—</option>
                <option v-for="p in clientProjects" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
              <small v-if="form.errors.project_id" class="error">{{ form.errors.project_id }}</small>
            </label>
            <label class="field">
              <span>Language</span>
              <select v-model="locale" class="select" :aria-invalid="!!form.errors.locale">
                <option value="de">German</option>
                <option value="en">English</option>
              </select>
              <small v-if="form.errors.locale" class="error">{{ form.errors.locale }}</small>
              <small v-else class="hint">PDF and mail.</small>
            </label>
          </div>
        </section>

        <section class="form-section">
          <h2 class="section-title">Scope</h2>
          <EstimateScopeEditor v-model="sections" :default-rate="defaultRate" />
        </section>

        <section class="form-section">
          <h2 class="section-title">Assumptions</h2>
          <p class="hint estimate-section-intro">Printed as a bullet list after the totals.</p>
          <AssumptionsEditor v-model="assumptions" />
        </section>

        <section class="form-section">
          <h2 class="section-title">Notes</h2>
          <label class="field">
            <span class="sr-only">Notes</span>
            <textarea v-model="notes" class="textarea" rows="3" :aria-invalid="!!form.errors.notes" placeholder="Optional notes shown on the estimate PDF…"></textarea>
            <small v-if="form.errors.notes" class="error">{{ form.errors.notes }}</small>
          </label>
        </section>

        <section class="form-section">
          <h2 class="section-title">Recipients</h2>
          <RecipientPicker v-model="form.recipients" :contacts="selectedClientContacts" :client-selected="Boolean(clientId)" />
          <small v-if="form.errors.recipients" class="error">{{ form.errors.recipients }}</small>
        </section>
      </div>

      <aside class="summary-card">
        <div class="summary-head">Totals</div>
        <div class="summary-body">
          <TotalsCard :totals="totals" />
          <p class="hint estimate-summary-note">Server recomputes all amounts on save. Only draft estimates can be edited.</p>
          <p v-if="otherErrors.length" class="error estimate-summary-note" role="alert">{{ otherErrors.join(' · ') }}</p>
        </div>
      </aside>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary" :disabled="form.processing || !canSave" :title="saveBlockedReason">Save changes</button>
      <Link :href="`/estimates/${estimate.number}`" class="btn ghost">Cancel</Link>
      <span v-if="otherErrors.length" class="form-foot__aside is-red">Fix the errors above</span>
      <span v-else-if="isDirty" class="form-foot__aside">Unsaved changes</span>
    </div>
  </form>
</template>
