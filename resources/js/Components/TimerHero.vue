<script setup>
import { computed } from 'vue';
import { useTimer } from '@/composables/useTimer.js';
import { fmtMoney } from '@/formatters/money.js';
import { fmtTime } from '@/formatters/date.js';
import { fmtClock } from '@/formatters/duration.js';

defineProps({
  // Another primary action is on screen (the entry form): Stop drops to a secondary button.
  quiet: { type: Boolean, default: false },
});

const { running, elapsedSeconds, stop, discard, pending } = useTimer();

const earnings = computed(() => {
  if (!running.value) return 0;
  const rate = running.value.project.rate_rappen / 100;
  return (elapsedSeconds.value / 3600) * rate;
});

const startedAtLocal = computed(() => {
  if (!running.value) return '';
  return fmtTime(running.value.started_at);
});

const description = computed(() => running.value?.description || running.value?.task?.name || '');
</script>

<template>
  <section v-if="running" class="timer-hero" aria-label="Running timer">
    <div class="timer-hero__head">
      <span class="timer-hero__label"><span class="timer-hero__dot" aria-hidden="true" />Running · {{ running.project.name }}</span>
      <span class="timer-hero__since">since {{ startedAtLocal }}</span>
    </div>
    <div class="timer-hero__desc" :class="{ 'is-empty': !description }">{{ description || 'untitled' }}</div>

    <div class="timer-display" role="timer" aria-live="off">
      {{ fmtClock(elapsedSeconds) }}<span class="ms">:{{ String(elapsedSeconds % 60).padStart(2, '0') }}</span>
    </div>

    <div class="timer-meta">
      <template v-if="running.billable">
        <span>billable · {{ fmtMoney(earnings, { currency: true }) }}</span>
        <span class="ascii-dot">·</span>
        <span>rate {{ fmtMoney(running.project.rate_rappen, { rappen: true, currency: true }) }}/h</span>
      </template>
      <span v-else>non-billable</span>
      <span class="ascii-dot">·</span>
      <span>{{ running.project.code }}</span>
    </div>

    <div class="timer-hero__actions">
      <button type="button" class="btn" :class="{ primary: !quiet }" :disabled="pending" @click="stop">■ Stop</button>
      <button type="button" class="btn ghost" :disabled="pending" @click="discard">Discard</button>
    </div>
  </section>

  <div v-else class="timer-hero is-idle">
    No timer running. Start one from Quick start, or press <span class="kbd">space</span> to pick a project.
  </div>
</template>
