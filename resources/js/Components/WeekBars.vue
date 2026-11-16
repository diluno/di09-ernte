<script setup>
import { fmtHours } from '@/formatters/duration.js';

defineProps({
  hours:  { type: Array,  required: true },  // 7 numbers, Mon..Sun
  target: { type: Number, default: 40 },
});

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const todayIdx = (new Date().getDay() + 6) % 7; // JS: Sun=0..Sat=6 → ISO Mon=0..Sun=6

// One block per half hour, capped at 10 h (20 blocks, 5 rows). Anything tracked shows at least one.
function blocks(h) {
  if (!h) return 0;
  return Math.min(20, Math.max(1, Math.round(h * 2)));
}
</script>

<template>
  <div class="week-bars">
    <div v-for="(h, i) in hours" :key="i" class="week-day" :class="{ 'is-today': i === todayIdx }" :title="`${DAYS[i]}: ${fmtHours(h)}`">
      <i v-for="n in blocks(h)" :key="n" />
    </div>
  </div>
</template>
