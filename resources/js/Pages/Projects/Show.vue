<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Heatmap from '@/Components/Heatmap.vue';
import EntryRow from '@/Components/EntryRow.vue';
import TaskRow from '@/Components/TaskRow.vue';
import ProjectNotes from '@/Components/ProjectNotes.vue';
import Icon from '@/Components/Icon.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import { pushRecent } from '@/composables/useRecent.js';
import { usePendingAction } from '@/composables/usePendingAction.js';
import { fmtMoney } from '@/formatters/money.js';
import { fmtDate, toIsoDate } from '@/formatters/date.js';
import { fmtHours } from '@/formatters/duration.js';
import { glyphClass } from '@/formatters/glyph.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  project: { type: Object, required: true },
  tasks:   { type: Array,  required: true },
  recent_entries: { type: Array, required: true },
  notes:   { type: Array,  required: true },
  heatmap: { type: Array, required: true },
  counts:  { type: Object, required: true },
});

const showAddTask = ref(false);
const taskForm = useForm({
  project_id: props.project.id,
  name: '',
  budget_hours: null,
});

function submitTask() {
  taskForm.post('/tasks', {
    preserveScroll: true,
    onSuccess: () => { showAddTask.value = false; taskForm.reset(); taskForm.project_id = props.project.id; },
  });
}

onMounted(() => {
  pushRecent({ url: `/projects/${props.project.code}`, label: props.project.name });
});

// ── Actions ──
const { pending, pendingKey, run: act } = usePendingAction();
const isArchived = computed(() => props.project.status === 'archived');

function togglePin() {
  act('post', `/projects/${props.project.code}/${props.project.is_pinned ? 'unpin' : 'pin'}`, {}, { key: 'pin' });
}

function startTimer() {
  act('post', '/timer/start', { project_id: props.project.id }, {
    key: 'timer',
    onSuccess: () => router.visit('/timer'),
  });
}

function archive() {
  act('post', `/projects/${props.project.code}/archive`, {}, {
    confirm: `Archive ${props.project.name}? It will be hidden from active lists.`,
  });
}
function unarchive() { act('post', `/projects/${props.project.code}/unarchive`); }

const menuItems = computed(() => [
  { label: 'Edit project', href: `/projects/${props.project.code}/edit` },
  { label: `Open ${props.project.client.name}`, href: `/clients/${props.project.client.id}` },
  { separator: true },
  isArchived.value
    ? { label: 'Unarchive', action: unarchive }
    : { label: 'Archive…', action: archive },
]);

function editEntry(entry) {
  router.get('/timer', { date: toIsoDate(entry.started_at), edit: entry.id });
}

function deleteEntry(entry) {
  if (window.confirm('Delete this entry?')) {
    router.delete(`/entries/${entry.id}`, { preserveScroll: true });
  }
}

// ── Stats ──
// Budget tiles show whole francs (aggregates, unit set separately); the rate shows two decimals.
const tile = (v) => fmtMoney(v, { whole: true });
const hasBudget = computed(() => props.project.budget_hours > 0);
const isOver = computed(() => hasBudget.value && props.project.band === 'over');
const remaining = computed(() => props.project.budget_hours - props.project.spent_hours);
const rateText = computed(() => {
  if (!props.project.billable) return 'non-billable';
  return props.project.rate > 0 ? `CHF ${fmtMoney(props.project.rate)}/h` : 'no rate set';
});
const feesFoot = computed(() => {
  if (props.project.budget_amount > 0) return `of ${tile(props.project.budget_amount)} CHF budget`;
  return props.project.billable && props.project.rate > 0 ? `at ${rateText.value}` : rateText.value;
});

const statusMeta = computed(() => {
  if (isArchived.value) return '● archived';
  if (isOver.value) return '● over budget';
  return props.project.retainer ? '● retainer' : '● active';
});
</script>

