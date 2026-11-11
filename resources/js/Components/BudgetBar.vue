<script setup>
import { computed } from 'vue';
import { fmtMoney } from '@/formatters/money.js';
import { fmtHours } from '@/formatters/duration.js';

const props = defineProps({
  spent:  { type: Number, required: true },
  budget: { type: Number, required: true },
  unit:   { type: String, default: 'h' }, // 'h' or 'CHF'
});

const pct = computed(() => props.budget ? Math.round((props.spent / props.budget) * 100) : 0);
const band = computed(() => pct.value > 100 ? 'over' : pct.value >= 85 ? 'warn' : 'ok');
const width = computed(() => Math.min(100, pct.value));

function fmt(v) {
  // Compact budget aggregate: whole francs.
  return props.unit === 'h' ? fmtHours(v) : fmtMoney(v, { whole: true, currency: true });
}
</script>

<template>
  <div class="budget-cell" :class="`is-${band}`">
    <div class="label">
      <span>{{ fmt(spent) }}<span class="of"> / {{ fmt(budget) }}</span></span>
      <span class="pct" :class="band">{{ pct }}%</span>
    </div>
    <div class="budget-bar" role="meter" :aria-valuenow="pct" aria-valuemin="0" :aria-valuemax="Math.max(100, pct)" :aria-label="`${fmt(spent)} of ${fmt(budget)} used`">
      <div class="budget-fill" :class="band" :style="{ width: `${width}%` }" />
    </div>
  </div>
</template>
