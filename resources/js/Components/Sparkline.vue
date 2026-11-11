<script setup>
import { computed } from 'vue';

const props = defineProps({
  data:  { type: Array,  required: true },     // numbers
  w:     { type: Number, default: 90 },
  h:     { type: Number, default: 22 },
  color: { type: String, default: 'var(--ink-3)' },
  label: { type: String, default: null },      // accessible summary; decorative when null
});

const points = computed(() => {
  const max = Math.max(...props.data, 1);
  const stepX = props.w / Math.max(props.data.length - 1, 1);
  return props.data.map((v, i) => [
    (i * stepX).toFixed(1),
    (props.h - (v / max) * (props.h - 2) - 1).toFixed(1),
  ]);
});

const path = computed(() => points.value.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x},${y}`).join(' '));
const area = computed(() => `M0,${props.h} ${points.value.map(([x, y]) => `L${x},${y}`).join(' ')} L${props.w},${props.h} Z`);
</script>

<template>
  <svg
    class="spark" :viewBox="`0 0 ${w} ${h}`" :width="w" :height="h" preserveAspectRatio="none"
    :role="label ? 'img' : undefined" :aria-label="label || undefined" :aria-hidden="label ? undefined : 'true'"
  >
    <path class="area" :d="area" />
    <path :d="path" :style="{ stroke: color }" fill="none" />
  </svg>
</template>
