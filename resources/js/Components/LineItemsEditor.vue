<script setup>
import { nextTick, ref } from 'vue';
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import { makeLineItem } from '@/formatters/lineItems.js';
import { lineAmountRappen } from '@/formatters/vat.js';
import { fmtMoney } from '@/formatters/money.js';

// Editable line-item table shared by the invoice and recurring-invoice editors.
// Lines are { key, description, hours, rate (CHF/h), vat_exempt?, ... } — extra
// fields (e.g. entry_ids) are left untouched.
//
// Layout: a table while the editor is at least 560px wide; narrower (stacked doc
// grid, phone) each line becomes a small card — see .line-editor in pages/billing.css.
const lines = defineModel({ type: Array, required: true });

const props = defineProps({
  // Show the per-line "charge VAT" checkbox (writes line.vat_exempt).
  showVat: { type: Boolean, default: false },
  // Values for a line added with "+ Add line": an object, or (lines) => object.
  newLine: { type: [Object, Function], default: () => ({}) },
  emptyText: { type: String, default: 'No lines. Add one to start.' },
});

// 'remove' fires with the removed line after it left the list.
const emit = defineEmits(['remove']);

const bodyEl = ref(null);

function addLine() {
  const values = typeof props.newLine === 'function' ? props.newLine(lines.value) : props.newLine;
  const line = makeLineItem(values);
  lines.value.push(line);
  // Move focus to the new line's description.
  nextTick(() => {
    bodyEl.value?.querySelector(`[data-line-key="${CSS.escape(line.key)}"] textarea`)?.focus();
  });
}
function removeLine(index) {
  const [removed] = lines.value.splice(index, 1);
  if (removed) emit('remove', removed);
}
function move(index, dir) {
  const target = index + dir;
  if (target < 0 || target >= lines.value.length) return;
  const list = lines.value;
  [list[index], list[target]] = [list[target], list[index]];
}
</script>

<template>
  <div class="lines-card line-editor" :class="{ 'has-vat': showVat }">
    <table class="table table--lines">
      <thead>
        <tr>
          <th class="le-desc">Description</th>
          <th class="num le-hours">Hours</th>
          <th class="num le-rate">Rate</th>
          <th class="num le-amount">CHF</th>
          <th v-if="showVat" class="le-vat" title="Charge VAT on this line">VAT</th>
          <th class="le-actions"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody ref="bodyEl">
        <tr v-for="(line, index) in lines" :key="line.key" :data-line-key="line.key">
          <td class="le-desc">
            <AutoTextarea v-model="line.description" class="cell-input" placeholder="Description" :aria-label="`Line ${index + 1} description`" />
          </td>
          <td class="num le-hours" data-label="Hours">
            <input v-model="line.hours" type="number" inputmode="decimal" min="0" step="0.25" class="cell-input num" :aria-label="`Line ${index + 1} hours`" />
          </td>
          <td class="num le-rate" data-label="Rate">
            <input v-model="line.rate" type="number" inputmode="decimal" min="0" step="0.01" class="cell-input num" :aria-label="`Line ${index + 1} rate in CHF per hour`" />
          </td>
          <td class="num le-amount" data-label="CHF">{{ fmtMoney(lineAmountRappen(line), { rappen: true }) }}</td>
          <td v-if="showVat" class="le-vat">
            <label class="le-vat__label" title="Charge VAT on this line">
              <input
                type="checkbox" :checked="!line.vat_exempt"
                :aria-label="`Charge VAT on line ${index + 1}`"
                @change="line.vat_exempt = !$event.target.checked"
              />
              <span class="le-vat__text" aria-hidden="true">VAT</span>
            </label>
          </td>
          <td class="le-actions">
            <div class="line-actions">
              <button type="button" class="icon-btn" title="Move up" :aria-label="`Move line ${index + 1} up`" :disabled="index === 0" @click="move(index, -1)"><Icon name="chevron-up" /></button>
              <button type="button" class="icon-btn" title="Move down" :aria-label="`Move line ${index + 1} down`" :disabled="index === lines.length - 1" @click="move(index, 1)"><Icon name="chevron-down" /></button>
              <button type="button" class="icon-btn icon-btn--danger" title="Remove" :aria-label="`Remove line ${index + 1}`" @click="removeLine(index)"><Icon name="close" /></button>
            </div>
          </td>
        </tr>
        <tr v-if="lines.length === 0" class="le-empty">
          <td :colspan="showVat ? 6 : 5" class="is-empty">{{ emptyText }}</td>
        </tr>
      </tbody>
    </table>
    <button type="button" class="add-line" @click="addLine"><span class="mono">+</span> Add line</button>
  </div>
</template>
