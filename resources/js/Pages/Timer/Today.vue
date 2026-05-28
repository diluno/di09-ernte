<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
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
const page = usePage();

if (page.url.includes('manual=1')) {
  showManual.value = true;
}

function openManualEntry() {
  showManual.value = true;
}

onMounted(() => window.addEventListener('ernte:open-manual-entry', openManualEntry));
onUnmounted(() => window.removeEventListener('ernte:open-manual-entry', openManualEntry));

function isoLocal(d) {
  // 'YYYY-MM-DDTHH:MM' suitable for <input type="datetime-local">
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const nowDate = new Date();
const oneHourAgo = new Date(nowDate.getTime() - 60 * 60 * 1000);

const manualForm = useForm({
  project_id: '',
  description: '',
  started_at: isoLocal(oneHourAgo),
  ended_at: isoLocal(nowDate),
  billable: true,
});

function submitManual() {
  manualForm.transform((data) => ({
    ...data,
    started_at: new Date(data.started_at).toISOString(),
    ended_at: new Date(data.ended_at).toISOString(),
  })).post('/entries', {
    onSuccess: () => { showManual.value = false; manualForm.reset(); },
    preserveScroll: true,
  });
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
      <button class="btn" @click="showManual = !showManual">{{ showManual ? '× cancel' : '+ Manual entry' }}</button>
    </div>
  </div>

  <form v-if="showManual" @submit.prevent="submitManual" style="border: 1px solid var(--border-strong); padding: 16px; margin: 12px 0; display: grid; grid-template-columns: 200px 1fr 160px 160px auto auto; gap: 10px; align-items: center">
    <select v-model="manualForm.project_id" required class="select">
      <option value="" disabled>project…</option>
      <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }} ({{ p.code }})</option>
    </select>
    <input v-model="manualForm.description" placeholder="what did you do?" class="input" />
    <input type="datetime-local" v-model="manualForm.started_at" required class="input" />
    <input type="datetime-local" v-model="manualForm.ended_at" required class="input" />
    <label style="display: flex; align-items: center; gap: 4px; font-size: var(--fs-sm)">
      <input type="checkbox" v-model="manualForm.billable" /> billable
    </label>
    <button type="submit" class="btn primary" :disabled="manualForm.processing">save</button>
  </form>
  <div v-if="showManual && Object.keys(manualForm.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-bottom: 8px">
    {{ Object.values(manualForm.errors).join(' · ') }}
  </div>

  <div class="timer-stage">
    <div>
      <TimerHero />

      <div class="divider-row">Today's entries · {{ entries.length }}</div>
      <EntryRow v-for="(e, i) in entries" :key="e.id" :entry="e" :color-index="i" />
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
