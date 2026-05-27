<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useTimer, fmtHMS } from '@/composables/useTimer.js';

const { running, elapsedSeconds, stop, discard } = useTimer();

const earnings = computed(() => {
  if (!running.value) return 0;
  const rate = running.value.project.rate_rappen / 100;
  return ((elapsedSeconds.value / 3600) * rate).toFixed(2);
});

const startedAtLocal = computed(() => {
  if (!running.value) return '';
  return new Date(running.value.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
});
</script>

<template>
  <div class="timer-hero" v-if="running">
    <div style="display: flex; align-items: flex-start; justify-content: space-between">
      <div>
        <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">
          Running · {{ running.project.name }}
        </div>
        <div style="margin-top: 12px; color: var(--ink); font-size: var(--fs-md)">
          {{ running.description || running.task?.name || 'untitled' }}
        </div>
      </div>
      <div style="display: flex; align-items: center; gap: 6px; color: var(--rust); font-size: var(--fs-sm)">
        <span class="pulse" style="width: 6px; height: 6px; border-radius: 50%; background: var(--rust)" />
        started {{ startedAtLocal }}
      </div>
    </div>

    <div class="timer-display" style="margin-top: 18px">
      {{ fmtHMS(elapsedSeconds).slice(0, 5) }}<span class="ms">:{{ fmtHMS(elapsedSeconds).slice(6) }}</span>
    </div>

    <div class="timer-meta" v-if="running.billable">
      <span>billable · €{{ earnings }}</span>
      <span class="ascii-dot">·</span>
      <span>rate €{{ (running.project.rate_rappen / 100).toFixed(0) }}/h</span>
      <span class="ascii-dot">·</span>
      <span>{{ running.project.code }}</span>
    </div>

    <div style="margin-top: 20px; display: flex; gap: 8px">
      <button class="btn primary" style="min-width: 120px" @click="stop">■ stop</button>
      <button class="btn ghost" @click="discard">discard</button>
    </div>
  </div>

  <div v-else class="timer-hero" style="text-align: center; color: var(--ink-3); padding: 40px 0">
    No timer running. Pick a project below to start.
  </div>
</template>
