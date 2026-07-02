<script setup>
import { onMounted } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { pushRecent } from '@/composables/useRecent.js';
import ContactsEditor from '@/Components/ContactsEditor.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, required: true },
});

const form = useForm({
  ...props.client,
  contacts: (props.client.contacts ?? []).map((c) => ({ ...c })),
});

onMounted(() => {
  pushRecent({ url: `/clients/${props.client.id}`, label: props.client.name });
});

function submit() {
  form.transform((d) => ({ ...d, default_rate_rappen: d.default_rate_rappen ? Number(d.default_rate_rappen) : null }))
      .patch(`/clients/${props.client.id}`);
}

function archive() {
  if (!confirm(`Archive ${props.client.name}? Projects remain but the client is hidden from active lists.`)) return;
  router.delete(`/clients/${props.client.id}`);
}
</script>

<template>
  <Head :title="`Edit ${client.name}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/clients">~ / clients</Link>
        <span class="ascii-dot">/</span>
        <Link :href="`/clients/${client.id}`">{{ client.short_code }}</Link>
      </div>
      <h1 class="page-title">{{ client.name }}</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button class="btn ghost" @click="archive">Archive</button>
    </div>
  </div>

  <form @submit.prevent="submit" style="max-width: 720px; padding: 20px 28px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px">
    <label class="field" style="grid-column: span 2">
      <span>Name</span>
      <input v-model="form.name" required />
      <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
    </label>
    <label class="field">
      <span>Short code</span>
      <input v-model="form.short_code" maxlength="4" style="text-transform: uppercase" />
      <small v-if="form.errors.short_code" class="err">{{ form.errors.short_code }}</small>
    </label>
    <label class="field">
      <span>Country</span>
      <input v-model="form.country" maxlength="2" style="text-transform: uppercase" />
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
    <div class="field" style="grid-column: span 2">
      <span>Contacts</span>
      <ContactsEditor v-model="form.contacts" />
      <small v-if="form.errors.contacts" class="err">{{ form.errors.contacts }}</small>
    </div>
    <div style="grid-column: span 2; display: flex; gap: 8px; margin-top: 12px">
      <button type="submit" class="btn primary" :disabled="form.processing">Save</button>
      <Link :href="`/clients/${client.id}`" class="btn ghost">Cancel</Link>
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
