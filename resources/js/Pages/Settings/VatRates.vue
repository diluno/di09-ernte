<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { todayIso } from '@/formatters/date.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  rates: { type: Array, default: () => [] },
});

// One edit form per existing row, keyed by id. Kept in step with the `rates`
// prop: a newly added rate gets a form, a deleted one loses it, a row the user
// has not touched picks up server-side changes (adding a rate can close the
// open-ended one), and a row with unsaved edits keeps them.
const fromServer = (r) => ({ rate: Number(r.rate).toFixed(2), valid_from: r.valid_from, valid_until: r.valid_until ?? '' });
const sameValues = (a, b) => Number(a.rate) === Number(b.rate) && a.valid_from === b.valid_from && (a.valid_until || '') === (b.valid_until || '');

const edits = ref({});
let baseline = {};
watch(() => props.rates, (rates) => {
  const next = {};
  for (const r of rates) {
    const current = edits.value[r.id];
    const untouched = !current || !baseline[r.id] || sameValues(current, baseline[r.id]);
    next[r.id] = untouched ? fromServer(r) : current;
  }
  baseline = Object.fromEntries(rates.map((r) => [r.id, fromServer(r)]));
  edits.value = next;
}, { immediate: true });

const isDirty = (r) => !!edits.value[r.id] && !sameValues(edits.value[r.id], fromServer(r));
const rowErrors = ref({});

const adding = useForm({ rate: '', valid_from: '', valid_until: '' });

function payload(e) {
  return { rate: Number(e.rate), valid_from: e.valid_from, valid_until: e.valid_until || null };
}

function save(id) {
  rowErrors.value = {};
  router.patch(`/settings/vat-rates/${id}`, payload(edits.value[id]), {
    preserveScroll: true,
    onError: (errs) => { rowErrors.value = { id, errs }; },
  });
}

function add() {
  adding.transform((d) => ({ rate: Number(d.rate), valid_from: d.valid_from, valid_until: d.valid_until || null }))
    .post('/settings/vat-rates', {
      preserveScroll: true,
      onSuccess: () => adding.reset(),
    });
}

function remove(id) {
  if (!window.confirm('Remove this VAT rate? Existing documents keep their stored rate; new documents will fall back to the next covering rate.')) return;
  router.delete(`/settings/vat-rates/${id}`, { preserveScroll: true });
}

// "in force" / "scheduled" / "ended", from the saved values.
const today = todayIso();
function statusOf(r) {
  if (r.valid_from > today) return 'scheduled';
  if (r.valid_until && r.valid_until < today) return 'ended';
  return 'in force';
}
const hasError = (id, field) => rowErrors.value.id === id && !!rowErrors.value.errs?.[field];
</script>

<template>
  <Head title="VAT rates" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/settings">Settings</Link><span>/</span><span>VAT rates</span></div>
      <h1 class="page-title">VAT rates <span class="meta">Dated standard rate</span></h1>
    </div>
  </div>

  <div class="vat-intro">
    <p class="hint">
      One VAT rate applies at a time and periods may not overlap. Existing documents keep the rate stored on them.
    </p>
  </div>

  <div class="table-wrap">
    <table class="table vat-table">
      <thead>
        <tr>
          <th class="pad-l num" style="width: 140px">Rate %</th>
          <th style="width: 190px">Valid from</th>
          <th style="width: 190px">Valid until</th>
          <th>Status</th>
          <th class="row-actions pad-r"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="r in rates.filter((rate) => edits[rate.id])" :key="r.id">
          <tr :class="{ 'has-msg': rowErrors.id === r.id }">
            <td class="pad-l num"><input v-model="edits[r.id].rate" type="number" min="0" max="100" step="0.01" class="cell-input num" :aria-label="`Rate in percent, from ${r.valid_from}`" :aria-invalid="hasError(r.id, 'rate')" /></td>
            <td><input v-model="edits[r.id].valid_from" type="date" class="cell-input" :aria-label="`Valid from (rate from ${r.valid_from})`" :aria-invalid="hasError(r.id, 'valid_from')" /></td>
            <td><input v-model="edits[r.id].valid_until" type="date" class="cell-input" :aria-label="`Valid until, empty = open-ended (rate from ${r.valid_from})`" :aria-invalid="hasError(r.id, 'valid_until')" /></td>
            <td><span class="badge" :class="{ active: statusOf(r) === 'in force', draft: statusOf(r) === 'ended' }">{{ statusOf(r) }}</span><span v-if="!r.valid_until" class="dim"> · open-ended</span></td>
            <td class="row-actions pad-r">
              <button type="button" class="btn sm" :disabled="!isDirty(r)" :title="isDirty(r) ? undefined : 'No changes to save'" @click="save(r.id)">Save</button>
              <button type="button" class="btn sm ghost danger vat-delete" :aria-label="`Delete the rate from ${r.valid_from}`" @click="remove(r.id)">Delete</button>
            </td>
          </tr>
          <tr v-if="rowErrors.id === r.id" class="vat-msg">
            <td colspan="5" class="pad-l pad-r"><small class="error" role="alert">{{ Object.values(rowErrors.errs).join(' · ') }}</small></td>
          </tr>
        </template>
        <tr v-if="rates.length === 0">
          <td colspan="5" class="is-empty">No dated rates yet. Documents use the fallback rate from Settings.</td>
        </tr>
        <tr class="is-new">
          <td class="pad-l num"><input v-model="adding.rate" type="number" min="0" max="100" step="0.01" class="cell-input num" placeholder="8.10" aria-label="New rate in percent" :aria-invalid="!!adding.errors.rate" /></td>
          <td><input v-model="adding.valid_from" type="date" class="cell-input" aria-label="New rate valid from" :aria-invalid="!!adding.errors.valid_from" /></td>
          <td><input v-model="adding.valid_until" type="date" class="cell-input" aria-label="New rate valid until (empty = open-ended)" :aria-invalid="!!adding.errors.valid_until" /></td>
          <td class="dim">new</td>
          <td class="row-actions pad-r">
            <button type="button" class="btn sm primary" :disabled="adding.processing || !adding.rate || !adding.valid_from" :title="!adding.rate || !adding.valid_from ? 'Enter a rate and a valid-from date' : undefined" @click="add">+ Add rate</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="vat-foot">
    <small v-if="Object.keys(adding.errors).length" class="error" role="alert">{{ Object.values(adding.errors).join(' · ') }}</small>
    <p class="hint">
      When the Swiss rate changes, add the new rate with its <em>valid from</em> date and leave <em>valid until</em> empty:
      the current open-ended rate is closed on the day before. To add a rate for a limited period, give the current rate
      a <em>valid until</em> date first.
    </p>
  </div>
</template>
