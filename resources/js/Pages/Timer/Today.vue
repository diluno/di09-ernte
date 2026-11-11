<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import TimerHero from '@/Components/TimerHero.vue';
import EntryRow from '@/Components/EntryRow.vue';
import TimeEntryForm from '@/Components/TimeEntryForm.vue';
import { fmtMoney } from '@/formatters/money.js';
import { fmtDate, fmtWeekday, parseDate, toIsoDate, todayIso } from '@/formatters/date.js';
import { fmtClock } from '@/formatters/duration.js';
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

// HH:MM is reserved for the live clock and this page's day totals.
const fmtHM = fmtClock;

// The viewed day (parseDate anchors a bare date on local midnight): "Wed 11 Nov 2026".
const viewedDateLabel = computed(() => `${fmtWeekday(props.date).slice(0, 3)} ${fmtDate(props.date)}`);
const headingLabel = computed(() => props.is_today ? 'Today' : fmtWeekday(props.date));

function goToDate(value) {
  if (!value) return;
  router.get('/timer', { date: value }, { preserveScroll: true, preserveState: false });
}
function shiftDay(delta) {
  const d = parseDate(props.date);
  d.setDate(d.getDate() + delta);
  goToDate(toIsoDate(d));
}
function goToday() {
  goToDate(todayIso());
}

function startProject(projectId) {
  router.post('/timer/start', { project_id: projectId }, { preserveScroll: true });
}

const totalShare = (sec) => props.totals.total_seconds ? (sec / props.totals.total_seconds) * 100 : 0;

// ── Manual / edit entry form (Components/TimeEntryForm.vue) ──
const showManual = ref(false);
const editing = ref(null);   // the entry being edited, or null for a new one
const formWrap = ref(null);
const page = usePage();

if (page.url.includes('manual=1')) {
  showManual.value = true;
}

function openManualEntry() {
  showManual.value = true;
}

onMounted(() => {
  window.addEventListener('ernte:open-manual-entry', openManualEntry);

  const query = new URLSearchParams(page.url.split('?')[1] ?? '');
  const requestedEditId = Number(query.get('edit'));
  const requestedEntry = props.entries.find((entry) => entry.id === requestedEditId);
  if (requestedEntry) startEdit(requestedEntry);
});
onUnmounted(() => window.removeEventListener('ernte:open-manual-entry', openManualEntry));

function startEdit(entry) {
  editing.value = entry;
  showManual.value = true;
  // Scroll the page region to the top: the form is the first thing in the main
  // column, right under the page head.
  nextTick(() => formWrap.value?.closest('.content')?.scrollTo({ top: 0, behavior: 'smooth' }));
}

function deleteEntry(entry) {
  if (window.confirm('Delete this entry?')) {
    router.delete(`/entries/${entry.id}`, { preserveScroll: true });
  }
}

function closeManual() {
  showManual.value = false;
  editing.value = null;
}
</script>

<template>
  <Head title="Today" />

  <div class="page-head">
    <div>
      <div class="crumb timer-crumb">
        <span>Timer</span><span>·</span><span>{{ viewedDateLabel }}</span><span>·</span><span>{{ fmtHM(totals.total_seconds) }} logged</span>
      </div>
      <h1 class="page-title">{{ headingLabel }}</h1>
    </div>
    <div class="page-actions">
      <div class="day-nav" role="group" aria-label="Day">
        <button type="button" class="btn icon" title="Previous day" aria-label="Previous day" @click="shiftDay(-1)"><Icon name="arrow-left" /></button>
        <input type="date" class="input day-nav__date" aria-label="Go to date" :value="date" @change="goToDate($event.target.value)" />
        <button type="button" class="btn icon" title="Next day" aria-label="Next day" @click="shiftDay(1)"><Icon name="arrow-right" /></button>
      </div>
      <button v-if="!is_today" type="button" class="btn" @click="goToday">Today</button>
      <button type="button" class="btn" :aria-expanded="showManual" @click="showManual ? closeManual() : (showManual = true)">
        {{ showManual ? '× Close form' : '+ Manual entry' }}
      </button>
    </div>
  </div>

  <div class="detail-grid timer-grid">
    <div class="detail-main timer-main">
      <div v-if="showManual" ref="formWrap">
        <TimeEntryForm
          :key="editing ? `edit-${editing.id}` : 'new'"
          :projects="projects"
          :date="date"
          :entry="editing"
          @saved="closeManual"
          @cancel="closeManual"
        />
      </div>

      <TimerHero v-if="is_today" :quiet="showManual" />

      <section>
        <h2 class="section-title">{{ is_today ? "Today's entries" : 'Entries' }} · {{ entries.length }}</h2>
        <EntryRow v-for="(e, i) in entries" :key="e.id" :entry="e" :color-index="i" @edit="startEdit" @delete="deleteEntry" />
        <div v-if="entries.length === 0" class="side-row is-muted">{{ is_today ? 'No entries today yet.' : 'No entries this day.' }}</div>
      </section>
    </div>

    <aside class="detail-side timer-side">
      <section>
        <h3 class="section-title">{{ is_today ? 'Today summary' : 'Day summary' }}</h3>
        <div class="side-row split is-total"><span>Total</span><span>{{ fmtHM(totals.total_seconds) }}</span></div>
        <div class="side-row split"><span>Billable</span><span>{{ fmtHM(totals.billable_seconds) }}</span></div>
        <div class="side-row split"><span>Earnings</span><span>{{ fmtMoney(totals.earnings_amount, { currency: true }) }}</span></div>
      </section>

      <section v-if="by_project.length">
        <h3 class="section-title">By project</h3>
        <div v-for="p in by_project" :key="p.project_id" class="side-row">
          <div class="timer-proj">
            <span class="cell-trunc" :title="p.name">{{ p.name }}</span>
            <span class="mono">{{ fmtHM(p.seconds) }}</span>
          </div>
          <div class="budget-bar timer-share" aria-hidden="true">
            <div class="budget-fill" :style="{ width: `${totalShare(p.seconds)}%` }" />
          </div>
        </div>
      </section>

      <section v-if="quick_start.length">
        <h3 class="section-title">Quick start</h3>
        <button
          v-for="p in quick_start" :key="p.id"
          type="button"
          class="side-row timer-quick"
          :aria-label="`Start timer on ${p.name}`"
          @click="startProject(p.id)"
        >
          <span class="proj-glyph" :class="glyphClass(p.id)" aria-hidden="true">{{ p.code[0] }}</span>
          <span class="cell-trunc">{{ p.name }}</span>
          <span class="timer-quick__go" aria-hidden="true">▶ start</span>
        </button>
      </section>

      <section>
        <h3 class="section-title">Shortcuts</h3>
        <div class="side-row split"><span>Start / stop timer</span><span class="kbd">space</span></div>
        <div class="side-row split"><span>New entry</span><span class="kbd">n</span></div>
        <div class="side-row split"><span>Jump palette</span><span class="kbd">⌘K</span></div>
        <div class="side-row split"><span>Projects / Clients / Invoices</span><span class="kbd">g</span></div>
      </section>
    </aside>
  </div>
</template>
