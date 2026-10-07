<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  profile: { type: Object, required: true },
  dropbox: { type: Object, required: true },
  standing_documents: { type: Array, default: () => [] },
});

const standing = useForm({ label: '', row_keyword: '', source_path: '', filename: '' });
function addStanding() { standing.post('/settings/standing-documents', { preserveScroll: true, onSuccess: () => standing.reset() }); }
function removeStanding(doc) {
  if (!window.confirm(`Remove "${doc.label}"? Copies already made stay in Dropbox.`)) return;
  router.delete(`/settings/standing-documents/${doc.id}`, { preserveScroll: true });
}

function disconnectDropbox() {
  if (!window.confirm('Disconnect Dropbox? Receipts can no longer be filed until it is connected again.')) return;
  router.post('/settings/dropbox/disconnect', {}, { preserveScroll: true });
}
function fmtDay(iso) { return iso ? new Date(iso).toLocaleDateString('de-CH') : ''; }

const form = useForm({
  name: props.profile.name ?? '',
  address_line_1: props.profile.address_line_1 ?? '',
  address_line_2: props.profile.address_line_2 ?? '',
  postal_code: props.profile.postal_code ?? '',
  city: props.profile.city ?? '',
  country: props.profile.country ?? 'CH',
  uid: props.profile.uid ?? '',
  vat_id: props.profile.vat_id ?? '',
  iban: props.profile.iban ?? '',
  qr_iban: props.profile.qr_iban ?? '',
  email: props.profile.email ?? '',
  sender_name: props.profile.sender_name ?? '',
  logo_path: props.profile.logo_path ?? '',
  default_currency: props.profile.default_currency ?? 'CHF',
  default_vat_rate: props.profile.default_vat_rate ?? '8.10',
  invoice_number_prefix: props.profile.invoice_number_prefix ?? '',
  reminder_days_after_due: props.profile.reminder_days_after_due ?? 7,
});

function submit() {
  form.patch('/settings/profile', { preserveScroll: true });
}
</script>

