<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
  year:    { type: Number, required: true },
  minYear: { type: Number, required: true },
  maxYear: { type: Number, required: true },
  months:  { type: Array,  required: true }, // [{ label, open, paid }]
});
const emit = defineEmits(['update:year']);

const BOX = 56; // px height of the bar box

// Round an axis max up to a "nice" value so bars scale against a round number.
function niceScale(value, targetLines = 5) {
  if (value <= 0) return { max: 1000, step: 250 };
  const raw = value / targetLines;
  const pow = Math.pow(10, Math.floor(Math.log10(raw)));
  let step = 10 * pow;
  for (const c of [1, 2, 2.5, 5]) {
    if (c * pow >= raw) { step = c * pow; break; }
  }
  return { max: Math.ceil(value / step) * step, step };
}

const maxTotal = computed(() => Math.max(0, ...props.months.map((m) => m.open + m.paid)));
const scale = computed(() => niceScale(maxTotal.value));

const fmt = (v) => v ? Math.round(v).toLocaleString('de-CH') : '—';

const now = new Date();
const currentIdx = computed(() => props.year === now.getFullYear() ? now.getMonth() : -1);

const cells = computed(() => props.months.map((m, i) => ({
  label: m.label,
  openPx: Math.round((m.open / scale.value.max) * BOX),
  paidPx: Math.round((m.paid / scale.value.max) * BOX),
  total: fmt(m.open + m.paid),
  title: `${m.label} ${props.year}: paid ${fmt(m.paid)} · open ${fmt(m.open)}`,
  current: i === currentIdx.value,
})));

const yearTotal = computed(() => fmt(props.months.reduce((a, m) => a + m.open + m.paid, 0)));

const canPrev = computed(() => props.year > props.minYear);
const canNext = computed(() => props.year < props.maxYear);
function prev() { if (canPrev.value) emit('update:year', props.year - 1); }
function next() { if (canNext.value) emit('update:year', props.year + 1); }
</script>

<template>
  <section class="ibc" role="img" :aria-label="`Monthly invoiced amounts for ${year}`">
    <div v-for="c in cells" :key="c.label" class="ibc__month" :class="{ 'is-current': c.current }" :title="c.title">
      <span class="ibc__label">{{ c.label }}</span>
      <div class="ibc__bars">
        <div v-if="c.openPx > 0" class="ibc__bar ibc__bar--open" :style="{ height: c.openPx + 'px' }" />
        <div v-if="c.paidPx > 0" class="ibc__bar ibc__bar--paid" :style="{ height: c.paidPx + 'px' }" />
      </div>
      <span class="ibc__total">{{ c.total }}</span>
    </div>

    <div class="ibc__legend">
      <div class="ibc__nav">
        <button class="btn sm" :disabled="!canPrev" aria-label="Previous year" @click="prev"><Icon name="arrow-left" /></button>
        <span class="ibc__year">{{ year }}</span>
        <button class="btn sm" :disabled="!canNext" aria-label="Next year" @click="next"><Icon name="arrow-right" /></button>
      </div>
      <span class="ibc__key"><span class="ibc__sw ibc__sw--paid" />Paid</span>
      <span class="ibc__key"><span class="ibc__sw ibc__sw--open" />Open</span>
      <span class="ibc__sum">Σ {{ yearTotal }}</span>
    </div>
  </section>
</template>

<style scoped>
.ibc {
  display: grid;
  grid-template-columns: repeat(12, 1fr) 120px;
  padding: 20px 40px 18px;
  border-bottom: 1px solid var(--border);
  background: var(--paper);
}
.ibc__month {
  border-left: 1px solid var(--border);
  padding-left: 10px;
  display: flex; flex-direction: column; gap: 8px;
  transition: background .12s;
}
.ibc__month:hover { background: var(--paper-2); }
.ibc__month.is-current { background: var(--paper-3); }
.ibc__month.is-current:hover { background: var(--paper-2); }
.ibc__label {
  font-size: 11px;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--ink-3);
}
.ibc__month.is-current .ibc__label { color: var(--ink); }
.ibc__bars {
  display: flex; flex-direction: column; justify-content: flex-end; gap: 2px;
  height: 56px;
}
.ibc__bar { width: 22px; }
.ibc__bar--open { background: var(--red); }
.ibc__bar--paid { background: var(--ink); }
.ibc__total { font-family: var(--font-mono); font-size: 12px; color: var(--ink); }

.ibc__legend {
  border-left: 1px solid var(--rule);
  padding-left: 14px;
  display: flex; flex-direction: column; justify-content: flex-end; gap: 8px;
  font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-3);
}
.ibc__nav { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.ibc__nav .btn { padding: 3px 6px; }
.ibc__year { font-family: var(--font-mono); font-size: 12px; color: var(--ink); letter-spacing: 0; }
.ibc__key { display: flex; align-items: center; gap: 8px; }
.ibc__sw { width: 10px; height: 10px; display: inline-block; }
.ibc__sw--paid { background: var(--ink); }
.ibc__sw--open { background: var(--red); }
.ibc__sum { font-family: var(--font-mono); font-size: 12px; color: var(--ink); letter-spacing: 0; text-transform: none; margin-top: 4px; }
</style>
