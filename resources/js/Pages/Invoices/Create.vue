<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LineItemsEditor from '@/Components/LineItemsEditor.vue';
import TotalsCard from '@/Components/TotalsCard.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import ClientPicker from '@/Components/ClientPicker.vue';
import { makeLineItem, lineItemsPayload } from '@/formatters/lineItems.js';
import { fmtDate } from '@/formatters/date.js';
import { fmtHours } from '@/formatters/duration.js';
import { useDocumentTotals } from '@/composables/useDocumentTotals.js';
import { useDirtySnapshot, useUnsavedGuard } from '@/composables/useUnsavedGuard.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, default: null },
  project: { type: Object, default: null },
  period: { type: Object, default: null },
  entries: { type: Array, default: () => [] },
  suggested_lines: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] }, // populated only in picker mode (no client yet)
  vat_rates: { type: Array, default: () => [] },
  // Billable, unbilled entries of this client/project that fall outside the
  // period — { count, first_on, last_on } (null in client-picker mode).
  unbilled_outside_period: { type: Object, default: null },
});

// The chosen client's contacts (client entries carry a `contacts` key in picker mode,
// or the loaded client itself carries it once selected).
const selectedClientContacts = computed(() => props.client?.contacts ?? []);

// Picker mode: no client chosen yet — let the user pick one, then reload with ?client=.
const picked = ref(null);
const choosing = ref(false);
function chooseClient() {
  if (!picked.value || choosing.value) return;
  router.get('/invoices/new', { client: picked.value }, {
    onStart: () => { choosing.value = true; },
    onFinish: () => { choosing.value = false; },
  });
}

// Entry checklist: all selected by default.
const selected = reactive(Object.fromEntries(props.entries.map((e) => [e.id, true])));
const selectedIds = computed(() => props.entries.filter((e) => selected[e.id]).map((e) => e.id));

// Server-suggested grouping of the entries into lines. Each suggestion gets a
// stable key (description + rate) so a line derived from it can be recognised
// again after the period — and with it the suggestions — changes.
function keyedSuggestions(list) {
  const seen = {};
  return list.map((line) => {
    const base = `${line.description}|${line.rate}`;
    seen[base] = (seen[base] ?? 0) + 1;
    return { ...line, suggestionKey: `${base}|${seen[base]}` };
  });
}
const suggestions = computed(() => keyedSuggestions(props.suggested_lines));

function hoursForEntries(entryIds) {
  const seconds = props.entries
    .filter((entry) => entryIds.includes(entry.id))
    .reduce((sum, entry) => sum + Number(entry.duration_seconds || 0), 0);

  return Math.round((seconds / 3600) * 100) / 100;
}

function lineFromSuggestion(suggestion, entryIds = suggestion.entry_ids) {
  const full = entryIds.length === suggestion.entry_ids.length;
  return makeLineItem({
    suggestionKey: suggestion.suggestionKey,
    description: suggestion.description,
    hours: full ? suggestion.hours : hoursForEntries(entryIds),
    rate: suggestion.rate,
    entry_ids: [...entryIds],
  });
}

// Editable lines, seeded from the suggestions. Lines with a `suggestionKey` are
// entry-derived; lines without one were added by hand.
const lines = ref(suggestions.value.map((s) => lineFromSuggestion(s)));

const newLine = () => ({
  rate: props.project?.rate_rappen ? props.project.rate_rappen / 100 : 0,
  entry_ids: [],
});

// Removing an entry-derived line unchecks its entries.
function onLineRemoved(line) {
  (line.entry_ids ?? []).forEach((entryId) => { selected[entryId] = false; });
}

function reconcileEntry(entryId) {
  const line = lines.value.find((item) => (item.entry_ids ?? []).includes(entryId));

  if (!selected[entryId]) {
    if (!line) return;
    line.entry_ids = line.entry_ids.filter((id) => id !== entryId);
    if (line.entry_ids.length === 0) lines.value = lines.value.filter((item) => item.key !== line.key);
    else line.hours = hoursForEntries(line.entry_ids);
    return;
  }

  if (line) return;
  const suggestion = suggestions.value.find((item) => item.entry_ids.includes(entryId));
  if (!suggestion) return;
  const existing = lines.value.find((item) => item.suggestionKey === suggestion.suggestionKey);
  if (existing) {
    existing.entry_ids.push(entryId);
    existing.hours = hoursForEntries(existing.entry_ids);
    return;
  }

  lines.value.push(lineFromSuggestion(suggestion, [entryId]));
}

const form = useForm({
  recipients: selectedClientContacts.value.filter((c) => c.is_default).map(({ name, email }) => ({ name, email })),
});
const title = ref('');

// Period: re-query the eligible entries with a partial reload. The user's title,
// recipients, hand-added lines and edits to suggested lines are kept; only the
// entry list and the entry-derived lines are refreshed.
const from = ref(props.period?.start ?? '');
const to = ref(props.period?.end ?? '');
const reloading = ref(false);

