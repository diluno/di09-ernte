<script setup>
import { onMounted } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { pushRecent } from '@/composables/useRecent.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  project: { type: Object, required: true },
  clients: { type: Array, required: true },
});

const form = useForm({
  client_id: props.project.client_id,
  name: props.project.name,
  code: props.project.code,
  glyph: props.project.glyph,
  description: props.project.description ?? '',
  billable: props.project.billable,
  budget_hours: props.project.budget_hours,
  budget_amount: props.project.budget_amount,
  rate: props.project.rate,
  started_on: props.project.started_on ?? '',
  deadline_on: props.project.deadline_on ?? '',
});

onMounted(() => {
  pushRecent({ url: `/projects/${props.project.code}`, label: props.project.name });
});

function submit() {
  form.transform((d) => ({
    client_id: d.client_id,
    name: d.name,
    code: d.code,
    glyph: d.glyph,
    description: d.description,
    billable: d.billable,
    budget_hours: d.budget_hours ? Number(d.budget_hours) : 0,
    budget_amount_rappen: Math.round((Number(d.budget_amount) || 0) * 100),
    rate_rappen: Math.round((Number(d.rate) || 0) * 100),
    started_on: d.started_on || null,
    deadline_on: d.deadline_on || null,
  })).patch(`/projects/${props.project.id}`);
}

function archive() {
  if (!confirm(`Archive ${props.project.name}? It will be hidden from active lists.`)) return;
  router.post(`/projects/${props.project.code}/archive`);
}

function unarchive() {
  router.post(`/projects/${props.project.code}/unarchive`);
}
</script>

<template>
  <Head :title="`Edit ${project.name}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/projects">~ / projects</Link>
        <span class="ascii-dot">/</span>
        <Link :href="`/projects/${project.code}`">{{ project.code }}</Link>
        <span class="ascii-dot">/</span><span>edit</span>
      </div>
      <h1 class="page-title">{{ project.name }}</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button v-if="project.status === 'active'" class="btn ghost" @click="archive">Archive</button>
      <button v-else class="btn ghost" @click="unarchive">Unarchive</button>
    </div>
  </div>

  <form @submit.prevent="submit" style="max-width: 720px; padding: 0 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px">
    <label class="field" style="grid-column: span 2">
      <span>Client</span>
      <select v-model="form.client_id" required>
        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <small v-if="form.errors.client_id" class="err">{{ form.errors.client_id }}</small>
    </label>
    <label class="field">
      <span>Name</span>
      <input v-model="form.name" required />
      <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
    </label>
    <label class="field">
      <span>Code (≤32 chars)</span>
      <input v-model="form.code" required maxlength="32" style="text-transform: uppercase" />
      <small v-if="form.errors.code" class="err">{{ form.errors.code }}</small>
    </label>
    <label class="field">
      <span>Glyph</span>
      <select v-model="form.glyph" required>
        <option value="alt-0">alt-0</option>
        <option value="alt-1">alt-1</option>
        <option value="alt-2">alt-2</option>
        <option value="alt-3">alt-3</option>
        <option value="alt-4">alt-4</option>
      </select>
    </label>
    <label class="field" style="flex-direction: row; align-items: center; gap: 8px">
      <input type="checkbox" v-model="form.billable" />
      <span>Billable</span>
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Description</span>
      <textarea v-model="form.description" rows="2" />
    </label>
    <label class="field">
      <span>Budget hours</span>
      <input type="number" v-model="form.budget_hours" min="0" required />
      <small v-if="form.errors.budget_hours" class="err">{{ form.errors.budget_hours }}</small>
    </label>
    <label class="field">
      <span>Budget amount (CHF)</span>
      <input type="number" v-model="form.budget_amount" min="0" required />
      <small v-if="form.errors.budget_amount_rappen" class="err">{{ form.errors.budget_amount_rappen }}</small>
    </label>
    <label class="field">
      <span>Rate (CHF/h)</span>
      <input type="number" v-model="form.rate" min="0" required />
      <small v-if="form.errors.rate_rappen" class="err">{{ form.errors.rate_rappen }}</small>
    </label>
    <label class="field">
      <span>Started on</span>
      <input type="date" v-model="form.started_on" />
    </label>
    <label class="field">
      <span>Deadline on</span>
      <input type="date" v-model="form.deadline_on" />
      <small v-if="form.errors.deadline_on" class="err">{{ form.errors.deadline_on }}</small>
    </label>
    <div style="grid-column: span 2; display: flex; gap: 8px; margin-top: 12px">
      <button type="submit" class="btn primary" :disabled="form.processing">Save</button>
      <Link :href="`/projects/${project.code}`" class="btn ghost">Cancel</Link>
    </div>
  </form>
</template>

<style scoped>
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field input, .field select, .field textarea {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; color: var(--ink);
}
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--accent); }
.err { color: var(--red); font-size: var(--fs-xs); }
</style>
