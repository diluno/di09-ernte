<script setup>
defineProps({
  hours:  { type: Array,  required: true },  // 7 numbers, Mon..Sun
  target: { type: Number, default: 40 },
});

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const todayIdx = (new Date().getDay() + 6) % 7; // JS: Sun=0..Sat=6 → ISO Mon=0..Sun=6

// Past days ink, today red, empty/future days no fill. Full height = 10h.
function bar(h, i) {
  if (!h) return { flex: 1 };
  return {
    flex: 1,
    height: `${Math.min(100, Math.max(4, (h / 10) * 100))}%`,
    background: i === todayIdx ? 'var(--red)' : 'var(--ink)',
  };
}
</script>

<template>
  <div class="week-bars">
    <div v-for="(h, i) in hours" :key="i" :title="`${DAYS[i]}: ${h}h`" :style="bar(h, i)" />
  </div>
</template>
