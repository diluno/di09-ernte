<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import LineItemsEditor from '@/Components/LineItemsEditor.vue';
import TotalsCard from '@/Components/TotalsCard.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import ClientPicker from '@/Components/ClientPicker.vue';
import { makeLineItem, lineItemsPayload } from '@/formatters/lineItems.js';
import { todayIso } from '@/formatters/date.js';
import { nextRunOnOrAfter, periodEnd } from '@/formatters/billingPeriod.js';
import { useDocumentTotals } from '@/composables/useDocumentTotals.js';
import { useDirtySnapshot, useUnsavedGuard } from '@/composables/useUnsavedGuard.js';

// The create and edit pages for a recurring schedule share this form; with a
// `schedule` it edits (PATCH), without one it creates (POST).
const props = defineProps({
  schedule: { type: Object, default: null }, // includes recipients + lines [{description,hours,rate}]
  clients: { type: Array, default: () => [] }, // [{id,name,contacts}]
  projects: { type: Array, default: () => [] },
  vat_rates: { type: Array, default: () => [] },
});

const editing = computed(() => props.schedule !== null);

const clientId = ref(props.schedule?.client_id ?? null);
const projectId = ref(props.schedule?.project_id ?? null);
const title = ref(props.schedule?.title ?? '');
const notes = ref(props.schedule?.notes ?? '');
const cadence = ref(props.schedule?.cadence ?? 'monthly');
const nextRunOn = ref(props.schedule?.next_run_on ?? todayIso());
const autoSend = ref(Boolean(props.schedule?.auto_send));

const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);
const selectedClientContacts = computed(() => props.clients.find((c) => c.id === clientId.value)?.contacts ?? []);

const form = useForm({ recipients: props.schedule?.recipients ?? [] });

// Changing the client clears the project and resets recipients to that client's defaults.
watch(clientId, () => {
  projectId.value = null;
  form.recipients = selectedClientContacts.value
    .filter((c) => c.is_default)
    .map(({ name, email }) => ({ name, email }));
});

const lines = ref(
  props.schedule
    ? props.schedule.lines.map((l) => makeLineItem({ description: l.description, hours: l.hours, rate: l.rate }))
    : [makeLineItem({ hours: 1 })],
);
const newLine = () => ({ hours: 1, rate: selectedProject.value?.rate ?? 0 });

// The generator applies the VAT rate in force at the END of the period the next run
// bills, so the preview looks the rate up there. Mirrors RecurringInvoiceSchedules:
// a past date snaps forward, and the anchor day only changes when the date moves.
const nextPeriodEnd = computed(() => {
  if (!nextRunOn.value) return null;
  const dateKept = editing.value && nextRunOn.value === props.schedule.next_run_on;
  if (dateKept && cadence.value === props.schedule.cadence && props.schedule.next_period_end) {
    return props.schedule.next_period_end;
  }
  const anchor = dateKept && props.schedule.anchor_day ? props.schedule.anchor_day : Number(nextRunOn.value.slice(8, 10));
  const run = nextRunOnOrAfter(cadence.value, nextRunOn.value, todayIso(), anchor);
  return run ? periodEnd(cadence.value, run, anchor) : nextRunOn.value;
});

const totals = useDocumentTotals(lines, { catalog: () => props.vat_rates, date: nextPeriodEnd });

const canSave = computed(() => clientId.value && lines.value.length > 0 && nextRunOn.value);

const payload = () => ({
  client_id: clientId.value,
  project_id: projectId.value || null,
  title: title.value || null,
  notes: notes.value || null,
  cadence: cadence.value,
  next_run_on: nextRunOn.value,
  auto_send: autoSend.value,
  recipients: form.recipients,
  lines: lineItemsPayload(lines.value, { vat: false }),
});

const { isDirty } = useDirtySnapshot(payload);
useUnsavedGuard(isDirty);

function save() {
  if (form.processing || !canSave.value) return;
  form.transform(payload);
  if (editing.value) form.patch(`/recurring-invoices/${props.schedule.id}`);
  else form.post('/recurring-invoices');
}

// The resolved title of the next invoice is only known server-side (the period
// label follows the client's language); show it while nothing that affects it changed.
const nextTitle = computed(() => {
  if (!editing.value || !props.schedule.next_title) return null;
  const s = props.schedule;
  const unchanged = title.value === (s.title ?? '') && cadence.value === s.cadence
    && nextRunOn.value === s.next_run_on && clientId.value === s.client_id;
  return unchanged ? s.next_title : null;
});
const scheduleClientName = computed(() => props.clients.find((c) => c.id === props.schedule?.client_id)?.name ?? null);

