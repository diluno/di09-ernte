<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Heatmap from '@/Components/Heatmap.vue';
import EntryRow from '@/Components/EntryRow.vue';
import TaskRow from '@/Components/TaskRow.vue';
import Icon from '@/Components/Icon.vue';
import { pushRecent } from '@/composables/useRecent.js';
import { formatChf } from '@/formatters/money.js';
import { glyphClass } from '@/formatters/glyph.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  project: { type: Object, required: true },
  tasks:   { type: Array,  required: true },
  recent_entries: { type: Array, required: true },
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

function togglePin() {
  const url = props.project.is_pinned
    ? `/projects/${props.project.code}/unpin`
    : `/projects/${props.project.code}/pin`;
  router.post(url, {}, { preserveScroll: true });
}

function startTimer() {
  router.post('/timer/start', { project_id: props.project.id }, {
    preserveScroll: true,
    onSuccess: () => router.visit('/timer'),
  });
}

function fmtHours(h) { return `${h.toFixed(1)}h`; }
function fmtMoneyShort(v) { return formatChf(v); }

const remaining = computed(() => Math.max(0, props.project.budget_hours - props.project.spent_hours));
</script>

<template>
  <Head :title="`${project.code} · ${project.name}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/projects">~ / projects</Link>
        <span class="ascii-dot">/</span>
        <span>{{ project.code }}</span>
      </div>
      <h1 class="page-title">
        <span class="proj-glyph" :class="glyphClass(project.id)" style="width: 28px; height: 28px; font-size: 14px">{{ project.code[0] }}</span>
        {{ project.name }}
        <span class="meta">{{ project.client.name }}<span class="ascii-dot">·</span>{{ fmtMoneyShort(project.rate) }}/h</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button
        class="btn"
        :class="{ primary: project.is_pinned }"
        @click="togglePin"
      >
        <Icon name="star" :style="{ color: project.is_pinned ? 'var(--gold)' : undefined }" />
        {{ project.is_pinned ? 'Pinned' : 'Pin' }}
      </button>
      <button class="btn" @click="startTimer"><Icon name="play" /> Start timer</button>
      <Link :href="`/projects/${project.code}/edit`" class="btn">Edit</Link>
      <Link :href="`/invoices/new?client=${project.client.id}&project=${project.id}`" class="btn primary">+ Invoice</Link>
    </div>
  </div>

  <div class="detail-grid">
    <div class="detail-main">
      <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin: 0 0 28px">
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Hours spent</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px" :style="{ color: project.band === 'over' ? 'var(--red)' : 'var(--ink)' }">
            {{ fmtHours(project.spent_hours) }}
          </div>
          <div style="font-size: var(--fs-xs); color: var(--ink-3); margin-top: 2px">of {{ fmtHours(project.budget_hours) }}</div>
        </div>
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Fees</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px">{{ fmtMoneyShort(project.spent_amount) }}</div>
          <div style="font-size: var(--fs-xs); color: var(--ink-3); margin-top: 2px">of {{ fmtMoneyShort(project.budget_amount) }}</div>
        </div>
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Remaining</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px">{{ fmtHours(remaining) }}</div>
          <div style="font-size: var(--fs-xs); color: var(--ink-3); margin-top: 2px">{{ project.band === 'over' ? 'exceeded' : 'available' }}</div>
        </div>
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Budget used</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px">{{ project.pct_hours }}%</div>
        </div>
      </div>

      <h3 class="section-title">Tasks</h3>
      <div class="task-list">
        <TaskRow v-for="t in tasks" :key="t.id" :task="t" :project-id="project.id" />
        <form v-if="showAddTask" @submit.prevent="submitTask" style="display: grid; grid-template-columns: 1fr 120px auto auto; gap: 8px; align-items: center; margin-top: 12px">
          <input v-model="taskForm.name" placeholder="task name" required class="input" />
          <input type="number" v-model="taskForm.budget_hours" min="0" placeholder="budget h" class="input" />
          <button type="submit" class="btn primary" :disabled="taskForm.processing">add</button>
          <button type="button" class="btn ghost" @click="showAddTask = false">cancel</button>
        </form>
        <button v-else class="btn ghost" style="margin-top: 12px; align-self: flex-start" @click="showAddTask = true">+ Add task</button>
        <div v-if="Object.keys(taskForm.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 6px">
          {{ Object.values(taskForm.errors).join(' · ') }}
        </div>
      </div>

      <h3 class="section-title" style="margin-top: 28px">Recent entries</h3>
      <div>
        <EntryRow v-for="(e, i) in recent_entries" :key="e.id" :entry="e" :color-index="i" />
        <div v-if="recent_entries.length === 0" class="muted" style="padding: 12px">No entries yet</div>
      </div>
    </div>

    <aside class="detail-side">
      <h3 class="section-title">Details</h3>
      <dl class="kv">
        <dt>Client</dt><dd>{{ project.client.name }}</dd>
        <dt>Code</dt><dd><span class="mono-tag">{{ project.code }}</span></dd>
        <dt>Status</dt><dd><span class="badge dot" :class="project.status">{{ project.status }}</span></dd>
        <dt>Started</dt><dd>{{ project.started_on ?? '—' }}</dd>
        <dt>Due</dt><dd>{{ project.deadline_on ?? '—' }}</dd>
        <dt>Billing</dt><dd>{{ project.billable ? `Hourly · ${fmtMoneyShort(project.rate)}/h` : 'non-billable' }}</dd>
      </dl>

      <h3 class="section-title" style="margin-top: 24px">Description</h3>
      <p style="font-size: var(--fs-sm); color: var(--ink-2); line-height: 1.6; margin: 0">{{ project.description ?? '—' }}</p>

      <h3 class="section-title" style="margin-top: 24px">Activity heatmap</h3>
      <Heatmap :cells="heatmap" />
    </aside>
  </div>
</template>

<style scoped>
.input {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; color: var(--ink);
}
.input:focus { outline: none; border-color: var(--accent); }
</style>
