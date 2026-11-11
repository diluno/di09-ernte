<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fmtDate } from '@/formatters/date.js';

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
  form.patch('/settings/profile', {
    preserveScroll: true,
    // The saved values are the new baseline for "Unsaved changes".
    onSuccess: () => form.defaults(),
  });
}

// Login password (route `password.update`).
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
function updatePassword() {
  password.put('/password', {
    preserveScroll: true,
    onSuccess: () => password.reset(),
    onError: () => {
      if (password.errors.password) password.reset('password', 'password_confirmation');
      if (password.errors.current_password) password.reset('current_password');
    },
  });
}
</script>

<template>
  <Head title="Settings" />

  <div class="page-head">
    <div>
      <div class="crumb"><span>Settings</span><span>·</span><span>{{ profile.name || 'Business profile' }}</span></div>
      <h1 class="page-title">Settings</h1>
    </div>
    <div class="page-actions">
      <Link href="/settings/vat-rates" class="btn">VAT rates</Link>
    </div>
  </div>

  <!-- Business profile: the page's one primary action, in a footer that stays in view while this form is. -->
  <form class="form-page settings-form" @submit.prevent="submit">
    <div class="form-body">
      <section class="form-section">
        <h2 class="section-title">Business identity</h2>
        <div class="form-grid">
          <label class="field span-2">
            <span>Name</span>
            <input v-model="form.name" class="input" required :aria-invalid="!!form.errors.name" />
            <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
          </label>
          <label class="field">
            <span>Email</span>
            <input v-model="form.email" class="input" type="email" :aria-invalid="!!form.errors.email" />
            <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
            <small v-else class="hint">Sender address of invoices, estimates and reminders.</small>
          </label>
          <label class="field">
            <span>Sender name</span>
            <input v-model="form.sender_name" class="input" placeholder="Samuel Alder" :aria-invalid="!!form.errors.sender_name" />
            <small v-if="form.errors.sender_name" class="error">{{ form.errors.sender_name }}</small>
            <small v-else class="hint">Signs off the mails.</small>
          </label>
          <label class="field span-2">
            <span>Logo path</span>
            <input v-model="form.logo_path" class="input" :aria-invalid="!!form.errors.logo_path" />
            <small v-if="form.errors.logo_path" class="error">{{ form.errors.logo_path }}</small>
          </label>
          <label class="field">
            <span>UID</span>
            <input v-model="form.uid" class="input" placeholder="CHE-000.000.000" :aria-invalid="!!form.errors.uid" />
            <small v-if="form.errors.uid" class="error">{{ form.errors.uid }}</small>
          </label>
          <label class="field">
            <span>VAT ID</span>
            <input v-model="form.vat_id" class="input" placeholder="CHE-000.000.000 MWST" :aria-invalid="!!form.errors.vat_id" />
            <small v-if="form.errors.vat_id" class="error">{{ form.errors.vat_id }}</small>
          </label>
        </div>
      </section>

      <section class="form-section">
        <h2 class="section-title">Address</h2>
        <div class="form-grid">
          <label class="field span-2">
            <span>Address line 1</span>
            <input v-model="form.address_line_1" class="input" :aria-invalid="!!form.errors.address_line_1" />
            <small v-if="form.errors.address_line_1" class="error">{{ form.errors.address_line_1 }}</small>
          </label>
          <label class="field span-2">
            <span>Address line 2</span>
            <input v-model="form.address_line_2" class="input" :aria-invalid="!!form.errors.address_line_2" />
            <small v-if="form.errors.address_line_2" class="error">{{ form.errors.address_line_2 }}</small>
          </label>
          <label class="field">
            <span>Postal code</span>
            <input v-model="form.postal_code" class="input" :aria-invalid="!!form.errors.postal_code" />
            <small v-if="form.errors.postal_code" class="error">{{ form.errors.postal_code }}</small>
          </label>
          <label class="field">
            <span>City</span>
            <input v-model="form.city" class="input" :aria-invalid="!!form.errors.city" />
            <small v-if="form.errors.city" class="error">{{ form.errors.city }}</small>
          </label>
          <label class="field">
            <span>Country</span>
            <input v-model="form.country" class="input" maxlength="2" :aria-invalid="!!form.errors.country" />
            <small v-if="form.errors.country" class="error">{{ form.errors.country }}</small>
            <small v-else class="hint">Two-letter code, e.g. CH.</small>
          </label>
        </div>
      </section>

      <section class="form-section">
        <h2 class="section-title">Banking</h2>
        <div class="form-grid">
          <label class="field span-2">
            <span>IBAN</span>
            <input v-model="form.iban" class="input" :aria-invalid="!!form.errors.iban" />
            <small v-if="form.errors.iban" class="error">{{ form.errors.iban }}</small>
            <small v-else class="hint">Required to issue invoices: it goes on the QR bill.</small>
          </label>
          <label class="field span-2">
            <span>QR-IBAN</span>
            <input v-model="form.qr_iban" class="input" :aria-invalid="!!form.errors.qr_iban" />
            <small v-if="form.errors.qr_iban" class="error">{{ form.errors.qr_iban }}</small>
          </label>
        </div>
      </section>

      <section class="form-section">
        <h2 class="section-title">Invoice defaults</h2>
        <div class="form-grid">
          <label class="field">
            <span>Currency</span>
            <input v-model="form.default_currency" class="input" readonly :aria-invalid="!!form.errors.default_currency" />
            <small v-if="form.errors.default_currency" class="error">{{ form.errors.default_currency }}</small>
          </label>
          <label class="field">
            <span>Fallback VAT rate %</span>
            <input v-model="form.default_vat_rate" class="input num" type="number" min="0" max="100" step="0.01" :aria-invalid="!!form.errors.default_vat_rate" />
            <small v-if="form.errors.default_vat_rate" class="error">{{ form.errors.default_vat_rate }}</small>
            <small v-else class="hint">Only used when no <Link href="/settings/vat-rates" class="link-ink">dated VAT rate</Link> covers a document's date.</small>
          </label>
          <label class="field">
            <span>Number prefix</span>
            <input v-model="form.invoice_number_prefix" class="input" :aria-invalid="!!form.errors.invoice_number_prefix" />
            <small v-if="form.errors.invoice_number_prefix" class="error">{{ form.errors.invoice_number_prefix }}</small>
          </label>
          <label class="field">
            <span>Reminder days</span>
            <input v-model="form.reminder_days_after_due" class="input num" type="number" min="1" max="60" step="1" :aria-invalid="!!form.errors.reminder_days_after_due" />
            <small v-if="form.errors.reminder_days_after_due" class="error">{{ form.errors.reminder_days_after_due }}</small>
            <small v-else class="hint">Days after the due date between payment reminders.</small>
          </label>
        </div>
      </section>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save profile' }}</button>
      <span v-if="form.isDirty" class="form-foot__aside">Unsaved changes</span>
      <span v-else-if="form.recentlySuccessful" class="form-foot__aside" role="status">Saved.</span>
    </div>
  </form>

  <div class="form-body settings-more">
    <section class="form-section" aria-labelledby="dropbox-title">
      <h2 id="dropbox-title" class="section-title">Dropbox · bookkeeping folder</h2>
      <p v-if="!dropbox.configured" class="settings-text">
        Not configured. Set <code>DROPBOX_APP_KEY</code>, <code>DROPBOX_APP_SECRET</code> and <code>DROPBOX_RECEIPTS_ROOT</code> in the environment.
      </p>
      <template v-else-if="dropbox.connected">
        <p class="settings-text">Connected as <strong>{{ dropbox.account }}</strong><template v-if="dropbox.connected_at"> since {{ fmtDate(dropbox.connected_at) }}</template>.</p>
        <p class="settings-text">
          Folder <code>{{ dropbox.root }}</code>:
          <span v-if="dropbox.problem" class="error">{{ dropbox.problem }}</span>
          <span v-else-if="dropbox.root_ok">found</span>
          <span v-else class="error">not found in this Dropbox</span>
        </p>
        <div class="settings-actions">
          <button type="button" class="btn danger" @click="disconnectDropbox">Disconnect</button>
        </div>
      </template>
      <template v-else>
        <p class="settings-text">Not connected. ernte will only read and write inside <code>{{ dropbox.root }}</code>.</p>
        <p v-if="dropbox.problem" class="settings-text error">{{ dropbox.problem }}</p>
        <div class="settings-actions">
          <a href="/settings/dropbox/connect" class="btn">Connect Dropbox</a>
        </div>
      </template>
    </section>

    <form class="form-section" aria-labelledby="password-title" @submit.prevent="updatePassword">
      <h2 id="password-title" class="section-title">Password</h2>
      <div class="form-grid">
        <label class="field span-2">
          <span>Current password</span>
          <input v-model="password.current_password" class="input" type="password" autocomplete="current-password" required :aria-invalid="!!password.errors.current_password" />
          <small v-if="password.errors.current_password" class="error">{{ password.errors.current_password }}</small>
        </label>
        <label class="field">
          <span>New password</span>
          <input v-model="password.password" class="input" type="password" autocomplete="new-password" required :aria-invalid="!!password.errors.password" />
          <small v-if="password.errors.password" class="error">{{ password.errors.password }}</small>
        </label>
        <label class="field">
          <span>Confirm new password</span>
          <input v-model="password.password_confirmation" class="input" type="password" autocomplete="new-password" required :aria-invalid="!!password.errors.password_confirmation" />
          <small v-if="password.errors.password_confirmation" class="error">{{ password.errors.password_confirmation }}</small>
        </label>
      </div>
      <div class="settings-actions">
        <button type="submit" class="btn" :disabled="password.processing">{{ password.processing ? 'Saving…' : 'Change password' }}</button>
        <span v-if="password.recentlySuccessful" class="hint" role="status">Password changed.</span>
      </div>
    </form>

    <form class="form-section" aria-labelledby="standing-title" @submit.prevent="addStanding">
      <h2 id="standing-title" class="section-title">Standing documents</h2>
      <p class="hint">A document that belongs to the same payment every month. When a bank row contains the keyword and has no receipt, ernte copies the document into that month, numbered.</p>
      <div v-if="standing_documents.length" class="settings-list">
        <div v-for="doc in standing_documents" :key="doc.id" class="side-row">
          <span>
            <strong>{{ doc.label }}</strong> · rows containing “{{ doc.row_keyword }}” · <code>{{ doc.source_path }}</code> → <code>NN_{{ doc.filename }}</code>
          </span>
          <button type="button" class="btn sm danger" :aria-label="`Remove standing document ${doc.label}`" @click="removeStanding(doc)">Remove</button>
        </div>
      </div>
      <div class="form-grid">
        <label class="field">
          <span>Label</span>
          <input v-model="standing.label" class="input" placeholder="Office rent" :aria-invalid="!!standing.errors.label" />
          <small v-if="standing.errors.label" class="error">{{ standing.errors.label }}</small>
        </label>
        <label class="field">
          <span>Keyword in the bank row</span>
          <input v-model="standing.row_keyword" class="input" placeholder="Miete" :aria-invalid="!!standing.errors.row_keyword" />
          <small v-if="standing.errors.row_keyword" class="error">{{ standing.errors.row_keyword }}</small>
        </label>
        <label class="field">
          <span>File, relative to {{ dropbox.root }}</span>
          <input v-model="standing.source_path" class="input" placeholder="mietvertrag-buero.pdf" :aria-invalid="!!standing.errors.source_path" />
          <small v-if="standing.errors.source_path" class="error">{{ standing.errors.source_path }}</small>
        </label>
        <label class="field">
          <span>Name of the copy</span>
          <input v-model="standing.filename" class="input" placeholder="same as the file" :aria-invalid="!!standing.errors.filename" />
          <small v-if="standing.errors.filename" class="error">{{ standing.errors.filename }}</small>
          <small v-else class="hint">Optional.</small>
        </label>
      </div>
      <div class="settings-actions">
        <button type="submit" class="btn" :disabled="standing.processing">Add standing document</button>
      </div>
    </form>
  </div>
</template>
