<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const form = useForm({
  name: '', short_code: '', contact_name: '', email: '',
  address_line_1: '', address_line_2: '', postal_code: '', city: '',
  country: 'CH', vat_id: '', default_rate_rappen: null,
});

function submit() {
  form.transform((d) => ({ ...d, default_rate_rappen: d.default_rate_rappen ? Number(d.default_rate_rappen) : null }))
      .post('/clients');
}
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/clients">~ / clients</Link>
        <span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">New client</h1>
    </div>
  </div>

  <form @submit.prevent="submit" style="max-width: 720px; padding: 0 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px">
    <label class="field" style="grid-column: span 2">
      <span>Name</span>
      <input v-model="form.name" required />
      <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
    </label>
    <label class="field">
      <span>Short code (≤4 chars)</span>
      <input v-model="form.short_code" required maxlength="4" style="text-transform: uppercase" />
      <small v-if="form.errors.short_code" class="err">{{ form.errors.short_code }}</small>
    </label>
    <label class="field">
      <span>Country</span>
      <input v-model="form.country" required maxlength="2" style="text-transform: uppercase" />
    </label>
    <label class="field">
      <span>Contact name</span>
      <input v-model="form.contact_name" />
    </label>
    <label class="field">
      <span>Email</span>
      <input type="email" v-model="form.email" />
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Address line 1</span>
      <input v-model="form.address_line_1" />
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Address line 2</span>
      <input v-model="form.address_line_2" />
    </label>
    <label class="field">
      <span>Postal code</span>
      <input v-model="form.postal_code" />
    </label>
    <label class="field">
      <span>City</span>
      <input v-model="form.city" />
    </label>
    <label class="field">
      <span>VAT ID</span>
      <input v-model="form.vat_id" />
    </label>
    <label class="field">
      <span>Default rate (rappen)</span>
      <input type="number" v-model="form.default_rate_rappen" min="0" />
    </label>
    <div style="grid-column: span 2; display: flex; gap: 8px; margin-top: 12px">
      <button type="submit" class="btn primary" :disabled="form.processing">Create</button>
      <Link href="/clients" class="btn ghost">Cancel</Link>
    </div>
  </form>
</template>

<style scoped>
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field input {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; color: var(--ink);
}
.field input:focus { outline: none; border-color: var(--accent); }
.err { color: var(--red); font-size: var(--fs-xs); }
</style>
