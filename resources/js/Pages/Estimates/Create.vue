<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import RecipientPicker from '@/Components/RecipientPicker.vue';
import { totalsForLines } from '@/formatters/vat.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients:  { type: Array, default: () => [] }, // [{id,name,contacts}]
  projects: { type: Array, default: () => [] }, // { id, name, client_id, rate }
  vat_rates: { type: Array, default: () => [] },
});

const clientId = ref(null);
const projectId = ref(null);
const title = ref('');
const notes = ref('');

// Projects belonging to the selected client.
const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);
const selectedClientContacts = computed(() => props.clients.find((c) => c.id === clientId.value)?.contacts ?? []);

// Reset the project when the client changes.
watch(clientId, () => { projectId.value = null; });

// Reset recipients to the new client's default contacts when the client changes.
watch(selectedClientContacts, (contacts) => {
  form.recipients = contacts.filter((c) => c.is_default).map(({ name, email }) => ({ name, email }));
});

// Editable lines (manual entry).
const lines = ref([]);
let nextKey = 0;
function addLine() {
  lines.value.push({
    key: nextKey++,
    description: '',
    hours: 0,
    rate: selectedProject.value?.rate ?? 0,
  });
}
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }

// Seed one empty line on mount for convenience.
addLine();

// ---- AI drafting -----------------------------------------------------------
// Sends a prose brief to the server, which asks Claude for line items and
// returns them as a proposal. Nothing is saved — the lines land in the form
// above for review and editing, exactly as if they'd been typed by hand.
const brief = ref('');
const drafting = ref(false);
const draftError = ref('');

const canDraft = computed(() => clientId.value && brief.value.trim().length >= 10 && !drafting.value);

async function draftWithAi() {
  if (!canDraft.value) return;
  drafting.value = true;
  draftError.value = '';

  try {
    const res = await fetch('/estimates/draft', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        // Laravel sets an XSRF-TOKEN cookie; plain fetch doesn't echo it back on its own.
        'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? ''),
      },
      body: JSON.stringify({
        brief: brief.value,
        client_id: clientId.value,
        project_id: projectId.value || null,
      }),
    });

    const payload = await res.json();
    if (!res.ok) throw new Error(payload.message || 'Drafting failed.');

    // Replace the lines wholesale — the draft is the starting point, not an addition.
    lines.value = payload.lines.map((l) => ({
      key: nextKey++,
      description: l.description,
      hours: l.hours,
      rate: l.rate || selectedProject.value?.rate || 0,
    }));
    if (payload.title && !title.value) title.value = payload.title;
    if (payload.notes && !notes.value) notes.value = payload.notes;
  } catch (e) {
    draftError.value = e.message;
  } finally {
    drafting.value = false;
  }
}

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtRate(rate) { return Number(rate).toFixed(2).replace(/\.?0+$/, ''); }

const totals = computed(() => totalsForLines(lines.value, props.vat_rates));
const subtotalRappen = computed(() => totals.value.subtotal);
const totalRappen = computed(() => totals.value.total);

const canSave = computed(() => clientId.value && lines.value.length > 0);

const form = useForm({ recipients: [] });
function save() {
  form.transform(() => ({
    client_id: clientId.value,
    project_id: projectId.value || null,
    title: title.value || null,
    notes: notes.value || null,
    recipients: form.recipients,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
    })),
  })).post('/estimates');
}
</script>