function mergeReloadedEntries() {
  // Checklist: keep the state of entries still in the period, select new ones.
  const present = new Set(props.entries.map((e) => e.id));
  Object.keys(selected).forEach((id) => { if (!present.has(Number(id))) delete selected[id]; });
  props.entries.forEach((e) => { if (!(e.id in selected)) selected[e.id] = true; });

  const bySuggestion = new Map(suggestions.value.map((s) => [s.suggestionKey, s]));
  const used = new Set();
  const merged = [];

  for (const line of lines.value) {
    if (line.suggestionKey === undefined) { merged.push(line); continue; } // hand-added: untouched
    const suggestion = bySuggestion.get(line.suggestionKey);
    if (!suggestion) continue; // its entries left the period
    used.add(line.suggestionKey);

    const entryIds = suggestion.entry_ids.filter((id) => selected[id]);
    if (entryIds.length === 0) continue;
    const sameEntries = entryIds.length === line.entry_ids.length && entryIds.every((id) => line.entry_ids.includes(id));
    // Same entries: keep the line as edited. Otherwise keep description/rate/VAT edits, refresh the hours.
    merged.push(sameEntries ? line : {
      ...line,
      entry_ids: entryIds,
      hours: entryIds.length === suggestion.entry_ids.length ? suggestion.hours : hoursForEntries(entryIds),
    });
  }

  for (const suggestion of suggestions.value) {
    if (used.has(suggestion.suggestionKey)) continue;
    const entryIds = suggestion.entry_ids.filter((id) => selected[id]);
    if (entryIds.length) merged.push(lineFromSuggestion(suggestion, entryIds));
  }

  lines.value = merged;
}

function reloadPeriod() {
  const wasDirty = isDirty.value;
  router.get('/invoices/new', {
    client: props.client.id,
    project: props.project?.id || undefined,
    from: from.value,
    to: to.value,
  }, {
    only: ['entries', 'suggested_lines', 'period', 'unbilled_outside_period'],
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onStart: () => { reloading.value = true; },
    onSuccess: () => {
      from.value = props.period?.start ?? from.value;
      to.value = props.period?.end ?? to.value;
      mergeReloadedEntries();
      if (!wasDirty) markClean();
    },
    onFinish: () => { reloading.value = false; },
  });
}

const totals = useDocumentTotals(lines, { catalog: () => props.vat_rates, date: to });

// What the user has actually entered (drives the unsaved-changes prompt).
const { isDirty, markClean } = useDirtySnapshot(() => ({
  title: title.value,
  recipients: form.recipients,
  lines: lineItemsPayload(lines.value),
  deselected: props.entries.filter((e) => !selected[e.id]).map((e) => e.id),
}));
useUnsavedGuard(() => Boolean(props.client) && isDirty.value);

function save() {
  if (form.processing || lines.value.length === 0) return;
  form.transform(() => ({
    client_id: props.client.id,
    title: title.value || null,
    project_id: props.project?.id ?? null,
    period_start: from.value,
    period_end: to.value,
    entry_ids: selectedIds.value,
    recipients: form.recipients,
    lines: lineItemsPayload(lines.value),
  })).post('/invoices');
}

const entryCountLabel = computed(() => {
  const n = selectedIds.value.length;
  return `${n} ${n === 1 ? 'entry' : 'entries'}`;
});

// Errors without an inline slot (line rows, recipient rows, …).
const INLINE = ['title', 'period_start', 'period_end', 'recipients'];
const otherErrors = computed(() => Object.entries(form.errors).filter(([k]) => !INLINE.includes(k)).map(([, v]) => v));
</script>

