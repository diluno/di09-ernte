<script setup>
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import { makeLine, makeSection, sectionTotals } from '@/formatters/estimateScope.js';
import { lineAmountRappen } from '@/formatters/vat.js';
import { fmtMoney } from '@/formatters/money.js';
import { fmtHours } from '@/formatters/duration.js';

// Sections of an estimate, each a small line table. With one unnamed section
// it behaves like a plain line list; naming sections groups them on the PDF
// (heading, subtotal, package overview). Line titles print bold, descriptions
// print beneath in lighter text — don't repeat the section label in titles.
// Styles: resources/css/pages/estimates.css (.estimate-scope).
const sections = defineModel({ type: Array, required: true });
const props = defineProps({ defaultRate: { type: Number, default: 0 } });

function move(list, i, dir) {
  const j = i + dir;
  if (j < 0 || j >= list.length) return;
  [list[i], list[j]] = [list[j], list[i]];
}
function addSection() { sections.value.push(makeSection(props.defaultRate)); }
function removeSection(i) {
  if (sections.value.length === 1) { sections.value[0] = makeSection(props.defaultRate); return; }
  sections.value.splice(i, 1);
}
function addLine(section) { section.lines.push(makeLine(props.defaultRate)); }
function removeLine(section, i) { section.lines.splice(i, 1); }

const money = (rappen) => fmtMoney(rappen, { rappen: true });
// "Section 2" in accessible names, or its label once it has one.
const sectionName = (s, si) => s.label?.trim() || s.title?.trim() || `section ${si + 1}`;
</script>

<template>
  <div class="estimate-scope">
    <div v-for="(s, si) in sections" :key="s.key" class="lines-card estimate-scope__section">
      <div class="estimate-scope__head">
        <input v-model="s.label" class="cell-input estimate-scope__label" :aria-label="`Section ${si + 1} label`" placeholder="Label, e.g. Bündel 1 (optional)" />
        <input v-model="s.title" class="cell-input estimate-scope__title" :aria-label="`Section ${si + 1} title`" placeholder="Title, e.g. Regionale Webapp (optional)" />
        <div class="line-actions">
          <button type="button" class="icon-btn" title="Move section up" :aria-label="`Move ${sectionName(s, si)} up`" :disabled="si === 0" @click="move(sections, si, -1)"><Icon name="chevron-up" /></button>
          <button type="button" class="icon-btn" title="Move section down" :aria-label="`Move ${sectionName(s, si)} down`" :disabled="si === sections.length - 1" @click="move(sections, si, 1)"><Icon name="chevron-down" /></button>
          <button type="button" class="icon-btn icon-btn--danger" title="Remove section" :aria-label="`Remove ${sectionName(s, si)}`" @click="removeSection(si)"><Icon name="close" /></button>
        </div>
      </div>

      <div class="estimate-scope__scroll">
        <table class="table table--lines estimate-scope__table">
          <thead>
            <tr>
              <th>Task</th>
              <th class="num" style="width: 80px">Hours</th>
              <th class="num" style="width: 104px">Rate</th>
              <th class="num" style="width: 112px">CHF</th>
              <th style="width: 96px"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in s.lines" :key="l.key">
              <td>
                <input v-model="l.title" class="cell-input estimate-scope__line-title" :aria-label="`Line ${i + 1} title`" placeholder="Title, e.g. Technische Grundlage" />
                <AutoTextarea v-model="l.description" class="cell-input estimate-scope__line-desc" :aria-label="`Line ${i + 1} description`" placeholder="Description (optional)" />
              </td>
              <td class="num"><input v-model="l.hours" type="number" min="0" step="0.25" inputmode="decimal" class="cell-input num" :aria-label="`Line ${i + 1} hours`" /></td>
              <td class="num"><input v-model="l.rate" type="number" min="0" step="0.01" inputmode="decimal" class="cell-input num" :aria-label="`Line ${i + 1} rate in CHF per hour`" /></td>
              <td class="money">{{ money(lineAmountRappen(l)) }}</td>
              <td>
                <div class="line-actions">
                  <button type="button" class="icon-btn" title="Move up" :aria-label="`Move line ${i + 1} up`" :disabled="i === 0" @click="move(s.lines, i, -1)"><Icon name="chevron-up" /></button>
                  <button type="button" class="icon-btn" title="Move down" :aria-label="`Move line ${i + 1} down`" :disabled="i === s.lines.length - 1" @click="move(s.lines, i, 1)"><Icon name="chevron-down" /></button>
                  <button type="button" class="icon-btn icon-btn--danger" title="Remove line" :aria-label="`Remove line ${i + 1}`" @click="removeLine(s, i)"><Icon name="close" /></button>
                </div>
              </td>
            </tr>
            <tr v-if="s.lines.length === 0"><td colspan="5" class="is-empty">No lines. Add one, or remove this section.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="estimate-scope__foot">
        <button type="button" class="add-line" @click="addLine(s)"><span class="mono">+</span> Add line</button>
        <span v-if="sections.length > 1 || s.label || s.title" class="estimate-scope__subtotal">
          {{ fmtHours(sectionTotals(s).hours) }} · {{ money(sectionTotals(s).amount) }} CHF
        </span>
      </div>
    </div>

    <button type="button" class="btn ghost sm" @click="addSection"><span class="mono">+</span> Add section</button>
  </div>
</template>
