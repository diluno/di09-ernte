<script setup>
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import { makeLine, makeSection, sectionTotals } from '@/formatters/estimateScope.js';
import { lineAmountRappen } from '@/formatters/vat.js';

// Sections of an estimate, each a small line table. With one unnamed section
// it behaves like a plain line list; naming sections groups them on the PDF
// (heading, subtotal, package overview). Line titles print bold, descriptions
// print beneath in lighter text — don't repeat the section label in titles.
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

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtHours(h) { return Number(h).toLocaleString('de-CH', { maximumFractionDigits: 2 }) + ' h'; }
</script>

<template>
  <div class="scope">
    <div v-for="(s, si) in sections" :key="s.key" class="lines-card scope-section">
      <div class="scope-head">
        <input v-model="s.label" class="cell-input scope-label" placeholder="Section label, e.g. Bündel 1 (optional)" />
        <input v-model="s.title" class="cell-input" placeholder="Section title, e.g. Regionale Webapp (optional)" />
        <div class="scope-actions">
          <button class="icon-btn" title="move section up" :disabled="si === 0" @click="move(sections, si, -1)"><Icon name="chevron-up" /></button>
          <button class="icon-btn icon-btn--danger" title="remove section" @click="removeSection(si)"><Icon name="close" /></button>
        </div>
      </div>

      <table class="table table--lines">
        <thead>
          <tr>
            <th class="pad-l">Task</th>
            <th class="num" style="width: 80px">Hours</th>
            <th class="num" style="width: 100px">Rate</th>
            <th class="num" style="width: 120px">Amount</th>
            <th style="width: 70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in s.lines" :key="l.key">
            <td class="pad-l">
              <input v-model="l.title" class="cell-input line-title" placeholder="Title, e.g. Technische Grundlage" />
              <AutoTextarea v-model="l.description" class="cell-input line-desc" placeholder="Description (optional)" />
            </td>
            <td class="num"><input v-model="l.hours" type="number" min="0" step="0.25" class="cell-input num" /></td>
            <td class="num"><input v-model="l.rate" type="number" min="0" step="0.01" class="cell-input num" /></td>
            <td class="num strong">{{ fmtMoney(lineAmountRappen(l)) }}</td>
            <td>
              <button class="icon-btn" title="move up" @click="move(s.lines, i, -1)"><Icon name="chevron-up" /></button>
              <button class="icon-btn icon-btn--danger" title="remove" @click="removeLine(s, i)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="s.lines.length === 0"><td colspan="5" class="pad-l muted" style="padding: 16px">No lines. Add one, or remove this section.</td></tr>
        </tbody>
      </table>
      <div class="scope-foot">
        <button class="add-line" @click="addLine(s)"><span style="font-family: var(--font-mono)">+</span> Add line</button>
        <span v-if="sections.length > 1 || s.label || s.title" class="dim scope-subtotal">
          {{ fmtHours(sectionTotals(s).hours) }} · {{ fmtMoney(sectionTotals(s).amount) }}
        </span>
      </div>
    </div>

    <button class="btn ghost" @click="addSection"><span style="font-family: var(--font-mono)">+</span> Add section</button>
  </div>
</template>

<style scoped>
.scope { display: flex; flex-direction: column; gap: 16px; align-items: flex-start; }
.scope-section { width: 100%; }
.scope-head { display: grid; grid-template-columns: minmax(200px, 280px) 1fr auto; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--border); background: var(--bg-2); }
.scope-head .cell-input { background: var(--paper); border-color: var(--border); }
.scope-label { font-weight: 600; }
.scope-actions { display: flex; align-items: center; }
.scope-foot { display: flex; align-items: center; justify-content: space-between; padding-right: 12px; }
.scope-subtotal { font-size: var(--fs-sm); font-variant-numeric: tabular-nums; white-space: nowrap; }

.cell-input {
  width: 100%;
  border: 1px solid transparent;
  background: var(--bg-2);
  padding: 8px 10px;
  font-family: inherit;
  color: var(--ink);
  border-radius: 3px;
}
.cell-input:hover { border-color: var(--border); }
.cell-input:focus {
  outline: none;
  border-color: var(--accent);
  background: var(--paper);
  box-shadow: 0 0 0 3px color-mix(in oklch, var(--accent) 14%, transparent);
}
.cell-input.num { text-align: right; font-variant-numeric: tabular-nums; }
.line-title { font-weight: 600; }
.line-desc { margin-top: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
</style>