<template>
  <Head title="Settings" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / settings</div>
      <h1 class="page-title">
        Settings
        <span class="meta">Business profile</span>
      </h1>
    </div>
    <button class="btn primary" :disabled="form.processing" @click="submit">
      {{ form.processing ? 'Saving…' : 'Save profile' }}
    </button>
  </div>

  <form class="settings-page" @submit.prevent="submit">
    <section class="settings-section">
      <h2 class="section-title">Business identity</h2>
      <div class="form-grid">
        <label class="field span-2">
          <span>Name</span>
          <input v-model="form.name" class="input" required />
          <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
        </label>
        <label class="field">
          <span>Email</span>
          <input v-model="form.email" class="input" type="email" />
          <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
        </label>
        <label class="field">
          <span>Sender name (mail sign-off)</span>
          <input v-model="form.sender_name" class="input" placeholder="Samuel Alder" />
          <small v-if="form.errors.sender_name" class="error">{{ form.errors.sender_name }}</small>
        </label>
        <label class="field">
          <span>Logo path</span>
          <input v-model="form.logo_path" class="input" />
          <small v-if="form.errors.logo_path" class="error">{{ form.errors.logo_path }}</small>
        </label>
        <label class="field">
          <span>UID</span>
          <input v-model="form.uid" class="input" placeholder="CHE-000.000.000" />
          <small v-if="form.errors.uid" class="error">{{ form.errors.uid }}</small>
        </label>
        <label class="field">
          <span>VAT ID</span>
          <input v-model="form.vat_id" class="input" placeholder="CHE-000.000.000 MWST" />
          <small v-if="form.errors.vat_id" class="error">{{ form.errors.vat_id }}</small>
        </label>
      </div>
    </section>

    <section class="settings-section">
      <h2 class="section-title">Address</h2>
      <div class="form-grid">
        <label class="field span-2">
          <span>Address line 1</span>
          <input v-model="form.address_line_1" class="input" />
          <small v-if="form.errors.address_line_1" class="error">{{ form.errors.address_line_1 }}</small>
        </label>
        <label class="field span-2">
          <span>Address line 2</span>
          <input v-model="form.address_line_2" class="input" />
          <small v-if="form.errors.address_line_2" class="error">{{ form.errors.address_line_2 }}</small>
        </label>
        <label class="field">
          <span>Postal code</span>
          <input v-model="form.postal_code" class="input" />
          <small v-if="form.errors.postal_code" class="error">{{ form.errors.postal_code }}</small>
        </label>
        <label class="field">
          <span>City</span>
          <input v-model="form.city" class="input" />
          <small v-if="form.errors.city" class="error">{{ form.errors.city }}</small>
        </label>
        <label class="field">
          <span>Country</span>
          <input v-model="form.country" class="input" maxlength="2" />
          <small v-if="form.errors.country" class="error">{{ form.errors.country }}</small>
        </label>
      </div>
    </section>

    <section class="settings-section">
      <h2 class="section-title">Banking</h2>
      <div class="form-grid">
        <label class="field span-2">
          <span>IBAN</span>
          <input v-model="form.iban" class="input" />
          <small v-if="form.errors.iban" class="error">{{ form.errors.iban }}</small>
        </label>
        <label class="field span-2">
          <span>QR-IBAN</span>
          <input v-model="form.qr_iban" class="input" />
          <small v-if="form.errors.qr_iban" class="error">{{ form.errors.qr_iban }}</small>
        </label>
      </div>
    </section>

    <section class="settings-section">
      <h2 class="section-title">Invoice defaults</h2>
      <div class="form-grid">
        <label class="field">
          <span>Currency</span>
          <input v-model="form.default_currency" class="input" readonly />
          <small v-if="form.errors.default_currency" class="error">{{ form.errors.default_currency }}</small>
        </label>
        <label class="field">
          <span>VAT rate (fallback) · <Link href="/settings/vat-rates">manage dated rates</Link></span>
          <input v-model="form.default_vat_rate" class="input" type="number" min="0" max="100" step="0.01" />
          <small v-if="form.errors.default_vat_rate" class="error">{{ form.errors.default_vat_rate }}</small>
        </label>
        <label class="field">
          <span>Number prefix</span>
          <input v-model="form.invoice_number_prefix" class="input" />
          <small v-if="form.errors.invoice_number_prefix" class="error">{{ form.errors.invoice_number_prefix }}</small>
        </label>
        <label class="field">
          <span>Reminder days</span>
          <input v-model="form.reminder_days_after_due" class="input" type="number" min="1" max="60" step="1" />
          <small v-if="form.errors.reminder_days_after_due" class="error">{{ form.errors.reminder_days_after_due }}</small>
        </label>
      </div>
    </section>
    <section class="settings-section">
      <h2 class="section-title">Dropbox · bookkeeping folder</h2>
      <p v-if="!dropbox.configured" class="muted">
        Not configured. Set <code>DROPBOX_APP_KEY</code>, <code>DROPBOX_APP_SECRET</code> and <code>DROPBOX_RECEIPTS_ROOT</code> in the environment.
      </p>
      <template v-else-if="dropbox.connected">
        <p>Connected as <strong>{{ dropbox.account }}</strong><template v-if="dropbox.connected_at"> since {{ fmtDay(dropbox.connected_at) }}</template>.</p>
        <p>
          Folder <code>{{ dropbox.root }}</code>:
          <span v-if="dropbox.problem" class="error">{{ dropbox.problem }}</span>
          <span v-else-if="dropbox.root_ok">found</span>
          <span v-else class="error">not found in this Dropbox</span>
        </p>
        <button type="button" class="btn danger" @click="disconnectDropbox">Disconnect</button>
      </template>
      <template v-else>
        <p class="muted">Not connected. ernte will only read and write inside <code>{{ dropbox.root }}</code>.</p>
        <a href="/settings/dropbox/connect" class="btn">Connect Dropbox</a>
      </template>
    </section>
  </form>

  <form class="settings-page" @submit.prevent="addStanding">
    <section class="settings-section">
      <h2 class="section-title">Standing documents</h2>
      <p class="muted">A document that belongs to the same payment every month. When a bank row contains the keyword and has no receipt, ernte copies the document into that month, numbered.</p>
      <p v-for="doc in standing_documents" :key="doc.id">
        <strong>{{ doc.label }}</strong> · rows containing “{{ doc.row_keyword }}” · <code>{{ doc.source_path }}</code> → <code>NN_{{ doc.filename }}</code>
        <button type="button" class="btn sm ghost danger" @click="removeStanding(doc)">Remove</button>
      </p>
      <div class="form-grid">
        <label class="field">
          <span>Label</span>
          <input v-model="standing.label" class="input" placeholder="Office rent" />
          <small v-if="standing.errors.label" class="error">{{ standing.errors.label }}</small>
        </label>
        <label class="field">
          <span>Keyword in the bank row</span>
          <input v-model="standing.row_keyword" class="input" placeholder="Miete" />
          <small v-if="standing.errors.row_keyword" class="error">{{ standing.errors.row_keyword }}</small>
        </label>
        <label class="field">
          <span>File, relative to {{ dropbox.root }}</span>
          <input v-model="standing.source_path" class="input" placeholder="mietvertrag-buero.pdf" />
          <small v-if="standing.errors.source_path" class="error">{{ standing.errors.source_path }}</small>
        </label>
        <label class="field">
          <span>Name of the copy (optional)</span>
          <input v-model="standing.filename" class="input" placeholder="same as the file" />
        </label>
      </div>
      <button class="btn" :disabled="standing.processing">Add standing document</button>
    </section>
  </form>
</template>
