<script setup>
import { computed } from 'vue';

const props = defineProps({
  spent:  { type: Number, required: true },
  budget: { type: Number, required: true },
  unit:   { type: String, default: 'h' }, // 'h' or '€'
});

const pct = computed(() => props.budget ? Math.round((props.spent / props.budget) * 100) : 0);
const band = computed(() => pct.value > 100 ? 'over' : pct.value >= 85 ? 'warn' : 'ok');
const width = computed(() => Math.min(100, pct.value));

function fmt(v) {
  return props.unit === 'h'
    ? `${v.toFixed(1)}h`
    : `€${Math.round(v).toLocaleString('en-US')}`;
}
</script>

<template>
  <div class="budget-cell">
    <div class="label">
      <span>
        {{ fmt(spent) }}
        <span class="ascii-dot">/</span>
        <span class="muted">{{ fmt(budget) }}</span>
      </span>
      <span class="pct" :class="band">{{ pct }}%</span>
    </div>
    <div class="budget-bar">
      <div class="budget-fill" :class="band" :style="{ width: `${width}%` }" />
      <div
        v-if="band === 'over'"
        class="budget-fill over"
        :style="{ width: `${Math.min(100, pct - 100)}%`, left: 0, opacity: 0.4 }"
      />
    </div>
  </div>
</template>