<template>
  <Head :title="client ? `New invoice for ${client.name}` : 'New invoice'" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">Invoices</Link><span>/</span><span>New</span>
        <template v-if="client"><span>·</span><span>{{ client.name }}</span></template>
      </div>
      <h1 class="page-title">
        <span>New invoice</span>
        <span v-if="client" class="meta">{{ client.name }}<template v-if="project"> · {{ project.name }}</template></span>
      </h1>
    </div>
    <div v-if="client" class="page-actions">
      <Link href="/invoices/new" class="btn">Change client</Link>
    </div>
  </div>

  <!-- Step 1: choose a client, then reload with ?client=. -->
  <form v-if="!client" class="form-page" @submit.prevent="chooseClient">
    <div class="form-body">
      <section class="form-section">
        <h2 class="section-title">Client</h2>
        <p class="hint section-intro">
          Pick the client to invoice. The next step lists their billable, unbilled time for a period.
          To start from a specific project, use <strong>+ Invoice</strong> on the project or client instead.
        </p>
        <div class="form-grid">
          <div class="field">
            <span id="invoice-client-label">Client</span>
            <ClientPicker v-model="picked" :options="clients" labelledby="invoice-client-label" placeholder="Type a client name…" :disabled="clients.length === 0" />
            <small v-if="clients.length === 0" class="hint">No active clients. <Link href="/clients/create" class="link-ink">Create one first.</Link></small>
            <small v-else class="hint">{{ clients.length }} active clients. ↑/↓ to browse, Enter to pick.</small>
          </div>
        </div>
      </section>
    </div>
    <div class="form-foot">
      <button type="submit" class="btn primary" :disabled="!picked || choosing" :title="picked ? undefined : 'Choose a client first'">Continue</button>
      <Link href="/invoices" class="btn ghost">Cancel</Link>
    </div>
  </form>

  <!-- Step 2: the editor. Not a <form>: Enter in a line field must not create the draft. -->
  <div v-else class="form-page">
    <div class="doc-grid">
      <div class="doc-main">
        <section class="form-section">
          <h2 class="section-title">Document</h2>
          <div class="form-grid">
            <label class="field span-all">
              <span>Title</span>
              <input v-model="title" class="input" placeholder="Title shown on the invoice PDF" :aria-invalid="!!form.errors.title" />
              <small v-if="form.errors.title" class="error">{{ form.errors.title }}</small>
            </label>
            <label class="field">
              <span>Period from</span>
              <input v-model="from" type="date" class="input" :aria-invalid="!!form.errors.period_start" @change="reloadPeriod" />
              <small v-if="form.errors.period_start" class="error">{{ form.errors.period_start }}</small>
            </label>
            <label class="field">
              <span>Period to</span>
              <input v-model="to" type="date" class="input" :aria-invalid="!!form.errors.period_end" @change="reloadPeriod" />
              <small v-if="form.errors.period_end" class="error">{{ form.errors.period_end }}</small>
            </label>
            <p class="hint span-all">Changing the period reloads the entries below. Your title, recipients, manual lines and edits to suggested lines are kept.</p>
          </div>
        </section>

        <section class="form-section">
          <h2 class="section-title">Lines</h2>
          <LineItemsEditor
            v-model="lines" show-vat :new-line="newLine"
            empty-text="No lines. Add one or widen the period."
            @remove="onLineRemoved"
          />
        </section>

        <section class="form-section">
          <h2 class="section-title">
            Entries in period <span class="section-title__aside">{{ selectedIds.length }} of {{ entries.length }} selected</span>
          </h2>
          <p class="hint section-intro">Unchecking an entry removes its hours from the suggested line; removing a suggested line unchecks its entries.</p>
          <div class="picker-wrap">
            <table class="table table--picker" :aria-busy="reloading">
              <thead><tr><th class="check"><span class="sr-only">Include</span></th><th>Entry</th><th style="width: 96px">Project</th><th class="num" style="width: 72px">Hours</th></tr></thead>
              <tbody>
                <tr v-for="e in entries" :key="e.id">
                  <td class="check"><input type="checkbox" v-model="selected[e.id]" :aria-label="`Include entry: ${e.description}`" @change="reconcileEntry(e.id)" /></td>
                  <td>{{ e.description }}</td>
                  <td class="dim">{{ e.project.code }}</td>
                  <td class="num">{{ fmtHours(e.hours) }}</td>
                </tr>
                <tr v-if="entries.length === 0">
                  <td colspan="4" class="is-empty">
                    No billable, unbilled entries in this period.
                    <template v-if="unbilled_outside_period?.count">
                      {{ unbilled_outside_period.count }} unbilled {{ unbilled_outside_period.count === 1 ? 'entry exists' : 'entries exist' }} outside it<template v-if="unbilled_outside_period.first_on">
                        ({{ fmtDate(unbilled_outside_period.first_on) }} – {{ fmtDate(unbilled_outside_period.last_on ?? unbilled_outside_period.first_on) }})</template> — widen the period to include them.
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="form-section">
          <h2 class="section-title">Recipients</h2>
          <RecipientPicker :contacts="selectedClientContacts" v-model="form.recipients" />
          <small v-if="form.errors.recipients" class="error">{{ form.errors.recipients }}</small>
        </section>
      </div>

      <aside class="summary-card">
        <div class="summary-head">Totals</div>
        <div class="summary-body">
          <TotalsCard :totals="totals" />
          <p class="hint summary-note">
            {{ entryCountLabel }} will be attached to this invoice and removed from unbilled time.
            The server recomputes all amounts on save.
          </p>
        </div>
      </aside>
    </div>

    <div class="form-foot">
      <button type="button" class="btn primary" :disabled="form.processing || lines.length === 0" :title="lines.length === 0 ? 'Add at least one line' : undefined" @click="save">
        {{ form.processing ? 'Creating…' : 'Create draft' }}
      </button>
      <Link href="/invoices" class="btn ghost">Cancel</Link>
      <span v-if="otherErrors.length" class="form-foot__aside is-red" role="alert">{{ otherErrors.join(' · ') }}</span>
      <span v-else-if="isDirty" class="form-foot__aside">Unsaved changes</span>
    </div>
  </div>
</template>
