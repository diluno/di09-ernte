<script setup>
import { computed } from 'vue';

// Browser port of App\Support\GenerativeLogo (the mark on the PDFs): the six "diluno"
// glyphs, each drawn as a pile of overlapped outlines that fills a seeded share of the
// width. The same seed always gives the same mark. It is decoration: the seeds here do
// not have to reproduce a document's mark, so the random source differs from PHP's.
const props = defineProps({
  seed:    { type: [String, Number], required: true },
  // viewBox width; the height is always 80. A narrower box gives a more compact mark.
  width:   { type: Number, default: 700 },
  // Distance between two overlapped copies, in viewBox units. Raise it for small sizes.
  step:    { type: Number, default: 4 },
  spacing: { type: Number, default: 15 },
  // Stroke in screen pixels (it does not scale with the mark).
  stroke:  { type: Number, default: 1 },
});

const GLYPHS = [
  ['M36.15,65 L36.15,58.43 C33.45,63.02 28.5,65.99 21.93,65.99 C10.23,65.99 0.24,57.44 0.24,41.33 C0.24,25.85 10.59,17.3 22.02,17.3 C28.5,17.3 33.27,19.91 35.97,24.05 L35.97,0.2 L47.58,0.2 L47.58,65 L36.15,65 Z M24.18,56.45 C30.84,56.45 36.24,50.87 36.24,41.33 C36.24,32.15 31.11,26.84 24.18,26.84 C17.16,26.84 12.12,32.24 12.12,41.33 C12.12,50.87 17.16,56.45 24.18,56.45 Z', 47.34],
  ['M0,11 L0,0.2 L11.7,0.2 L11.7,11 L0,11 Z M0.09,65 L0.09,18.38 L11.61,18.38 L11.61,65 L0.09,65 Z', 11.7],
  ['M0 65 0 0.2 11.52 0.2 11.52 65z', 11.52],
  ['M30.51,65 L30.51,59.51 C27.54,63.83 22.77,65.99 16.92,65.99 C7.02,65.99 0,59.78 0,47.63 L0,18.38 L11.61,18.38 L11.61,45.74 C11.61,52.4 15.21,56.09 20.7,56.09 C25.65,56.09 30.24,52.31 30.24,44.12 L30.24,18.38 L41.76,18.38 L41.76,65 L30.51,65 Z', 41.76],
  ['M11.52,39.71 L11.52,65 L0,65 L0,18.38 L11.34,18.38 L11.34,23.96 C14.31,19.73 19.35,17.3 25.29,17.3 C35.55,17.3 42.21,23.69 42.21,35.93 L42.21,65 L30.69,65 L30.69,37.64 C30.69,31.25 27.27,27.2 21.42,27.2 C16.11,27.2 11.52,31.25 11.52,39.71 Z', 42.21],
  ['M24.57,65.99 C10.89,65.99 0,56.54 0,41.42 C0,26.75 11.07,17.3 24.57,17.3 C38.16,17.3 49.05,26.57 49.05,41.42 C49.05,56.36 38.07,65.99 24.57,65.99 Z M24.57,56.27 C32.04,56.27 37.17,50.33 37.17,41.42 C37.17,32.96 32.04,27.02 24.57,27.02 C16.92,27.02 11.88,32.87 11.88,41.42 C11.88,50.24 17.01,56.27 24.57,56.27 Z', 49.05],
];

// FNV-1a hash of the seed, then mulberry32: small, deterministic, good enough for shares.
function rng(seed) {
  let h = 2166136261;
  for (const ch of String(seed)) { h ^= ch.codePointAt(0); h = Math.imul(h, 16777619); }
  let a = h >>> 0;
  return () => {
    a = (a + 0x6D2B79F5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const copies = computed(() => {
  const rand = rng(props.seed);
  const shares = GLYPHS.map(() => rand() + 0.2);
  const sum = shares.reduce((a, s) => a + s, 0);
  const usable = props.width - (GLYPHS.length - 1) * props.spacing - 10;
  const out = [];
  let x = 5;
  GLYPHS.forEach(([d, w], g) => {
    const space = (shares[g] / sum) * usable;
    const n = Math.max(1, Math.round((space - w) / props.step));
    for (let i = 0; i < n; i++) { out.push({ d, x: Math.round(x * 100) / 100 }); x += props.step; }
    x += w + (props.spacing - props.step);
  });
  return out;
});
</script>

<template>
  <svg
    class="gen-mark" :viewBox="`0 0 ${width} 76`" preserveAspectRatio="xMinYMid meet"
    fill="none" stroke="currentColor" :stroke-width="stroke" aria-hidden="true" focusable="false"
  >
    <path v-for="(c, i) in copies" :key="i" :d="c.d" :transform="`translate(${c.x},5)`" vector-effect="non-scaling-stroke" />
  </svg>
</template>
