<script setup>
import { Link } from '@inertiajs/vue3';
import { useTimer, fmtHMS } from '@/composables/useTimer.js';

const { running, elapsedSeconds, stop } = useTimer();

function onStop(e) {
  e.preventDefault();
  e.stopPropagation();
  stop();
}
</script>

<template>
  <Link v-if="running" href="/timer" class="running-timer" title="Open timer">
    <span class="pulse" />
    <span style="opacity: 0.8; font-size: var(--fs-xs)">{{ running.project.name }}</span>
    <span style="font-weight: 700">{{ fmtHMS(elapsedSeconds) }}</span>
    <button class="timer-stop" title="Stop" @click="onStop" />
  </Link>
  <div v-else class="running-timer idle" title="No timer running">
    <span class="pulse idle" />
    <span style="opacity: 0.6; font-size: var(--fs-xs)">idle</span>
  </div>
</template>
