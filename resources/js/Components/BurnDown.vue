<script setup>
import { computed } from 'vue';

const props = defineProps({
  spent:  { type: Number, required: true },
  budget: { type: Number, required: true },
  days:   { type: Number, default: 60 },
});

const W = 720, H = 140, PAD = 20;

function xs(i) { return PAD + (i / (props.days - 1)) * (W - PAD * 2); }
function ys(v) { return PAD + (1 - v / Math.max(props.budget, 1)) * (H - PAD * 2); }

const idealPath = computed(() => {
  const pts = Array.from({ length: props.days }).map((_, i) => props.budget - (props.budget / props.days) * i);
  return pts.map((v, i) => `${i === 0 ? 'M' : 'L'}${xs(i).toFixed(1)},${ys(v).toFixed(1)}`).join(' ');
});

const actualSeries = computed(() => {
  const burnFactor = props.budget ? props.spent / props.budget : 0;
  return Array.from({ length: props.days }).map((_, i) => {
    const progress = i / props.days;
    return Math.max(0, props.budget - props.budget * progress * burnFactor * (1 + Math.sin(i * 0.4) * 0.05));
  });
});

const actualPath = computed(() =>
  actualSeries.value.map((v, i) => `${i === 0 ? 'M' : 'L'}${xs(i).toFixed(1)},${ys(v).toFixed(1)}`).join(' ')
);

const todayIdx = computed(() => Math.min(
  props.days - 1,
  Math.floor(actualSeries.value.length * (props.budget ? props.spent / props.budget : 0) * 1.05)
));

const W_VB = W, H_VB = H, PAD_VB = PAD;
</script>

<template>
  <div class="burndown" style="position: relative">
    <svg :viewBox="`0 0 ${W_VB} ${H_VB}`" width="100%" :height="H_VB" preserveAspectRatio="none">
      <line v-for="p in [0.25, 0.5, 0.75]" :key="p"
        :x1="PAD_VB" :x2="W_VB - PAD_VB"
        :y1="PAD_VB + p * (H_VB - PAD_VB * 2)" :y2="PAD_VB + p * (H_VB - PAD_VB * 2)"
        stroke="var(--border)" stroke-dasharray="2 4" />
      <line :x1="PAD_VB" :x2="W_VB - PAD_VB" :y1="H_VB - PAD_VB" :y2="H_VB - PAD_VB" stroke="var(--border)" />
      <line :x1="PAD_VB" :x2="PAD_VB" :y1="PAD_VB" :y2="H_VB - PAD_VB" stroke="var(--border)" />
      <path :d="idealPath" fill="none" stroke="var(--ink-4)" stroke-width="1" stroke-dasharray="4 4" />
      <path :d="actualPath" fill="none" stroke="var(--forest)" stroke-width="1.5" />
      <template v-if="todayIdx < days">
        <line :x1="xs(todayIdx)" :x2="xs(todayIdx)" :y1="PAD_VB" :y2="H_VB - PAD_VB" stroke="var(--rust)" stroke-dasharray="2 2" />
        <circle :cx="xs(todayIdx)" :cy="ys(actualSeries[todayIdx])" r="3" fill="var(--rust)" />
      </template>
    </svg>
    <div style="position: absolute; top: 6px; left: 8px; font-size: var(--fs-xs); color: var(--ink-4); display: flex; gap: 14px">
      <span><span style="display: inline-block; width: 10px; height: 1.5px; background: var(--forest); vertical-align: middle; margin-right: 4px" />actual</span>
      <span><span style="display: inline-block; width: 10px; border-top: 1px dashed var(--ink-4); vertical-align: middle; margin-right: 4px" />ideal</span>
      <span><span style="display: inline-block; width: 6px; height: 6px; background: var(--rust); border-radius: 50%; vertical-align: middle; margin-right: 4px" />today</span>
    </div>
  </div>
</template>
