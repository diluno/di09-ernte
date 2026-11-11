<script setup>
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import EstimateScopeEditor from '@/Components/EstimateScopeEditor.vue';
import AssumptionsEditor from '@/Components/AssumptionsEditor.vue';
import { assumptionsPayload, flattenLines, makeLine, makeSection, sectionsPayload } from '@/formatters/estimateScope.js';
import TotalsCard from '@/Components/TotalsCard.vue';
import { useDocumentTotals } from '@/composables/useDocumentTotals.js';
import { useDirtySnapshot, useUnsavedGuard } from '@/composables/useUnsavedGuard.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients:  { type: Array, default: () => [] }, // [{id,name,contacts}]
  projects: { type: Array, default: () => [] }, // { id, name, client_id, rate }
  vat_rates: { type: Array, default: () => [] },
});

const clientId = ref(null);
const projectId = ref(null);
const title = ref('');
const notes = ref('');

// Projects belonging to the selected client.
const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);
const selectedClientContacts = computed(() => props.clients.find((c) => c.id === clientId.value)?.contacts ?? []);

// Reset the project when the client changes.
watch(clientId, () => { projectId.value = null; });

// Reset recipients to the new client's default contacts when the client changes.
watch(selectedClientContacts, (contacts) => {
  form.recipients = contacts.filter((c) => c.is_default).map(({ name, email }) => ({ name, email }));
});

// Editable scope: sections with lines, plus assumptions. Starts as one
// unnamed section with an empty line.
const sections = ref([makeSection(0)]);
const assumptions = ref([]);
const defaultRate = computed(() => selectedProject.value?.rate ?? 0);

// ---- AI drafting -----------------------------------------------------------
// Sends a prose brief to the server, which asks Claude for line items and
// returns them as a proposal. Nothing is saved — the lines land in the form
// above for review and editing, exactly as if they'd been typed by hand.
const brief = ref('');
const drafting = ref(false);
const draftError = ref('');

const canDraft = computed(() => clientId.value && brief.value.trim().length >= 10 && !drafting.value);

// True once the scope holds anything worth keeping (text or hours).
const scopeHasContent = computed(() => flattenLines(sections.value).some((l) =>
  (l.title ?? '').trim() || (l.description ?? '').trim() || Number(l.hours) > 0));

async function draftWithAi() {
  if (!canDraft.value) return;
  if (scopeHasContent.value && !window.confirm('Replace the current scope lines with an AI draft? Your existing lines will be discarded.')) return;
  drafting.value = true;
  draftError.value = '';

  try {
    const { data } = await axios.post('/estimates/draft', {
      brief: brief.value,
      client_id: clientId.value,
      project_id: projectId.value || null,
    });

    // Replace the scope wholesale — the draft is the starting point, not an addition.
    sections.value = [makeSection(defaultRate.value, {
      lines: (data.lines ?? []).map((l) => makeLine(defaultRate.value, {
        description: l.description,
        hours: l.hours,
        rate: l.rate || defaultRate.value,
      })),
    })];
    if (data.title && !title.value) title.value = data.title;
    if (data.notes && !notes.value) notes.value = data.notes;
  } catch (e) {
    draftError.value = e.response?.data?.message || e.message || 'Drafting failed.';
  } finally {
    drafting.value = false;
  }
}

const lines = computed(() => flattenLines(sections.value));
const totals = useDocumentTotals(lines, { catalog: () => props.vat_rates });

const canSave = computed(() => clientId.value && lines.value.length > 0);
const saveBlockedReason = computed(() => {
  if (!clientId.value) return 'Select a client first';
  if (!lines.value.length) return 'Add at least one line';
  return undefined;
});

const form = useForm({ recipients: [] });
const payload = () => ({
  client_id: clientId.value,
  project_id: projectId.value || null,
  title: title.value || null,
  notes: notes.value || null,
  recipients: form.recipients,
  assumptions: assumptionsPayload(assumptions.value),
  sections: sectionsPayload(sections.value),
});

// The brief counts as unsaved work too.
const { isDirty } = useDirtySnapshot(() => ({ ...payload(), brief: brief.value }));
useUnsavedGuard(isDirty);

function save() {
  if (form.processing || !canSave.value) return;
  form.transform(payload).post('/estimates');
}

// Enter in a single-line field (a line title, a rate…) must not create the
// draft; only the footer button submits.
function blockImplicitSubmit(e) {
  if (e.target instanceof HTMLInputElement && e.target.type !== 'checkbox') e.preventDefault();
}

// Errors without an inline slot (scope rows, recipient rows, …).
const INLINE = ['client_id', 'project_id', 'title', 'notes', 'recipients'];
const otherErrors = computed(() => Object.entries(form.errors).filter(([k]) => !INLINE.includes(k)).map(([, v]) => v));
</script>

<template>
  <Head title="New estimate" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/estimates">Estimates</Link><span>/</span><span>New</span></div>
      <h1 class="page-title">New estimate</h1>
    </div>
  </div>

  <form class="form-page" novalidate @submit.prevent="save" @keydown.enter="blockImplicitSubmit">
    <div class="doc-grid estimate-doc-grid">
      <div class="estimate-form-main">
        <section class="form-section">
          <h2 class="section-title">Estimate</h2>
          <div class="form-grid">
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
              <small v-else class="hint">Sets the default hourly rate for new lines.</small>
            </label>
          </div>
        </section>

        <section class="form-section">
          <h2 class="section-title">Draft with AI</h2>
          <div class="estimate-ai-draft">
            <AutoTextarea
              v-model="brief"
              class="textarea"
              aria-label="Brief for the AI draft"
              :disabled="drafting"
              placeholder="Describe the job — e.g. “Neue Website für den Hofladen: 6 Seiten, Bildstrecke, Bestellformular, Umzug auf unser Hosting.” The scope below gets replaced with a draft you can edit."
            />
            <div class="estimate-ai-draft__foot">
              <span class="hint">
                {{ clientId ? 'Uses this client’s past estimates as a style reference.' : 'Select a client first.' }}
              </span>
              <button type="button" class="btn sm" :disabled="!canDraft" :title="canDraft ? undefined : (clientId ? 'Write a brief of at least 10 characters' : 'Select a client first')" @click="draftWithAi">
                {{ drafting ? 'Drafting…' : 'Draft lines' }}
              </button>
            </div>
            <p v-if="draftError" class="error" role="alert">{{ draftError }}</p>
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
          <p class="hint estimate-summary-note">
            Server recomputes all amounts on save. The estimate is created as a draft; send it to stamp the validity date and email the client.
          </p>
          <p v-if="otherErrors.length" class="error estimate-summary-note" role="alert">{{ otherErrors.join(' · ') }}</p>
        </div>
      </aside>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary" :disabled="form.processing || !canSave" :title="saveBlockedReason">Create draft</button>
      <Link href="/estimates" class="btn ghost">Cancel</Link>
      <span v-if="otherErrors.length" class="form-foot__aside is-red">Fix the errors above</span>
      <span v-else-if="isDirty" class="form-foot__aside">Unsaved changes</span>
    </div>
  </form>
</template>
