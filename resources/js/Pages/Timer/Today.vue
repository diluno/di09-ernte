<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TimerHero from '@/Components/TimerHero.vue';
import EntryRow from '@/Components/EntryRow.vue';
import { formatChf } from '@/formatters/money.js';
import { parseDuration, formatDuration } from '@/formatters/duration.js';
import { glyphClass } from '@/formatters/glyph.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  entries:     { type: Array,  required: true },
  totals:      { type: Object, required: true },
  by_project:  { type: Array,  required: true },
  quick_start: { type: Array,  required: true },
  projects:    { type: Array,  default: () => [] },
  date:        { type: String, required: true },  // 'YYYY-MM-DD' — the day being viewed
  is_today:    { type: Boolean, default: true },
});

function fmtHM(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}

// The viewed day. Anchor on local midnight ('T00:00:00') so the date doesn't slip
// across timezones when parsed.
const viewedDate = computed(() => new Date(`${props.date}T00:00:00`));
const viewedDateLabel = computed(() => viewedDate.value.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }));
const headingLabel = computed(() => props.is_today ? 'Today' : viewedDate.value.toLocaleDateString([], { weekday: 'long' }));

function goToDate(value) {
  router.get('/timer', { date: value }, { preserveScroll: true, preserveState: false });
}
function shiftDay(delta) {
  const d = new Date(`${props.date}T00:00:00`);
  d.setDate(d.getDate() + delta);
  goToDate(dateStr(d));
}
function goToday() {
  goToDate(dateStr(new Date()));
}

function startProject(projectId) {
  router.post('/timer/start', { project_id: projectId }, { preserveScroll: true });
}

const totalShare = (sec) => props.totals.total_seconds ? (sec / props.totals.total_seconds) * 100 : 0;

const showManual = ref(false);
const editingId = ref(null);
const originalProjectId = ref(null);
const originalStartedAt = ref(null);
const durationText = ref('1:00');
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

const manualForm = useForm({
  project_id: '',
  description: '',
  date: props.date,
  billable: true,
});

const durationMinutes = computed(() => parseDuration(durationText.value));
const durationPreview = computed(() =>
  durationMinutes.value ? `= ${formatDuration(durationMinutes.value)}` : 'e.g. 1:30, 90m, 1.5');

function submitManual() {
  const mins = durationMinutes.value;
  if (!mins) {
    manualForm.setError('duration', 'Enter a valid duration, e.g. 1:30, 90m, or 1.5');
    return;
  }
  manualForm.clearErrors();

  const editing = editingId.value;
  manualForm.transform((data) => {
    // Create: anchor the start on the chosen date at the current time-of-day.
    // Edit: keep the entry's original start so it holds its place in the day.
    const start = (editing && originalStartedAt.value)
      ? new Date(originalStartedAt.value)
      : new Date(`${data.date}T${timeStr(new Date())}`);
    const end = new Date(start.getTime() + mins * 60000);

    const payload = {
      project_id: data.project_id,
      description: data.description,
      billable: data.billable,
      started_at: start.toISOString(),
      ended_at: end.toISOString(),
    };
    if (editing && data.project_id !== originalProjectId.value) {
      payload.task_id = null;
    }
    return payload;
  });

  const opts = {
    onSuccess: () => {
      showManual.value = false;
      editingId.value = null;
      manualForm.reset();
      durationText.value = '1:00';
    },
    preserveScroll: true,
  };

  if (editing) {
    manualForm.patch(`/entries/${editing}`, opts);
  } else {
    manualForm.post('/entries', opts);
  }
}

function startEdit(entry) {
  manualForm.clearErrors();
  manualForm.project_id = entry.project.id;
  manualForm.description = entry.description || '';
  manualForm.date = dateStr(new Date(entry.started_at));
  manualForm.billable = entry.billable;
  durationText.value = formatDuration(Math.round(entry.duration_seconds / 60));
  editingId.value = entry.id;
  originalProjectId.value = entry.project.id;
  originalStartedAt.value = entry.started_at;
  showManual.value = true;
  // Scroll the page region fully to the top: the form sits just under the sticky
  // page-head, so scrollIntoView would tuck it behind that header. Scrolling the
  // .content scroller to 0 reveals the header and the whole form.
  nextTick(() => formEl.value?.closest('.content')?.scrollTo({ top: 0, behavior: 'smooth' }));
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
  durationText.value = '1:00';
}
</script>

<template>
  <Head title="Today" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / timer</div>
      <h1 class="page-title">
        {{ headingLabel }}
        <span class="meta">{{ viewedDateLabel }}<span class="ascii-dot">·</span>{{ fmtHM(totals.total_seconds) }} logged</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px; align-items: center">
      <div class="day-nav">
        <button class="btn ghost day-nav__arrow" title="Previous day" aria-label="Previous day" @click="shiftDay(-1)">‹</button>
        <input type="date" class="input day-nav__date" :value="date" @change="goToDate($event.target.value)" />
        <button class="btn ghost day-nav__arrow" title="Next day" aria-label="Next day" @click="shiftDay(1)">›</button>
        <button v-if="!is_today" class="btn ghost" @click="goToday">Today</button>
      </div>
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
        <span>Duration</span>
        <input v-model="durationText" placeholder="1:30" class="input" />
        <span class="me-dur-hint" :class="{ ok: durationMinutes }">{{ durationPreview }}</span>
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
      <TimerHero v-if="is_today" />

      <div class="divider-row">{{ is_today ? "Today's entries" : 'Entries' }} · {{ entries.length }}</div>
      <EntryRow v-for="(e, i) in entries" :key="e.id" :entry="e" :color-index="i" @edit="startEdit" @delete="deleteEntry" />
      <div v-if="entries.length === 0" class="muted" style="padding: 12px">{{ is_today ? 'No entries today yet' : 'No entries this day' }}</div>
    </div>

    <aside>
      <h3 class="section-title">{{ is_today ? 'Today summary' : 'Day summary' }}</h3>
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
.day-nav {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.day-nav__arrow {
  padding: 6px 10px;
  font-size: var(--fs-md);
  line-height: 1;
}
.day-nav__date {
  width: auto;
  padding: 6px 8px;
  font-size: var(--fs-sm);
}
.me-grid { display: grid; gap: 16px; }
.me-grid--main { grid-template-columns: minmax(0, 1.3fr) minmax(0, 2fr); }
.me-grid--time {
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  align-items: start;
  margin-top: 16px;
}
.me-dur-hint {
  display: block;
  margin-top: 4px;
  font-size: var(--fs-xs);
  color: var(--ink-4);
}
.me-dur-hint.ok { color: var(--ink-3); }
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
}
</style>
