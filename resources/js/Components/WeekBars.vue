<script setup>
defineProps({
  hours:  { type: Array,  required: true },  // 7 numbers, Mon..Sun
  target: { type: Number, default: 40 },
});

const DAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const todayIdx = (new Date().getDay() + 6) % 7; // JS: Sun=0..Sat=6 → ISO Mon=0..Sun=6
</script>

<template>
  <div>
    <div style="display: flex; gap: 3px; align-items: flex-end; height: 28px">
      <div
        v-for="(h, i) in hours" :key="i"
        :title="`${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][i]}: ${h}h`"
        :style="{
          flex: 1,
          height: `${Math.max(2, (h / 10) * 100)}%`,
          background: i === todayIdx ? 'var(--accent)' : h === 0 ? 'var(--bg-3)' : 'var(--ink-3)',
          opacity: i === 5 || i === 6 ? 0.5 : 1,
        }"
      />
    </div>
    <div style="display: flex; justify-content: space-between; font-size: 9px; color: var(--ink-4); margin-top: 4px; letter-spacing: .05em">
      <span v-for="(d, i) in DAYS" :key="i">{{ d }}</span>
    </div>
  </div>
</template>
