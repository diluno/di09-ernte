<script setup>
import { ref } from 'vue';
import { useTweaks } from '@/composables/useTweaks.js';

const { settings, set } = useTweaks();
const open = ref(false);

const ACCENTS = ['#2d4a3a', '#c97b3c', '#1a1a1a', '#b8941f'];
</script>

<template>
  <button
    class="tweaks-toggle"
    :aria-expanded="open"
    @click="open = !open"
    title="Tweaks"
  >☰</button>

  <aside v-if="open" class="tweaks-panel">
    <div class="section-title">Theme</div>
    <div class="tweaks-row">
      <button
        v-for="t in ['paper', 'dark']" :key="t"
        :aria-pressed="settings.theme === t"
        class="chip"
        @click="set('theme', t)"
      >{{ t }}</button>
    </div>

    <div class="section-title">Density</div>
    <div class="tweaks-row">
      <button
        v-for="d in ['comfortable', 'compact']" :key="d"
        :aria-pressed="settings.density === d"
        class="chip"
        @click="set('density', d)"
      >{{ d }}</button>
    </div>

    <div class="section-title">Accent</div>
    <div class="tweaks-row">
      <button
        v-for="a in ACCENTS" :key="a"
        class="accent-swatch"
        :aria-pressed="settings.accent === a"
        :style="{ background: a }"
        @click="set('accent', a)"
      />
    </div>
  </aside>
</template>

<style scoped>
.tweaks-toggle {
  position: fixed; right: 16px; bottom: 28px; z-index: 50;
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 10px; font-family: var(--font-mono); cursor: pointer;
  color: var(--ink);
}
.tweaks-panel {
  position: fixed; right: 16px; bottom: 64px; width: 240px;
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 14px; z-index: 50; color: var(--ink);
}
.tweaks-row { display: flex; gap: 6px; margin: 8px 0 16px; }
.accent-swatch {
  width: 24px; height: 24px; border: 2px solid var(--border); cursor: pointer;
}
.accent-swatch[aria-pressed="true"] { border-color: var(--ink); }
</style>