<template>
  <Head :title="`${project.code} · ${project.name}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/projects">Projects</Link><span>/</span><span>{{ project.code }}</span><span>·</span><Link :href="`/clients/${project.client.id}`">{{ project.client.name }}</Link>
      </div>
      <h1 class="page-title">
        <span class="title-with-glyph">
          <span class="proj-glyph proj-glyph--title" :class="glyphClass(project.id)" aria-hidden="true">{{ project.code[0] }}</span>
          <span>{{ project.name }}</span>
        </span>
        <span class="meta" :class="{ 'is-red': isOver }">{{ statusMeta }}</span>
      </h1>
    </div>
    <div class="page-actions">
      <button
        type="button"
        class="btn icon pin-toggle"
        :aria-pressed="project.is_pinned ? 'true' : 'false'"
        :aria-label="project.is_pinned ? 'Pinned to sidebar' : 'Pin to sidebar'"
        :title="project.is_pinned ? 'Pinned to the sidebar — click to unpin' : 'Pin to the sidebar'"
        :disabled="pending"
        @click="togglePin"
      ><Icon name="star" /></button>
      <button type="button" class="btn" :disabled="pending" @click="startTimer">
        <Icon name="play" />{{ pendingKey === 'timer' ? 'Starting…' : 'Start timer' }}
      </button>
      <Link :href="`/invoices/new?client=${project.client.id}&project=${project.id}`" class="btn primary">+ Invoice</Link>
      <ActionMenu :items="menuItems" :disabled="pending" />
    </div>
  </div>

  <div class="stats">
    <div class="stat" :class="{ 'is-red': isOver }">
      <div class="label">Hours spent</div>
      <div class="val">{{ fmtHours(project.spent_hours) }}</div>
      <div class="foot">{{ hasBudget ? `of ${fmtHours(project.budget_hours)} budget` : 'no budget set' }}</div>
    </div>
    <div class="stat">
      <div class="label">Fees</div>
      <div class="val">{{ tile(project.spent_amount) }}<span class="unit">CHF</span></div>
      <div class="foot">{{ feesFoot }}</div>
    </div>
    <div class="stat" :class="{ 'is-red': isOver }">
      <div class="label">{{ isOver ? 'Over by' : 'Remaining' }}</div>
      <div class="val">{{ hasBudget ? fmtHours(Math.abs(remaining)) : '—' }}</div>
      <div class="foot">{{ !hasBudget ? 'no budget set' : isOver ? 'past the hours budget' : 'of the hours budget' }}</div>
    </div>
    <div class="stat" :class="{ 'is-red': isOver }">
      <div class="label">Budget used</div>
      <div class="val">{{ hasBudget ? project.pct_hours : '—' }}<span v-if="hasBudget" class="unit">%</span></div>
      <div class="foot">{{ hasBudget ? (project.band === 'warn' ? 'of hours · nearly used' : 'of hours') : 'no budget set' }}</div>
    </div>
  </div>

  <div class="detail-grid">
    <div class="detail-main">
      <section class="detail-section">
        <h2 class="section-title">Tasks <span v-if="tasks.length" class="count">{{ tasks.length }}</span></h2>
        <div class="task-list">
          <TaskRow v-for="t in tasks" :key="t.id" :task="t" :project-id="project.id" />
          <div v-if="tasks.length === 0 && !showAddTask" class="section-empty">No tasks yet.</div>
        </div>
        <form v-if="showAddTask" class="task-add" @submit.prevent="submitTask">
          <input v-model="taskForm.name" class="input" placeholder="Task name" aria-label="Task name" required :aria-invalid="!!taskForm.errors.name" />
          <input v-model="taskForm.budget_hours" type="number" min="0" step="1" class="input num" placeholder="Budget h" aria-label="Budget hours (optional)" :aria-invalid="!!taskForm.errors.budget_hours" />
          <button type="submit" class="btn sm" :disabled="taskForm.processing">Add task</button>
          <button type="button" class="btn ghost sm" @click="showAddTask = false">Cancel</button>
        </form>
        <button v-else type="button" class="btn ghost sm task-add-toggle" @click="showAddTask = true">+ Add task</button>
        <small v-if="Object.keys(taskForm.errors).length" class="error" role="alert">{{ Object.values(taskForm.errors).join(' · ') }}</small>
      </section>

      <section class="detail-section">
        <h2 class="section-title">Notes <span v-if="counts.notes" class="count">{{ counts.notes }}</span></h2>
        <ProjectNotes :project-code="project.code" :notes="notes" />
      </section>

      <section class="detail-section">
        <h2 class="section-title">Recent entries <span v-if="counts.entries" class="count">{{ counts.entries }}</span></h2>
        <EntryRow v-for="(e, i) in recent_entries" :key="e.id" :entry="e" :color-index="i" show-date @edit="editEntry" @delete="deleteEntry" />
        <div v-if="recent_entries.length === 0" class="section-empty">No entries yet.</div>
      </section>
    </div>

    <aside class="detail-side side-sections">
      <section>
        <h3 class="section-title">Details</h3>
        <div class="side-row split"><span class="k">Client</span><Link :href="`/clients/${project.client.id}`" class="link-ink cell-trunc">{{ project.client.name }}</Link></div>
        <div class="side-row split"><span class="k">Code</span><span class="mono">{{ project.code }}</span></div>
        <div class="side-row split"><span class="k">Billing</span><span>{{ rateText }}</span></div>
        <div class="side-row split"><span class="k">Hours budget</span><span>{{ hasBudget ? fmtHours(project.budget_hours) : '—' }}</span></div>
        <div class="side-row split"><span class="k">Fee budget</span><span>{{ project.budget_amount > 0 ? `CHF ${fmtMoney(project.budget_amount)}` : '—' }}</span></div>
        <div class="side-row split"><span class="k">Started</span><span>{{ project.started_on ? fmtDate(project.started_on) : '—' }}</span></div>
        <div class="side-row split"><span class="k">Deadline</span><span>{{ project.deadline_on ? fmtDate(project.deadline_on) : '—' }}</span></div>
      </section>

      <section>
        <h3 class="section-title">Description</h3>
        <p v-if="project.description" class="side-row side-text">{{ project.description }}</p>
        <div v-else class="side-row is-muted">No description.</div>
      </section>

      <section>
        <h3 class="section-title">Activity · 60 days</h3>
        <div class="side-row is-flush">
          <Heatmap :cells="heatmap" />
        </div>
      </section>
    </aside>
  </div>
</template>
