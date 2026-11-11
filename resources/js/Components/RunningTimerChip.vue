<script setup>
import { Link } from '@inertiajs/vue3';
import { useTimer } from '@/composables/useTimer.js';
import { fmtClockSeconds } from '@/formatters/duration.js';

const { running, elapsedSeconds, stop, pending } = useTimer();
</script>

<template>
  <!-- The stop button is a sibling of the link, not nested inside it. -->
  <div v-if="running" class="running-timer">
    <Link href="/timer" class="running-timer__link" title="Open timer" :aria-label="`Open timer — ${running.project.name} running`">
      <span class="pulse" />
      <span class="running-timer__name">{{ running.project.name }}</span>
      <b>{{ fmtClockSeconds(elapsedSeconds) }}</b>
    </Link>
    <button type="button" class="timer-stop" title="Stop timer" aria-label="Stop timer" :disabled="pending" @click="stop" />
  </div>
  <Link v-else href="/timer" class="running-timer idle" title="No timer running">idle</Link>
</template>