<template>
  <Head title="New estimate" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">~ / estimates</Link><span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">New estimate</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/estimates" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Create draft</button>
    </div>
  </div>

  <div class="doc-grid" style="padding: 20px 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Title</h3>
      <input v-model="title" class="cell-input" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px; margin-bottom: 20px" placeholder="e.g. Partnerschaft auf Augenhöhe — shown at the top of the PDF" />

      <h3 class="section-title">Client</h3>
      <div style="display: flex; gap: 12px; margin-bottom: 20px">
        <label class="field" style="flex: 1">
          <span>Client</span>
          <select v-model="clientId">
            <option :value="null" disabled>Select a client…</option>
            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="field" style="flex: 1">
          <span>Project (optional)</span>
          <select v-model="projectId" :disabled="!clientId">
            <option :value="null">—</option>
            <option v-for="p in clientProjects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>
      </div>

      <h3 class="section-title">Draft with AI</h3>
      <div class="ai-draft">
        <AutoTextarea
          v-model="brief"
          class="cell-input framed"
          :disabled="drafting"
          placeholder="Describe the job — e.g. “Neue Website für den Hofladen: 6 Seiten, Bildstrecke, Bestellformular, Umzug auf unser Hosting.” The lines below get replaced with a draft you can edit."
        />
        <div class="ai-draft__foot">
          <span class="dim" style="font-size: var(--fs-xs)">
            {{ clientId ? 'Uses this client’s past estimates as a style reference.' : 'Select a client first.' }}
          </span>
          <button class="btn ghost" :disabled="!canDraft" @click="draftWithAi">
            {{ drafting ? 'Drafting…' : 'Draft lines' }}
          </button>
        </div>
        <p v-if="draftError" style="color: var(--red); font-size: var(--fs-sm); margin-top: 8px">{{ draftError }}</p>
      </div>

      <h3 class="section-title" style="margin-top: 28px">Lines</h3>
      <div class="lines-card">
      <table class="table table--lines">
        <thead>
          <tr>
            <th class="pad-l">Description</th>
            <th class="num" style="width: 80px">Hours</th>
            <th class="num" style="width: 100px">Rate</th>
            <th class="num" style="width: 120px">Amount</th>
            <th style="width: 70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="l.key">
            <td class="pad-l"><AutoTextarea v-model="l.description" class="cell-input" placeholder="description" /></td>
            <td class="num"><input v-model="l.hours" type="number" min="0" step="0.25" class="cell-input num" /></td>
            <td class="num"><input v-model="l.rate" type="number" min="0" class="cell-input num" /></td>
            <td class="num strong">{{ fmtMoney(Math.round(Number(l.hours) * Number(l.rate) * 100)) }}</td>
            <td>
              <button class="icon-btn" title="move up" @click="moveUp(i)"><Icon name="chevron-up" /></button>
              <button class="icon-btn icon-btn--danger" title="remove" @click="removeLine(l.key)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="5" class="pad-l muted" style="padding: 16px">No lines. Add one to start.</td></tr>
        </tbody>
      </table>
      <button class="add-line" @click="addLine"><span style="font-family: var(--font-mono)">+</span> Add line</button>
      </div>

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input" rows="3" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px" placeholder="Optional notes shown on the estimate PDF…"></textarea>

      <h3 class="section-title" style="margin-top: 28px">Recipients</h3>
      <div class="field">
        <RecipientPicker :contacts="selectedClientContacts" v-model="form.recipients" />
      </div>
    </div>

    <aside class="summary-card">
      <div class="summary-head">Totals</div>
      <div class="summary-body">
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ fmtRate(totals.rate) }}%</div><div class="v">{{ fmtMoney(totals.vat) }}</div>
        <template v-if="totals.rounding !== 0">
          <div class="label">Rundung</div><div class="v">{{ fmtMoney(totals.rounding) }}</div>
        </template>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <button class="btn primary" style="width: 100%; justify-content: center; margin-top: 16px"
              :disabled="form.processing || !canSave" @click="save">
        Create draft
      </button>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        Server recomputes all amounts on save. The estimate is created as a draft; send it to stamp the validity date and email the client.
      </p>
      <div v-if="Object.keys(form.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 12px">
        {{ Object.values(form.errors).join(' · ') }}
      </div>
      </div>
    </aside>
  </div>
</template>

<style scoped>
/* Editable line-item cells: visible at rest, clear focus ring. */
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

/* Standalone framed fields (title / notes / dates). */
.cell-input.framed { background: var(--paper); border-color: var(--border-strong); }

/* Labelled select/date/text fields above the table. */
.field { display: flex; flex-direction: column; gap: 5px; font-size: var(--fs-sm); color: var(--ink-2); }
.field > span { font-size: var(--fs-xs); letter-spacing: 0.04em; text-transform: uppercase; color: var(--ink-3); }
.field input, .field select {
  border: 1px solid var(--border-strong);
  background: var(--paper);
  padding: 9px 11px;
  font-family: inherit;
  color: var(--ink);
  border-radius: 3px;
}
.field input:focus, .field select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px color-mix(in oklch, var(--accent) 14%, transparent);
}
/* Prose-brief box that feeds the AI drafter. */
.ai-draft { border: 1px solid var(--border-strong); background: var(--bg-2); padding: 12px; border-radius: 3px; }
.ai-draft .cell-input { min-height: 72px; }
.ai-draft__foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 10px; }

.detail-row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; border-bottom: 1px solid var(--border); }
</style>
