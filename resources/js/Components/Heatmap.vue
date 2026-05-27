<script setup>
import { computed } from 'vue';

const props = defineProps({
  cells: { type: Array, default: () => Array(60).fill(0) }, // 60 numbers, oldest → newest
});

function level(v) {
  if (v >= 4) return 'l4';
  if (v >= 2.5) return 'l3';
  if (v >= 1) return 'l2';
  if (v > 0) return 'l1';
  return '';
}

const filled = computed(() => {
  const out = Array(60).fill(0);
  for (let i = 0; i < Math.min(props.cells.length, 60); i++) out[i] = props.cells[i];
  return out;
});
</script>

<template>
  <div>
    <div class="heat">
      <div
        v-for="(v, i) in filled" :key="i"
        class="sq"
        :class="level(v)"
        :title="`${v.toFixed(1)}h`"
      />
    </div>
    <div style="font-size: var(--fs-xs); color: var(--ink-4); margin-top: 8px; display: flex; justify-content: space-between">
      <span>12 weeks ago</span><span>today</span>
    </div>
  </div>
</template>
