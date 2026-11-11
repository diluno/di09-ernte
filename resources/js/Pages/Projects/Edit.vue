<script setup>
import { computed, onMounted } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import { toRappen } from '@/formatters/money.js';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard.js';
import { usePendingAction } from '@/composables/usePendingAction.js';
import { pushRecent } from '@/composables/useRecent.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  project: { type: Object, required: true },
  clients: { type: Array, required: true },
});

// Money is edited in CHF and sent as integer rappen; a zero budget shows as an
// empty field ("no budget") and is sent back as 0.
const form = useForm({
  client_id: props.project.client_id,
  name: props.project.name,
  code: props.project.code,
  description: props.project.description ?? '',
  billable: props.project.billable,
  budget_hours: props.project.budget_hours || null,
  budget_amount: props.project.budget_amount || null,
  rate: props.project.rate || null,
  started_on: props.project.started_on ?? '',
  deadline_on: props.project.deadline_on ?? '',
});

onMounted(() => {
  pushRecent({ url: `/projects/${props.project.code}`, label: props.project.name });
});
useUnsavedGuard(form);

function submit() {
  form.transform((d) => ({
    client_id: d.client_id,
    name: d.name,
    code: d.code,
    description: d.description,
    billable: d.billable,
    budget_hours: d.budget_hours ? Number(d.budget_hours) : 0,
    budget_amount_rappen: toRappen(d.budget_amount),
    rate_rappen: toRappen(d.rate),
    started_on: d.started_on || null,
    deadline_on: d.deadline_on || null,
  })).patch(`/projects/${props.project.id}`);
}

const { pending, run: act } = usePendingAction();
function archive() {
  act('post', `/projects/${props.project.code}/archive`, {}, {
    confirm: `Archive ${props.project.name}? It will be hidden from active lists.`,
  });
}
function unarchive() { act('post', `/projects/${props.project.code}/unarchive`); }

const menuItems = computed(() => [
  props.project.status === 'active'
    ? { label: 'Archive…', action: archive }
    : { label: 'Unarchive', action: unarchive },
]);

// The client list holds active clients only; keep an archived client selectable.
const clientOptions = computed(() => props.clients.some((c) => c.id === props.project.client_id)
  ? props.clients
  : [...props.clients, { id: props.project.client_id, name: '(archived client)' }]);
</script>

<template>
  <Head :title="`Edit ${project.name}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/projects">Projects</Link><span>/</span><Link :href="`/projects/${project.code}`">{{ project.code }}</Link><span>/</span><span>Edit</span>
      </div>
      <h1 class="page-title">
        <span>{{ project.name }}</span>
        <span v-if="project.status !== 'active'" class="meta">● {{ project.status }}</span>
      </h1>
    </div>
    <div class="page-actions">
      <ActionMenu :items="menuItems" :disabled="pending" />
    </div>
  </div>

  <form class="form-page" @submit.prevent="submit">
    <div class="form-body">
      <section class="form-section">
        <h2 class="section-title">Project</h2>
        <div class="form-grid">
          <label class="field span-2">
            <span>Client</span>
            <select v-model="form.client_id" class="select" required :aria-invalid="!!form.errors.client_id">
              <option v-for="c in clientOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <small v-if="form.errors.client_id" class="error">{{ form.errors.client_id }}</small>
          </label>
          <label class="field">
            <span>Name</span>
            <input v-model="form.name" class="input" required :aria-invalid="!!form.errors.name" />
            <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
          </label>
          <label class="field">
            <span>Code</span>
            <input v-model="form.code" class="input mono uppercase" required maxlength="32" :aria-invalid="!!form.errors.code" />
            <small class="hint">Changing it changes the project's address.</small>
            <small v-if="form.errors.code" class="error">{{ form.errors.code }}</small>
          </label>
          <label class="field span-2">
            <span>Description</span>
            <textarea v-model="form.description" class="textarea" rows="3" :aria-invalid="!!form.errors.description" />
            <small v-if="form.errors.description" class="error">{{ form.errors.description }}</small>
          </label>
        </div>
      </section>

      <section class="form-section">
        <h2 class="section-title">Billing and budget</h2>
        <div class="form-grid cols-3">
          <label class="field is-check span-all">
            <input v-model="form.billable" type="checkbox" />
            <span>Billable — time on this project can be invoiced</span>
          </label>
          <small v-if="form.errors.billable" class="error span-all">{{ form.errors.billable }}</small>
          <label class="field">
            <span>Rate (CHF/h)</span>
            <input v-model="form.rate" type="number" class="input num" min="0" step="0.01" placeholder="0.00" :aria-invalid="!!form.errors.rate_rappen" />
            <small v-if="form.errors.rate_rappen" class="error">{{ form.errors.rate_rappen }}</small>
          </label>
          <label class="field">
            <span>Hours budget</span>
            <input v-model="form.budget_hours" type="number" class="input num" min="0" step="1" placeholder="none" :aria-invalid="!!form.errors.budget_hours" />
            <small class="hint">Leave empty for no budget.</small>
            <small v-if="form.errors.budget_hours" class="error">{{ form.errors.budget_hours }}</small>
          </label>
          <label class="field">
            <span>Fee budget (CHF)</span>
            <input v-model="form.budget_amount" type="number" class="input num" min="0" step="0.01" placeholder="none" :aria-invalid="!!form.errors.budget_amount_rappen" />
            <small v-if="form.errors.budget_amount_rappen" class="error">{{ form.errors.budget_amount_rappen }}</small>
          </label>
        </div>
      </section>

      <section class="form-section">
        <h2 class="section-title">Schedule</h2>
        <div class="form-grid">
          <label class="field">
            <span>Started on</span>
            <input v-model="form.started_on" type="date" class="input" :aria-invalid="!!form.errors.started_on" />
            <small v-if="form.errors.started_on" class="error">{{ form.errors.started_on }}</small>
          </label>
          <label class="field">
            <span>Deadline</span>
            <input v-model="form.deadline_on" type="date" class="input" :aria-invalid="!!form.errors.deadline_on" />
            <small v-if="form.errors.deadline_on" class="error">{{ form.errors.deadline_on }}</small>
          </label>
        </div>
      </section>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary" :disabled="form.processing">Save changes</button>
      <Link :href="`/projects/${project.code}`" class="btn ghost">Cancel</Link>
      <span v-if="form.isDirty" class="form-foot__aside">Unsaved changes</span>
    </div>
  </form>
</template>
