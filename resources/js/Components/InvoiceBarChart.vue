<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '@/Components/Icon.vue';
import { formatChf } from '@/formatters/money.js';

const props = defineProps({
  year:    { type: Number, required: true },
  minYear: { type: Number, required: true },
  maxYear: { type: Number, required: true },
  months:  { type: Array,  required: true }, // [{ label, open, paid }]
});
const emit = defineEmits(['update:year']);

// Fixed pixel height; the viewBox WIDTH tracks the rendered width (see the
// ResizeObserver below) so the chart re-flows to fill the page without growing
// taller on wide screens. Because the viewBox dimensions equal the on-screen
// pixels, preserveAspectRatio="none" scales 1:1 — no distortion of bars or text.
const H = 230;
const PAD_L = 82, PAD_R = 16, PAD_T = 12, PAD_B = 26;
const plotH = H - PAD_T - PAD_B;
const baseline = H - PAD_B;

const svgRef = ref(null);
const W = ref(1000);
let ro = null;
onMounted(() => {
  if (!svgRef.value) return;
  const measure = () => { W.value = Math.max(360, Math.round(svgRef.value.clientWidth)); };
  measure();
  ro = new ResizeObserver(measure);
  ro.observe(svgRef.value);
});
onBeforeUnmount(() => ro?.disconnect());

const plotW = computed(() => W.value - PAD_L - PAD_R);

// Round an axis max up to a "nice" value giving ~targetLines gridlines.
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

const gridlines = computed(() => {
  const { max, step } = scale.value;
  const out = [];
  for (let v = step; v <= max + 0.5; v += step) {
    out.push({ value: v, y: baseline - (v / max) * plotH });
  }
  return out;
});

const slot = computed(() => plotW.value / 12);
const barW = computed(() => Math.min(46, slot.value * 0.5));

const bars = computed(() => props.months.map((m, i) => {
  const cx = PAD_L + slot.value * i + slot.value / 2;
  const paidH = (m.paid / scale.value.max) * plotH;
  const openH = (m.open / scale.value.max) * plotH;
  return {
    label: m.label,
    cx,
    x: cx - barW.value / 2,
    paid: { y: baseline - paidH, h: paidH },
    open: { y: baseline - paidH - openH, h: openH },
  };
}));

const canPrev = computed(() => props.year > props.minYear);
const canNext = computed(() => props.year < props.maxYear);
function prev() { if (canPrev.value) emit('update:year', props.year - 1); }
function next() { if (canNext.value) emit('update:year', props.year + 1); }
</script>

<template>
  <section class="ibc">
    <header class="ibc__head">
      <div class="ibc__nav">
        <button class="btn ibc__arrow" :disabled="!canPrev" aria-label="Previous year" @click="prev"><Icon name="arrow-left" /></button>
        <button class="btn ibc__arrow" :disabled="!canNext" aria-label="Next year" @click="next"><Icon name="arrow-right" /></button>
        <h3 class="ibc__title">Invoices issued in {{ year }}</h3>
      </div>
      <div class="ibc__legend">
        <span class="ibc__key"><span class="ibc__sw ibc__sw--open" /> Open</span>
        <span class="ibc__key"><span class="ibc__sw ibc__sw--paid" /> Paid</span>
      </div>
    </header>

    <svg ref="svgRef" class="ibc__svg" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none"
         role="img" :aria-label="`Monthly invoiced amounts for ${year}`">
      <g class="ibc__grid">
        <line :x1="PAD_L" :x2="W - PAD_R" :y1="baseline" :y2="baseline" />
        <template v-for="g in gridlines" :key="g.value">
          <line :x1="PAD_L" :x2="W - PAD_R" :y1="g.y" :y2="g.y" />
          <text :x="PAD_L - 10" :y="g.y + 4" text-anchor="end">{{ formatChf(g.value) }}</text>
        </template>
      </g>
      <g v-for="b in bars" :key="b.label">
        <rect v-if="b.paid.h > 0" class="ibc__bar ibc__bar--paid" :x="b.x" :y="b.paid.y" :width="barW" :height="b.paid.h" />
        <rect v-if="b.open.h > 0" class="ibc__bar ibc__bar--open" :x="b.x" :y="b.open.y" :width="barW" :height="b.open.h" />
        <text class="ibc__mlabel" :x="b.cx" :y="H - 8" text-anchor="middle">{{ b.label }}</text>
      </g>
    </svg>
  </section>
</template>

<style scoped>
.ibc { padding: 16px 28px; border-bottom: 1px solid var(--border); background: var(--paper); }
.ibc__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.ibc__nav { display: flex; align-items: center; gap: 8px; }
.ibc__arrow { padding: 5px 9px; }
.ibc__arrow:disabled { opacity: 0.4; cursor: default; }
.ibc__title { font-size: var(--fs-md); font-weight: 700; margin: 0 0 0 6px; letter-spacing: -0.01em; }
.ibc__legend { display: flex; gap: 16px; font-size: var(--fs-xs); color: var(--ink-2); }
.ibc__key { display: inline-flex; align-items: center; gap: 6px; }
.ibc__sw { width: 12px; height: 12px; display: inline-block; }
.ibc__sw--paid { background: var(--forest); }
.ibc__sw--open { background: color-mix(in srgb, var(--forest) 45%, var(--paper)); }
.ibc__svg { width: 100%; height: 230px; display: block; }
.ibc__grid line { stroke: var(--border); stroke-width: 1; }
.ibc__grid text { fill: var(--ink-3); font-size: 11px; font-variant-numeric: tabular-nums; }
.ibc__mlabel { fill: var(--ink-3); font-size: 12px; }
.ibc__bar--paid { fill: var(--forest); }
.ibc__bar--open { fill: color-mix(in srgb, var(--forest) 45%, var(--paper)); }
</style>
