<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LineItemsEditor from '@/Components/LineItemsEditor.vue';
import TotalsCard from '@/Components/TotalsCard.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import { makeLineItem, lineItemsPayload } from '@/formatters/lineItems.js';
import { useDocumentTotals } from '@/composables/useDocumentTotals.js';
import { useDirtySnapshot, useUnsavedGuard } from '@/composables/useUnsavedGuard.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoice: { type: Object, required: true },
  client_contacts: { type: Array, default: () => [] },
});

const selectedClientContacts = computed(() => props.client_contacts);

const title = ref(props.invoice.title ?? '');
const notes = ref(props.invoice.notes ?? '');
const periodStart = ref(props.invoice.period_start ?? '');
const periodEnd = ref(props.invoice.period_end ?? '');

const lines = ref(props.invoice.lines.map((line) => makeLineItem({
  description: line.description,
  hours: line.hours,
  rate: Number(line.rate_rappen ?? 0) / 100,
  vat_exempt: Boolean(line.vat_exempt),
})));
if (lines.value.length === 0) lines.value.push(makeLineItem());

// A new line starts at the previous line's rate.
const newLine = (list) => ({ rate: list.length ? list[list.length - 1].rate : 0 });

// The invoice carries its own VAT rate snapshot.
const totals = useDocumentTotals(lines, { rate: () => props.invoice.vat_rate });
const canSave = computed(() => lines.value.length > 0);

// Language of the PDF, the mail and the QR bill.
const locale = ref(props.invoice.locale ?? 'de');

const form = useForm({
  recipients: props.invoice.recipients ?? [],
});

const payload = () => ({
  title: title.value || null,
  locale: locale.value,
  notes: notes.value || null,
  period_start: periodStart.value || null,
  period_end: periodEnd.value || null,
  recipients: form.recipients,
  lines: lineItemsPayload(lines.value),
});

const { isDirty } = useDirtySnapshot(payload);
useUnsavedGuard(isDirty);

function save() {
  if (form.processing || !canSave.value) return;
  form.transform(payload).patch(`/invoices/${props.invoice.id}`);
}

// Errors without an inline slot (line rows, recipient rows, …).
const INLINE = ['title', 'locale', 'notes', 'period_start', 'period_end', 'recipients'];
const otherErrors = computed(() => Object.entries(form.errors).filter(([k]) => !INLINE.includes(k)).map(([, v]) => v));
</script>

<template>
  <Head :title="`Edit invoice #${invoice.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">Invoices</Link><span>/</span><Link :href="`/invoices/${invoice.number}`">{{ invoice.number }}</Link><span>/</span><span>Edit</span>
      </div>
      <h1 class="page-title">
        <span>Edit invoice</span>
        <span class="meta">○ draft · {{ invoice.client.name }}</span>
      </h1>
    </div>
  </div>

  <!-- Not a <form>: Enter in a line field must not save. -->
  <div class="form-page">
    <div class="doc-grid">
      <div class="doc-main">
        <section class="form-section">
          <h2 class="section-title">Document</h2>
          <div class="form-grid cols-3">
            <label class="field span-all">
              <span>Title</span>
              <input v-model="title" class="input" placeholder="Title shown on the invoice PDF" :aria-invalid="!!form.errors.title" />
              <small v-if="form.errors.title" class="error">{{ form.errors.title }}</small>
            </label>
            <label class="field">
              <span>Period start</span>
              <input v-model="periodStart" type="date" class="input" :aria-invalid="!!form.errors.period_start" />
              <small v-if="form.errors.period_start" class="error">{{ form.errors.period_start }}</small>
            </label>
            <label class="field">
              <span>Period end</span>
              <input v-model="periodEnd" type="date" class="input" :aria-invalid="!!form.errors.period_end" />
              <small v-if="form.errors.period_end" class="error">{{ form.errors.period_end }}</small>
            </label>
            <label class="field">
              <span>Language</span>
              <select v-model="locale" class="select" :aria-invalid="!!form.errors.locale">
                <option value="de">German</option>
                <option value="en">English</option>
              </select>
              <small v-if="form.errors.locale" class="error">{{ form.errors.locale }}</small>
            </label>
          </div>
        </section>

        <section class="form-section">
          <h2 class="section-title">Lines</h2>
          <LineItemsEditor v-model="lines" show-vat :new-line="newLine" />
        </section>

        <section class="form-section">
          <h2 class="section-title">Notes</h2>
          <textarea v-model="notes" class="textarea" rows="4" aria-label="Notes" placeholder="Optional notes shown on the invoice PDF" :aria-invalid="!!form.errors.notes"></textarea>
          <small v-if="form.errors.notes" class="error">{{ form.errors.notes }}</small>
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
          <TotalsCard :totals="totals" :rate="invoice.vat_rate" />
          <dl class="kv summary-kv">
            <dt>Client</dt><dd>{{ invoice.client.name }}</dd>
            <template v-if="invoice.project_name"><dt>Project</dt><dd>{{ invoice.project_name }}</dd></template>
          </dl>
          <p class="hint summary-note">The server recomputes all amounts on save. Only draft invoices can be edited.</p>
        </div>
      </aside>
    </div>

    <div class="form-foot">
      <button type="button" class="btn primary" :disabled="form.processing || !canSave" :title="canSave ? undefined : 'Add at least one line'" @click="save">
        {{ form.processing ? 'Saving…' : 'Save changes' }}
      </button>
      <Link :href="`/invoices/${invoice.number}`" class="btn ghost">Cancel</Link>
      <span v-if="otherErrors.length" class="form-foot__aside is-red" role="alert">{{ otherErrors.join(' · ') }}</span>
      <span v-else-if="isDirty" class="form-foot__aside">Unsaved changes</span>
    </div>
  </div>
</template>
