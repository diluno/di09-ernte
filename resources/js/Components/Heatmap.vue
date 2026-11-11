<script setup>
import { computed } from 'vue';
import { fmtHours } from '@/formatters/duration.js';
import { fmtDateShort } from '@/formatters/date.js';

const DAYS = 60;

const props = defineProps({
  cells: { type: Array, default: () => Array(DAYS).fill(0) }, // 60 numbers, oldest → newest (today last)
});

function level(v) {
  if (v >= 4) return 'l4';
  if (v >= 2.5) return 'l3';
  if (v >= 1) return 'l2';
  if (v > 0) return 'l1';
  return '';
}

const days = computed(() => {
  const today = new Date();
  today.setHours(12, 0, 0, 0);
  return Array.from({ length: DAYS }, (_, i) => {
    const date = new Date(today);
    date.setDate(today.getDate() - (DAYS - 1 - i));
    const hours = Number(props.cells[i] ?? 0);
    return { hours, title: `${fmtDateShort(date)} · ${fmtHours(hours)}` };
  });
});

const summary = computed(() => {
  const active = days.value.filter((d) => d.hours > 0);
  const total = active.reduce((a, d) => a + d.hours, 0);
  return active.length
    ? `${fmtHours(total)} on ${active.length} ${active.length === 1 ? 'day' : 'days'} in the last ${DAYS} days`
    : `No time tracked in the last ${DAYS} days`;
});
</script>

<template>
  <figure class="heatmap">
    <div class="heat" role="img" :aria-label="summary">
      <div v-for="(d, i) in days" :key="i" class="sq" :class="level(d.hours)" :title="d.title" />
    </div>
    <figcaption class="heatmap__axis"><span>{{ DAYS }} days ago</span><span>today</span></figcaption>
    <p class="heatmap__sum">{{ summary }}</p>
  </figure>
</template>
