<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TimerHero from '@/Components/TimerHero.vue';
import EntryRow from '@/Components/EntryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  entries:     { type: Array,  required: true },
  totals:      { type: Object, required: true },
  by_project:  { type: Array,  required: true },
  quick_start: { type: Array,  required: true },
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
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">~ / timer</div>
      <h1 class="page-title">
        Today
        <span class="meta">{{ today }}<span class="ascii-dot">·</span>{{ fmtHM(totals.total_seconds) }} logged</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button class="btn" disabled title="Manual entry — wired in EntryController">+ Manual entry</button>
    </div>
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
          <span style="font-size: var(--fs-md); color: var(--ink)">€{{ totals.earnings_amount.toFixed(0) }}</span>
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
          <span class="proj-glyph" :class="p.glyph" style="width: 12px; height: 12px; font-size: 8px">{{ p.code[0] }}</span>
          <span style="font-size: var(--fs-sm)">{{ p.name }}</span>
        </button>
      </div>

      <h3 class="section-title" style="margin-top: 24px">Shortcuts</h3>
      <div style="display: grid; grid-template-columns: 1fr auto; gap: 6px 12px; font-size: var(--fs-xs); color: var(--ink-3)">
        <span>Start / stop timer</span><span class="kbd">space</span>
        <span>New entry</span><span class="kbd">n</span>
        <span class="dim" style="grid-column: span 2; font-size: 10px">(shortcuts ship in Phase 2b)</span>
      </div>
    </aside>
  </div>
</template>
