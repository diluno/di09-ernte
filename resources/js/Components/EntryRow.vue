<script setup>
defineProps({
  entry: { type: Object, required: true },
  colorIndex: { type: Number, default: 0 },
});

const COLORS = ['#2d4a3a', '#c97b3c', '#b8941f', '#1a1a1a', '#7a8c5c', '#b54834'];

function fmtTime(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
}
function fmtHM(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}
</script>

<template>
  <div class="entry-row">
    <div class="bar-color" :style="{ background: COLORS[colorIndex % COLORS.length] }" />
    <div class="desc">
      {{ entry.description || entry.task_name || '—' }}
      <span v-if="entry.task_name && entry.description !== entry.task_name" class="sub">{{ entry.task_name }}</span>
    </div>
    <div class="time">
      {{ fmtTime(entry.started_at) }} –
      <span v-if="entry.running" style="color: var(--rust)">now</span>
      <span v-else>{{ fmtTime(entry.ended_at) }}</span>
    </div>
    <div class="dur">{{ fmtHM(entry.duration_seconds) }}</div>
    <div class="billable" :class="{ no: !entry.billable }">{{ entry.billable ? 'billable' : '—' }}</div>
  </div>
</template>
