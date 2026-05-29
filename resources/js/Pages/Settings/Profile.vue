<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  profile: { type: Object, required: true },
});

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
  </form>
</template>
