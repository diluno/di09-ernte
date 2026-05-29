<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  rates: { type: Array, default: () => [] },
});

// One edit form per existing row, keyed by id.
const edits = ref(Object.fromEntries(props.rates.map((r) => [r.id, {
  rate: r.rate, valid_from: r.valid_from, valid_until: r.valid_until ?? '',
}])));
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
</script>

<template>
  <Head title="VAT rates" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/settings">~ / settings</Link><span class="ascii-dot">/</span><span>vat rates</span></div>
      <h1 class="page-title">VAT rates <span class="meta">Dated standard rate</span></h1>
    </div>
  </div>

  <div class="settings-page">
    <section class="settings-section">
      <p class="dim" style="font-size: var(--fs-sm); margin: 0 0 16px; line-height: 1.6">
        One VAT rate applies at a time. Add a new row with a future <em>valid from</em> date when the
        Swiss rate changes — periods may not overlap. Existing documents keep the rate stored on them.
      </p>

      <table class="table">
        <thead>
          <tr>
            <th class="pad-l num" style="width: 120px">Rate %</th>
            <th style="width: 180px">Valid from</th>
            <th style="width: 180px">Valid until</th>
            <th style="width: 140px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rates" :key="r.id">
            <td class="pad-l num"><input v-model="edits[r.id].rate" type="number" min="0" max="100" step="0.01" class="cell-input num" /></td>
            <td><input v-model="edits[r.id].valid_from" type="date" class="cell-input" /></td>
            <td><input v-model="edits[r.id].valid_until" type="date" class="cell-input" placeholder="open-ended" /></td>
            <td>
              <button class="btn ghost" @click="save(r.id)">Save</button>
              <button class="btn ghost" @click="remove(r.id)">Delete</button>
              <div v-if="rowErrors.id === r.id" class="error" style="color: var(--red); font-size: var(--fs-xs)">
                {{ Object.values(rowErrors.errs).join(' · ') }}
              </div>
            </td>
          </tr>
          <tr>
            <td class="pad-l num"><input v-model="adding.rate" type="number" min="0" max="100" step="0.01" class="cell-input num" placeholder="8.10" /></td>
            <td><input v-model="adding.valid_from" type="date" class="cell-input" /></td>
            <td><input v-model="adding.valid_until" type="date" class="cell-input" placeholder="open-ended" /></td>
            <td><button class="btn primary" :disabled="adding.processing || !adding.rate || !adding.valid_from" @click="add">+ Add rate</button></td>
          </tr>
        </tbody>
      </table>
      <div v-if="Object.keys(adding.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 12px">
        {{ Object.values(adding.errors).join(' · ') }}
      </div>
    </section>
  </div>
</template>

<style scoped>
.cell-input { width: 100%; border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.cell-input:focus { outline: none; border-color: var(--accent); }
.cell-input.num { text-align: right; }
</style>
