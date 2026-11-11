<script setup>
import Icon from '@/Components/Icon.vue';
import { makeAssumption } from '@/formatters/estimateScope.js';

// Bullet list printed as "Grundlagen der Schätzung" after the totals.
// Styles: resources/css/pages/estimates.css (.estimate-assumptions).
const items = defineModel({ type: Array, required: true });
</script>

<template>
  <div class="lines-card estimate-assumptions">
    <div v-for="(a, i) in items" :key="a.key" class="estimate-assumptions__row">
      <span class="estimate-assumptions__mark" aria-hidden="true">–</span>
      <input v-model="a.text" class="cell-input" :aria-label="`Assumption ${i + 1}`" placeholder="e.g. Basis ist die bestehende Plattform …" />
      <button type="button" class="icon-btn icon-btn--danger" title="Remove assumption" :aria-label="`Remove assumption ${i + 1}`" @click="items.splice(i, 1)"><Icon name="close" /></button>
    </div>
    <button type="button" class="add-line" @click="items.push(makeAssumption())"><span class="mono">+</span> Add assumption</button>
  </div>
</template>
