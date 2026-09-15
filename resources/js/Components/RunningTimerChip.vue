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
    <span>{{ running.project.name }}</span>
    <b>{{ fmtHMS(elapsedSeconds) }}</b>
    <button class="timer-stop" title="Stop" @click="onStop" />
  </Link>
  <Link v-else href="/timer" class="running-timer idle" title="No timer running">idle</Link>
</template>
