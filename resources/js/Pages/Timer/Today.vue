<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TimerHero from '@/Components/TimerHero.vue';
import EntryRow from '@/Components/EntryRow.vue';
import { formatChf } from '@/formatters/money.js';
import { glyphClass } from '@/formatters/glyph.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  entries:     { type: Array,  required: true },
  totals:      { type: Object, required: true },
  by_project:  { type: Array,  required: true },
  quick_start: { type: Array,  required: true },
  projects:    { type: Array,  default: () => [] },
});

function fmtHM(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}

const today = new Date().toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

function startProject(projectId) {
  router.post('/timer/start', { project_id: projectId }, { preserveScroll: true });
}

const totalShare = (sec) => props.totals.total_seconds ? (sec / props.totals.total_seconds) * 100 : 0;

const showManual = ref(false);
const editingId = ref(null);
const originalProjectId = ref(null);
const formEl = ref(null);
const page = usePage();

if (page.url.includes('manual=1')) {
  showManual.value = true;
}

function openManualEntry() {
  showManual.value = true;
}

onMounted(() => window.addEventListener('ernte:open-manual-entry', openManualEntry));
onUnmounted(() => window.removeEventListener('ernte:open-manual-entry', openManualEntry));

const pad = (n) => String(n).padStart(2, '0');
const dateStr = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const timeStr = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;

const nowDate = new Date();
const oneHourAgo = new Date(nowDate.getTime() - 60 * 60 * 1000);

const manualForm = useForm({
  project_id: '',
  description: '',
  date: dateStr(nowDate),
  start_time: timeStr(oneHourAgo),
  end_time: timeStr(nowDate),
  billable: true,
});

// Compose start/end Dates from the form's date + HH:MM times; an end at or before
// the start is treated as crossing midnight.
function composeRange() {
  const start = new Date(`${manualForm.date}T${manualForm.start_time}`);
  let end = new Date(`${manualForm.date}T${manualForm.end_time}`);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;
  if (end <= start) end = new Date(end.getTime() + 24 * 60 * 60 * 1000);
  return { start, end };
}

const durationLabel = computed(() => {
  const r = composeRange();
  if (!r) return '—';
  const mins = Math.round((r.end - r.start) / 60000);
  return `${Math.floor(mins / 60)}h ${pad(mins % 60)}m`;
});

function submitManual() {
  const editing = editingId.value;
  manualForm.transform((data) => {
    const r = composeRange();
    const payload = {
      project_id: data.project_id,
      description: data.description,
      billable: data.billable,
      started_at: r ? r.start.toISOString() : null,
      ended_at: r ? r.end.toISOString() : null,
    };
    // When editing and the project changed, drop the task so we never leave an
    // entry whose task belongs to a different project.
    if (editing && data.project_id !== originalProjectId.value) {
      payload.task_id = null;
    }
    return payload;
  });

  const opts = {
    onSuccess: () => { showManual.value = false; editingId.value = null; manualForm.reset(); },
    preserveScroll: true,
  };

  if (editing) {
    manualForm.patch(`/entries/${editing}`, opts);
  } else {
    manualForm.post('/entries', opts);
  }
}