// Errors without an inline slot (line rows, recipients rows, …).
const INLINE = ['client_id', 'project_id', 'title', 'notes', 'cadence', 'next_run_on', 'recipients', 'auto_send'];
const otherErrors = computed(() => Object.entries(form.errors).filter(([k]) => !INLINE.includes(k)).map(([, v]) => v));
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/recurring-invoices">Recurring</Link><span>/</span>
        <template v-if="editing && scheduleClientName"><span>{{ scheduleClientName }}</span><span>/</span></template>
        <span>{{ editing ? 'Edit' : 'New' }}</span>
      </div>
      <h1 class="page-title">
        <span>{{ editing ? 'Edit schedule' : 'New schedule' }}</span>
        <span v-if="editing && schedule.paused" class="meta">‖ paused</span>
      </h1>
    </div>
  </div>

  <!-- Not a <form>: Enter in a line field must not save. -->
  <div class="form-page">
    <div class="doc-grid">
      <div class="doc-main">
        <section class="form-section">
          <h2 class="section-title">Schedule</h2>
          <div class="form-grid">
            <label class="field span-all">
              <span>Invoice title</span>
              <input v-model="title" class="input" placeholder="e.g. Hosting — {period}" :aria-invalid="!!form.errors.title" />
              <small v-if="form.errors.title" class="error">{{ form.errors.title }}</small>
              <small v-else-if="nextTitle" class="hint">Next invoice: <span class="ink">{{ nextTitle }}</span></small>
              <small v-else class="hint"><code>{period}</code> becomes the billing period in the client’s language, e.g. “June 2026” or “July 2026 – September 2026”.</small>
            </label>
            <div class="field">
              <span id="recurring-client-label">Client</span>
              <ClientPicker v-model="clientId" :options="clients" labelledby="recurring-client-label" placeholder="Type a client name…" :invalid="!!form.errors.client_id" />
              <small v-if="form.errors.client_id" class="error">{{ form.errors.client_id }}</small>
            </div>
            <label class="field">
              <span>Project <span class="field-optional">optional</span></span>
              <select v-model="projectId" class="select" :disabled="!clientId" :aria-invalid="!!form.errors.project_id">
                <option :value="null">—</option>
                <option v-for="p in clientProjects" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
              <small v-if="form.errors.project_id" class="error">{{ form.errors.project_id }}</small>
            </label>
            <label class="field">
              <span>Cadence</span>
              <select v-model="cadence" class="select" :aria-invalid="!!form.errors.cadence">
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="half-yearly">Half-yearly</option>
                <option value="yearly">Yearly</option>
              </select>
              <small v-if="form.errors.cadence" class="error">{{ form.errors.cadence }}</small>
            </label>
            <label class="field">
              <span>{{ editing ? 'Next run on' : 'First run on' }}</span>
              <input v-model="nextRunOn" type="date" class="input" :aria-invalid="!!form.errors.next_run_on" />
              <small v-if="form.errors.next_run_on" class="error">{{ form.errors.next_run_on }}</small>
              <small v-else-if="!editing" class="hint">A past date moves forward to the next cycle; nothing is billed retroactively.</small>
            </label>
            <label class="field is-check span-all">
              <input type="checkbox" v-model="autoSend" />
              <span>Send automatically: email each invoice to the recipients when it is generated. Off, it waits as a draft for review.</span>
            </label>
          </div>
        </section>

        <section class="form-section">
          <h2 class="section-title">Lines</h2>
          <LineItemsEditor v-model="lines" :new-line="newLine" />
        </section>

        <section class="form-section">
          <h2 class="section-title">Notes</h2>
          <textarea v-model="notes" class="textarea" rows="3" aria-label="Notes" placeholder="Optional notes copied to every generated invoice" :aria-invalid="!!form.errors.notes"></textarea>
          <small v-if="form.errors.notes" class="error">{{ form.errors.notes }}</small>
        </section>

        <section class="form-section">
          <h2 class="section-title">Recipients</h2>
          <RecipientPicker :contacts="selectedClientContacts" v-model="form.recipients" :client-selected="Boolean(clientId)" />
          <small v-if="form.errors.recipients" class="error">{{ form.errors.recipients }}</small>
        </section>
      </div>

      <aside class="summary-card">
        <div class="summary-head">Per invoice</div>
        <div class="summary-body">
          <TotalsCard :totals="totals" />
          <p class="hint summary-note">Each cycle generates an invoice with these lines. The server recomputes amounts.</p>
        </div>
      </aside>
    </div>

    <div class="form-foot">
      <button type="button" class="btn primary" :disabled="form.processing || !canSave" :title="canSave ? undefined : 'Choose a client, a run date and at least one line'" @click="save">
        {{ form.processing ? 'Saving…' : (editing ? 'Save changes' : 'Create schedule') }}
      </button>
      <Link href="/recurring-invoices" class="btn ghost">Cancel</Link>
      <span v-if="otherErrors.length" class="form-foot__aside is-red" role="alert">{{ otherErrors.join(' · ') }}</span>
      <span v-else-if="isDirty" class="form-foot__aside">Unsaved changes</span>
    </div>
  </div>
</template>
