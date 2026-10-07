<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import VendorMark from '@/Components/VendorMark.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  receipt: { type: Object, required: true },
});

const now = new Date();
const form = useForm({
  vendor: props.receipt.vendor ?? '',
  vendor_domain: props.receipt.vendor_domain ?? '',
  document_date: props.receipt.document_date ?? '',
  total: props.receipt.total !== null ? props.receipt.total.toFixed(2) : '',
  currency: props.receipt.currency ?? '',
  invoice_number: props.receipt.invoice_number ?? '',
  payment_method: props.receipt.payment_method ?? 'unknown',
  note: props.receipt.note ?? '',
  target_year: props.receipt.target_year ?? now.getFullYear(),
  target_month: props.receipt.target_month ?? now.getMonth() + 1,
  filename: props.receipt.filename ?? '',
});

const isFiled = computed(() => props.receipt.filing_status === 'filed');
const isNumbered = computed(() => props.receipt.number_prefix !== null);
const reading = computed(() => props.receipt.extraction_status === 'pending');
const filing = computed(() => props.receipt.filing_status === 'pending' || props.receipt.filing_status === 'inbox');

function save() { form.patch(`/receipts/${props.receipt.id}`, { preserveScroll: true }); }
function reread() { router.post(`/receipts/${props.receipt.id}/extract`, {}, { preserveScroll: true }); }
function refile() { router.post(`/receipts/${props.receipt.id}/file`, {}, { preserveScroll: true }); }
function remove() {
  const text = props.receipt.dropbox_path
    ? 'Remove this receipt from ernte? The file stays in Dropbox.'
    : 'Remove this receipt? The uploaded file is deleted.';
  if (window.confirm(text)) router.delete(`/receipts/${props.receipt.id}`);
}

// Background jobs are still working on it: reload until they are done, then show what was read.
let timer = null;
watch(() => reading.value || filing.value, (on) => {
  clearInterval(timer);
  if (on) timer = setInterval(() => router.reload({ only: ['receipt'] }), 3000);
}, { immediate: true });
watch(() => props.receipt.extraction_status, (status, before) => {
  if (before === 'pending' && status !== 'pending' && !form.isDirty) router.visit(`/receipts/${props.receipt.id}`, { preserveScroll: true });
});
onBeforeUnmount(() => clearInterval(timer));

const MONTHS = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];
</script>

