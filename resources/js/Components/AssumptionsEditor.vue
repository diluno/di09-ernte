<script setup>
import Icon from '@/Components/Icon.vue';
import { makeAssumption } from '@/formatters/estimateScope.js';

// Bullet list printed as "Grundlagen der Schätzung" after the totals.
const items = defineModel({ type: Array, required: true });
</script>

<template>
  <div class="assumptions">
    <div v-for="(a, i) in items" :key="a.key" class="assumption-row">
      <span class="dim">–</span>
      <input v-model="a.text" class="assumption-input" placeholder="e.g. Basis ist die bestehende Plattform …" />
      <button class="icon-btn icon-btn--danger" title="remove" @click="items.splice(i, 1)"><Icon name="close" /></button>
    </div>
    <button class="add-line" @click="items.push(makeAssumption())"><span style="font-family: var(--font-mono)">+</span> Add assumption</button>
  </div>
</template>

<style scoped>
.assumptions { display: flex; flex-direction: column; gap: 6px; }
.assumption-row { display: grid; grid-template-columns: 12px 1fr auto; gap: 8px; align-items: center; }
.assumption-input {
  width: 100%; border: 1px solid var(--border-strong); background: var(--paper);
  padding: 8px 10px; font-family: inherit; color: var(--ink); border-radius: 3px;
}
.assumption-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in oklch, var(--accent) 14%, transparent); }
</style>
