<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import { fmtMoney } from '@/formatters/money.js';

const props = defineProps({
  year:    { type: Number, required: true },
  minYear: { type: Number, required: true },
  maxYear: { type: Number, required: true },
  months:  { type: Array,  required: true }, // [{ label, open, paid }]
});
const emit = defineEmits(['update:year']);

// Each month is a small field of blocks, seven to a row, filled from the bottom:
// paid first, open on top. One block is a round amount, chosen so the best month
// of the year still fits the field (7 × 8 blocks).
const COLS = 7;
const ROWS = 8;

function blockUnit(max) {
  for (let pow = 100; ; pow *= 10) {
    for (const c of [1, 2, 5]) {
      if (Math.ceil(max / (c * pow)) <= COLS * ROWS) return c * pow;
    }
  }
}

const maxTotal = computed(() => Math.max(0, ...props.months.map((m) => m.open + m.paid)));
const unit = computed(() => blockUnit(maxTotal.value));
// Any amount shows at least one block; the total is rounded once, so paid + open never drift.
function split(m) {
  const total = m.open + m.paid;
  if (total <= 0) return { paid: 0, open: 0 };
  const all = Math.max(1, Math.round(total / unit.value));
  let open = m.open > 0 ? Math.max(1, Math.round(m.open / unit.value)) : 0;
  open = Math.min(open, m.paid > 0 ? Math.max(all - 1, 0) : all);
  return { paid: m.paid > 0 ? Math.max(all - open, 1) : 0, open };
}

// Monthly aggregates on a chart: whole francs.
const fmt = (v) => v ? fmtMoney(v, { whole: true }) : '—';

const now = new Date();
const currentIdx = computed(() => props.year === now.getFullYear() ? now.getMonth() : -1);

const cells = computed(() => props.months.map((m, i) => ({
  label: m.label,
  ...split(m),
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
      <div class="ibc__field">
        <i v-for="n in c.paid" :key="`p${n}`" class="ibc__block ibc__block--paid" />
        <i v-for="n in c.open" :key="`o${n}`" class="ibc__block ibc__block--open" />
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
      <span class="ibc__unit">1 block = {{ fmtMoney(unit, { whole: true }) }}</span>
      <span class="ibc__sum">Σ {{ yearTotal }}</span>
    </div>
  </section>
</template>

<style scoped>
.ibc {
  display: grid;
  grid-template-columns: repeat(12, 1fr) 132px;
  padding: 20px var(--gutter) 18px;
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
  font-size: 12px;
  color: var(--ink-3);
}
.ibc__month.is-current .ibc__label { color: var(--ink); font-weight: 600; }
/* 7 × 8 blocks of 6px with 1px gaps; wrap-reverse fills rows from the bottom. */
.ibc__field {
  display: flex; flex-wrap: wrap-reverse; align-content: flex-start; gap: 1px;
  width: 48px; height: 55px;
}
.ibc__block { width: 6px; height: 6px; }
.ibc__block--open { background: var(--amber-fill); }
.ibc__block--paid { background: var(--green); }
.ibc__total { font-variant-numeric: tabular-nums; font-size: 12px; color: var(--ink); }

.ibc__legend {
  border-left: 1px solid var(--border);
  padding-left: 14px;
  display: flex; flex-direction: column; justify-content: flex-end; gap: 8px;
  font-size: 12px; color: var(--ink-2);
}
.ibc__nav { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.ibc__nav .btn { padding: 3px 6px; }
.ibc__year { font-variant-numeric: tabular-nums; font-size: 12px; color: var(--ink); letter-spacing: 0; }
.ibc__key { display: flex; align-items: center; gap: 8px; }
.ibc__sw { width: 6px; height: 6px; display: inline-block; }
.ibc__sw--paid { background: var(--green); }
.ibc__sw--open { background: var(--amber-fill); }
.ibc__unit { color: var(--ink-3); }
.ibc__sum { font-variant-numeric: tabular-nums; font-size: 12px; color: var(--ink); letter-spacing: 0; text-transform: none; margin-top: 4px; }

/* Below the desktop reference width the months keep a legible minimum and the
   strip scrolls sideways instead of overlapping its totals. */
@media (max-width: 1279px) {
  .ibc { grid-template-columns: repeat(12, minmax(62px, 1fr)) 132px; overflow-x: auto; flex-shrink: 0; }
}
</style>
