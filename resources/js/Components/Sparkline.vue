<script setup>
import { computed } from 'vue';

const props = defineProps({
  data:  { type: Array,  required: true },     // numbers
  w:     { type: Number, default: 90 },
  h:     { type: Number, default: 22 },
  color: { type: String, default: 'var(--ink-3)' },
});

const path = computed(() => {
  const max = Math.max(...props.data, 1);
  const stepX = props.w / Math.max(props.data.length - 1, 1);
  return props.data.map((v, i) => {
    const x = (i * stepX).toFixed(1);
    const y = (props.h - (v / max) * (props.h - 2) - 1).toFixed(1);
    return `${i === 0 ? 'M' : 'L'}${x},${y}`;
  }).join(' ');
});

const area = computed(() => {
  const max = Math.max(...props.data, 1);
  const stepX = props.w / Math.max(props.data.length - 1, 1);
  const pts = props.data.map((v, i) => {
    const x = (i * stepX).toFixed(1);
    const y = (props.h - (v / max) * (props.h - 2) - 1).toFixed(1);
    return `L${x},${y}`;
  }).join(' ');
  return `M0,${props.h} ${pts} L${props.w},${props.h} Z`;
});

const areaStyle = computed(() => ({ fill: `color-mix(in oklch, ${props.color} 14%, transparent)` }));
const lineStyle = computed(() => ({ stroke: props.color }));
</script>

<template>
  <svg class="spark" :viewBox="`0 0 ${w} ${h}`" :width="w" :height="h" preserveAspectRatio="none">
    <path class="area" :d="area" :style="areaStyle" />
    <path :d="path" :style="lineStyle" fill="none" />
  </svg>
</template>