function startEdit(entry) {
  const start = new Date(entry.started_at);
  const end = entry.ended_at ? new Date(entry.ended_at) : new Date();
  manualForm.clearErrors();
  manualForm.project_id = entry.project.id;
  manualForm.description = entry.description || '';
  manualForm.date = dateStr(start);
  manualForm.start_time = timeStr(start);
  manualForm.end_time = timeStr(end);
  manualForm.billable = entry.billable;
  editingId.value = entry.id;
  originalProjectId.value = entry.project.id;
  showManual.value = true;
  nextTick(() => formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}

function deleteEntry(entry) {
  if (window.confirm('Delete this entry?')) {
    router.delete(`/entries/${entry.id}`, { preserveScroll: true });
  }
}

function cancelManual() {
  showManual.value = false;
  editingId.value = null;
  manualForm.clearErrors();
  manualForm.reset();
}
</script>

<template>
  <Head title="Today" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / timer</div>
      <h1 class="page-title">
        Today
        <span class="meta">{{ today }}<span class="ascii-dot">·</span>{{ fmtHM(totals.total_seconds) }} logged</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button class="btn" @click="showManual ? cancelManual() : (showManual = true)">{{ showManual ? '× cancel' : '+ Manual entry' }}</button>
    </div>
  </div>

  <form v-if="showManual" ref="formEl" @submit.prevent="submitManual" class="manual-entry">
    <div class="manual-entry__title">{{ editingId ? 'Edit entry' : 'Manual entry' }}</div>

    <div class="me-grid me-grid--main">
      <label class="field">
        <span>Project</span>
        <select v-model="manualForm.project_id" required class="select">
          <option value="" disabled>Select a project…</option>
          <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }} ({{ p.code }})</option>
        </select>
      </label>
      <label class="field">
        <span>Description</span>
        <input v-model="manualForm.description" placeholder="What did you work on?" class="input" />
      </label>
    </div>

    <div class="me-grid me-grid--time">
      <label class="field">
        <span>Date</span>
        <input type="date" v-model="manualForm.date" required class="input" />
      </label>
      <label class="field">
        <span>From</span>
        <input type="time" v-model="manualForm.start_time" required class="input" />
      </label>
      <label class="field">
        <span>To</span>
        <input type="time" v-model="manualForm.end_time" required class="input" />
      </label>
      <label class="field">
        <span>Duration</span>
        <output class="me-dur">{{ durationLabel }}</output>
      </label>
    </div>

    <div class="manual-entry__foot">
      <label class="billable-toggle">
        <input type="checkbox" v-model="manualForm.billable" />
        <span>Billable</span>
      </label>
      <div style="display: flex; gap: 8px">
        <button type="button" class="btn ghost" @click="cancelManual">Cancel</button>
        <button type="submit" class="btn primary" :disabled="manualForm.processing">{{ editingId ? 'Save changes' : 'Save entry' }}</button>
      </div>
    </div>

    <div v-if="Object.keys(manualForm.errors).length" class="manual-entry__err">
      {{ Object.values(manualForm.errors).join(' · ') }}
    </div>
  </form>

  <div class="timer-stage">
    <div>
      <TimerHero />

      <div class="divider-row">Today's entries · {{ entries.length }}</div>
      <EntryRow v-for="(e, i) in entries" :key="e.id" :entry="e" :color-index="i" @edit="startEdit" @delete="deleteEntry" />
      <div v-if="entries.length === 0" class="muted" style="padding: 12px">No entries today yet</div>
    </div>

    <aside>
      <h3 class="section-title">Today summary</h3>
      <div style="border: 1px solid var(--border); padding: 16px; margin-bottom: 18px">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
          <span class="muted" style="font-size: var(--fs-xs)">TOTAL</span>
          <span style="font-size: var(--fs-xl); font-weight: 700">{{ fmtHM(totals.total_seconds) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 8px">
          <span class="muted" style="font-size: var(--fs-xs)">BILLABLE</span>
          <span style="font-size: var(--fs-md); color: var(--forest)">{{ fmtHM(totals.billable_seconds) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 4px">
          <span class="muted" style="font-size: var(--fs-xs)">EARNINGS</span>
          <span style="font-size: var(--fs-md); color: var(--ink)">{{ formatChf(totals.earnings_amount) }}</span>
        </div>
      </div>

      <h3 class="section-title">By project</h3>
      <div v-for="p in by_project" :key="p.project_id" style="margin-bottom: 12px">
        <div style="display: flex; justify-content: space-between; font-size: var(--fs-sm)">
          <span>{{ p.name }}</span>
          <span>{{ fmtHM(p.seconds) }}</span>
        </div>
        <div class="budget-bar" style="margin-top: 4px">
          <div class="budget-fill" :style="{ width: `${totalShare(p.seconds)}%`, background: 'var(--accent)' }" />
        </div>
      </div>

      <h3 class="section-title" style="margin-top: 24px">Quick start</h3>
      <div style="display: flex; flex-direction: column; gap: 6px">
        <button
          v-for="p in quick_start" :key="p.id"
          class="btn ghost"
          style="justify-content: flex-start; padding: 6px 8px"
          @click="startProject(p.id)"
        >
          <span class="proj-glyph" :class="glyphClass(p.id)" style="width: 12px; height: 12px; font-size: 8px">{{ p.code[0] }}</span>
          <span style="font-size: var(--fs-sm)">{{ p.name }}</span>
        </button>
      </div>

      <h3 class="section-title" style="margin-top: 24px">Shortcuts</h3>
      <div style="display: grid; grid-template-columns: 1fr auto; gap: 6px 12px; font-size: var(--fs-xs); color: var(--ink-3)">
        <span>Start / stop timer</span><span class="kbd">space</span>
        <span>New entry</span><span class="kbd">n</span>
        <span>Jump palette</span><span class="kbd">⌘K</span>
        <span>Projects / Clients / Invoices</span><span class="kbd">g</span>
      </div>
    </aside>
  </div>
</template>

<style scoped>
.manual-entry {
  border: 1px solid var(--border-strong);
  border-radius: 2px;
  background: var(--paper);
  padding: 18px 20px 16px;
  margin: 12px 0 4px;
}
.manual-entry__title {
  font-size: var(--fs-xs);
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-3);
  margin-bottom: 16px;
}
.me-grid { display: grid; gap: 16px; }
.me-grid--main { grid-template-columns: minmax(0, 1.3fr) minmax(0, 2fr); }
.me-grid--time {
  grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
  align-items: end;
  margin-top: 16px;
}
/* Live duration readout — echoes the big tabular timer numerals. */
.me-dur {
  min-height: var(--row-h);
  display: flex;
  align-items: center;
  justify-content: flex-end;
  font-size: var(--fs-lg);
  font-weight: 600;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.01em;
  white-space: nowrap;
}
.manual-entry__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}
.billable-toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: var(--fs-sm);
  color: var(--ink-2);
  cursor: pointer;
}
.billable-toggle input {
  accent-color: var(--accent);
  width: 15px;
  height: 15px;
}
.manual-entry__err {
  margin-top: 12px;
  color: var(--red);
  font-size: var(--fs-sm);
}
@media (max-width: 760px) {
  .me-grid--main { grid-template-columns: 1fr; }
  .me-grid--time { grid-template-columns: 1fr 1fr; }
  .me-dur { justify-content: flex-start; }
}
</style>