<template>
  <Head :title="receipt.vendor ?? receipt.original_name" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/receipts">Receipts</Link><span>/</span><span>{{ receipt.filename ?? receipt.original_name }}</span></div>
      <h1 class="page-title">
        <VendorMark v-if="receipt.logo_url" :name="receipt.vendor" :logo="receipt.logo_url" :size="44" style="align-self: center" />
        <span>{{ receipt.vendor ?? receipt.original_name }}</span>
        <span class="meta" :class="{ 'is-red': receipt.needs_attention }">
          <template v-if="reading">reading…</template>
          <template v-else-if="receipt.filing_status === 'inbox'">in the Dropbox inbox</template>
          <template v-else-if="filing">not filed yet</template>
          <template v-else-if="isFiled">filed<template v-if="isNumbered"> · no. {{ receipt.number_prefix }}</template></template>
          <template v-else>{{ receipt.filing_status }}</template>
        </span>
      </h1>
    </div>
    <div class="page-actions">
      <button class="btn" :disabled="reading" @click="reread">Read again</button>
      <button v-if="!isFiled && !receipt.is_duplicate" class="btn" :disabled="filing && !receipt.filing_error" @click="refile">File now</button>
      <button class="btn primary" :disabled="form.processing" @click="save">{{ form.processing ? 'Saving…' : 'Save' }}</button>
    </div>
  </div>

  <div class="receipt-page">
    <div class="receipt-doc">
      <iframe v-if="receipt.previewable" :src="`${receipt.file_url}#toolbar=0&navpanes=0&view=FitH`" title="Receipt document" class="receipt-sheet"></iframe>
      <div v-else class="receipt-nodoc">The photo is being converted. The preview appears in a moment.</div>
    </div>

    <form class="receipt-side" @submit.prevent="save">
      <p v-if="receipt.extraction_error" class="receipt-alert">Could not be read: {{ receipt.extraction_error }}</p>
      <p v-if="receipt.filing_error" class="receipt-alert">{{ receipt.filing_error }}</p>
      <p v-if="receipt.confidence === 'low' && !receipt.extraction_error" class="receipt-alert">Read with low confidence. Check the fields against the document.</p>

      <section>
        <h3 class="section-title">Read from the document</h3>
        <div class="form-grid">
          <label class="field span-2">
            <span>Vendor</span>
            <input v-model="form.vendor" class="input" />
            <small v-if="form.errors.vendor" class="error">{{ form.errors.vendor }}</small>
          </label>
          <label class="field span-2">
            <span>Website (for the logo)</span>
            <input v-model="form.vendor_domain" class="input" placeholder="vendor.com" autocapitalize="off" spellcheck="false" />
            <small v-if="form.errors.vendor_domain" class="error">{{ form.errors.vendor_domain }}</small>
          </label>
          <label class="field">
            <span>Date</span>
            <input v-model="form.document_date" class="input" type="date" />
            <small v-if="form.errors.document_date" class="error">{{ form.errors.document_date }}</small>
          </label>
          <label class="field">
            <span>Invoice no.</span>
            <input v-model="form.invoice_number" class="input" />
          </label>
          <label class="field">
            <span>Total</span>
            <input v-model="form.total" class="input" inputmode="decimal" placeholder="0.00" />
            <small v-if="form.errors.total" class="error">{{ form.errors.total }}</small>
          </label>
          <label class="field">
            <span>Currency</span>
            <input v-model="form.currency" class="input" maxlength="3" placeholder="CHF" />
            <small v-if="form.errors.currency" class="error">{{ form.errors.currency }}</small>
          </label>
        </div>
        <div v-if="receipt.amounts.length > 1">
          <p class="receipt-facts">Every amount on the document</p>
          <div class="receipt-amounts">
            <span v-for="(a, i) in receipt.amounts" :key="i">{{ a.amount }}{{ a.currency ? ` ${a.currency}` : '' }}</span>
          </div>
        </div>
      </section>

      <section>
        <h3 class="section-title">Paid by</h3>
        <template v-if="receipt.paid_by">
          <div class="side-row">
            {{ receipt.paid_by.kind }} · {{ receipt.paid_by.payee }}
            <span class="sub">
              {{ receipt.paid_by.date }} · CHF {{ receipt.paid_by.amount.toFixed(2) }}<template v-if="receipt.paid_by.original"> ({{ receipt.paid_by.original.amount.toFixed(2) }} {{ receipt.paid_by.original.currency }})</template>
              · {{ receipt.paid_by.state === 'matched' ? (isNumbered ? `numbered ${receipt.number_prefix}` : 'confirmed, not numbered yet') : 'proposed, not confirmed' }}
            </span>
          </div>
          <p class="receipt-facts"><Link :href="`/bank?quarter=${receipt.paid_by.quarter}`" class="link-ink">Open the bank ledger</Link> to confirm, change or number it.</p>
        </template>
        <p v-else class="receipt-facts">No bank or card row yet. It is proposed once the statement with the payment is imported.</p>
        <label class="field">
          <span>Payment method on the document</span>
          <select v-model="form.payment_method" class="input">
            <option value="bank">Bank account</option>
            <option value="card">Credit card</option>
            <option value="unknown">Not stated</option>
          </select>
        </label>
      </section>

      <section>
        <h3 class="section-title">In Dropbox</h3>
        <div class="form-grid">
          <label class="field">
            <span>Year</span>
            <input v-model.number="form.target_year" class="input" type="number" min="2000" max="2100" :disabled="isNumbered" />
          </label>
          <label class="field">
            <span>Month folder</span>
            <select v-model.number="form.target_month" class="input" :disabled="isNumbered">
              <option v-for="(m, i) in MONTHS" :key="m" :value="i + 1">{{ m }}</option>
            </select>
          </label>
          <label class="field span-2">
            <span>Filename</span>
            <input v-model="form.filename" class="input" :placeholder="receipt.is_photo ? 'made from date and vendor' : receipt.original_name" :disabled="isNumbered" />
            <small v-if="form.errors.filename" class="error">{{ form.errors.filename }}</small>
          </label>
        </div>
        <p class="receipt-facts">
          <template v-if="receipt.dropbox_path">{{ receipt.dropbox_path }}<br /></template>
          <template v-else>Goes to {{ receipt.folder ?? 'the month of its date' }} once it is read.<br /></template>
          <template v-if="isNumbered">It carries its number, so ernte leaves it where it is.</template>
          <template v-else-if="isFiled">Saving a new month or filename moves the file in Dropbox.</template>
        </p>
      </section>

      <section>
        <h3 class="section-title">Note</h3>
        <textarea v-model="form.note" class="input" rows="2" aria-label="Note" placeholder="For yourself or the accountant"></textarea>
        <p class="receipt-facts">
          Arrived as {{ receipt.original_name }}<template v-if="receipt.is_photo">, a photo converted to PDF</template>.
          {{ receipt.has_text_layer ? 'The PDF has a text layer.' : 'It is an image without a text layer.' }}
        </p>
        <button type="button" class="ledger-link" style="align-self: flex-start" @click="remove">Remove from ernte</button>
      </section>
    </form>
  </div>
</template>
