<script setup>
import { computed, onMounted } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import ContactsEditor from '@/Components/ContactsEditor.vue';
import { pushRecent } from '@/composables/useRecent.js';
import { toRappen } from '@/formatters/money.js';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard.js';
import { usePendingAction } from '@/composables/usePendingAction.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, required: true },
});

// The rate is edited in CHF/h; the server stores and validates integer rappen.
const { default_rate_rappen: initialRateRappen, ...clientFields } = props.client;
const form = useForm({
  ...clientFields,
  default_rate: initialRateRappen ? initialRateRappen / 100 : null,
  contacts: (props.client.contacts ?? []).map((c) => ({ ...c })),
});
useUnsavedGuard(form);

onMounted(() => {
  pushRecent({ url: `/clients/${props.client.id}`, label: props.client.name });
});

function submit() {
  form.transform(({ default_rate, ...d }) => ({ ...d, default_rate_rappen: default_rate ? toRappen(default_rate) : null }))
      .patch(`/clients/${props.client.id}`);
}

const { pending, run: act } = usePendingAction();
function archive() {
  act('delete', `/clients/${props.client.id}`, {}, {
    preserveScroll: false,
    confirm: `Archive ${props.client.name}? Projects remain but the client is hidden from active lists.`,
  });
}
// Archiving cannot be undone from the app (there is no unarchive), so it is the danger item.
const menuItems = computed(() => [
  { label: 'Archive client…', action: archive, danger: true },
]);
</script>

<template>
  <Head :title="`Edit ${client.name}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/clients">Clients</Link><span>/</span><Link :href="`/clients/${client.id}`">{{ client.short_code }}</Link><span>/</span><span>Edit</span>
      </div>
      <h1 class="page-title">{{ client.name }}</h1>
    </div>
    <div class="page-actions">
      <ActionMenu :items="menuItems" :disabled="pending" />
    </div>
  </div>

  <form class="form-page" @submit.prevent="submit">
    <div class="form-body">
      <section class="form-section">
        <h2 class="section-title">Client</h2>
        <div class="form-grid">
          <label class="field span-2">
            <span>Name</span>
            <input v-model="form.name" class="input" required :aria-invalid="!!form.errors.name" />
            <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
          </label>
          <label class="field">
            <span>Short code</span>
            <input v-model="form.short_code" class="input mono uppercase" maxlength="4" :aria-invalid="!!form.errors.short_code" />
            <small class="hint">Up to 4 characters, unique.</small>
            <small v-if="form.errors.short_code" class="error">{{ form.errors.short_code }}</small>
          </label>
          <label class="field">
            <span>Document language</span>
            <select v-model="form.locale" class="select" :aria-invalid="!!form.errors.locale">
              <option value="de">German</option>
              <option value="en">English</option>
            </select>
            <small class="hint">Mails, PDFs and the QR bill for new invoices and estimates.</small>
            <small v-if="form.errors.locale" class="error">{{ form.errors.locale }}</small>
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
        </div>
        <div class="form-grid cols-3 form-grid--follow">
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
            <input v-model="form.country" class="input mono uppercase" maxlength="2" :aria-invalid="!!form.errors.country" />
            <small class="hint">Two-letter code, e.g. CH.</small>
            <small v-if="form.errors.country" class="error">{{ form.errors.country }}</small>
          </label>
        </div>
      </section>

      <section class="form-section">
        <h2 class="section-title">Billing</h2>
        <div class="form-grid">
          <label class="field">
            <span>Default rate (CHF/h)</span>
            <input v-model="form.default_rate" type="number" class="input num" min="0" step="0.01" placeholder="0.00" :aria-invalid="!!form.errors.default_rate_rappen" />
            <small v-if="form.errors.default_rate_rappen" class="error">{{ form.errors.default_rate_rappen }}</small>
          </label>
          <label class="field">
            <span>VAT ID</span>
            <input v-model="form.vat_id" class="input mono" :aria-invalid="!!form.errors.vat_id" />
            <small v-if="form.errors.vat_id" class="error">{{ form.errors.vat_id }}</small>
          </label>
        </div>
      </section>

      <section class="form-section">
        <h2 class="section-title">Contacts</h2>
        <ContactsEditor v-model="form.contacts" :errors="form.errors" />
        <small v-if="form.errors.contacts" class="error" role="alert">{{ form.errors.contacts }}</small>
      </section>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary" :disabled="form.processing">Save changes</button>
      <Link :href="`/clients/${client.id}`" class="btn ghost">Cancel</Link>
      <span v-if="form.isDirty" class="form-foot__aside">Unsaved changes</span>
    </div>
  </form>
</template>
