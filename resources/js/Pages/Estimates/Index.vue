<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { fmtDate } from '@/formatters/date.js';
import { fmtMoney } from '@/formatters/money.js';
import { fmtHours } from '@/formatters/duration.js';
import { rowClick } from '@/composables/useRowLink.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  estimates: { type: Object, required: true },
  stats:     { type: Object, required: true },
  counts:    { type: Object, required: true },
  filters:   { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');
const filter = computed(() => props.filters.filter ?? 'all');

function setFilter(f) {
  router.get('/estimates', { filter: f, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
}
let t = null;
function onSearch() {
  if (t) clearTimeout(t);
  t = setTimeout(() => router.get('/estimates', { filter: filter.value, q: search.value || undefined }, { preserveState: true, preserveScroll: true }), 250);
}

// Stat tiles show whole francs (aggregates); rows always show two decimals.
const fmtChf0 = (v) => fmtMoney(v, { whole: true });
function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }

const TABS = computed(() => [
  { id: 'all',      label: 'All',      count: props.counts.all },
  { id: 'draft',    label: 'Draft',    count: props.counts.draft },
  { id: 'sent',     label: 'Sent',     count: props.counts.sent },
  { id: 'expired',  label: 'Expired',  count: props.counts.expired, red: true },
  { id: 'accepted', label: 'Accepted', count: props.counts.accepted },
  { id: 'declined', label: 'Declined', count: props.counts.declined },
]);

// Expired is virtual (sent + past valid_until); it wins over the stored status.
const rowStatus = (est) => est.expired ? 'expired' : est.status;
// Row treatment from the shared table vocabulary: expired = red tint and bar
// (like an overdue invoice), draft = muted, decided = quiet (like paid).
const ROW_CLASS = { expired: 'is-overdue', draft: 'is-draft', accepted: 'is-paid', declined: 'is-paid' };
</script>

<template>
  <Head title="Estimates" />

  <div class="page-head">
    <div>
      <div class="crumb">Estimates · FY {{ new Date().getFullYear() }} · {{ plural(counts.all, 'document', 'documents') }}</div>
      <h1 class="page-title">Estimates</h1>
    </div>
    <div class="page-actions">
      <Link href="/estimates/new" class="btn primary">+ New estimate</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Open</div>
      <div class="val">{{ fmtChf0(stats.open) }}<span class="unit">CHF</span></div>
      <div class="foot">
        {{ stats.open_count }} sent<template v-if="stats.expired_count > 0"> · <span class="estimates-foot-red">{{ stats.expired_count }} expired</span></template>
      </div>
    </div>
    <div class="stat">
      <div class="label">Accepted YTD</div>
      <div class="val">{{ fmtChf0(stats.accepted_ytd) }}<span class="unit">CHF</span></div>
      <div class="foot">{{ stats.accepted_ytd_count }} accepted</div>
    </div>
    <div class="stat">
      <div class="label">Acceptance rate</div>
      <div class="val">{{ stats.acceptance_rate ?? '—' }}<span v-if="stats.acceptance_rate !== null" class="unit">%</span></div>
      <div class="foot">
        <template v-if="stats.decided_count > 0">{{ stats.accepted_count }} of {{ stats.decided_count }} decided · all time</template>
        <template v-else>nothing decided yet</template>
      </div>
    </div>
    <div class="stat">
      <div class="label">Drafts</div>
      <div class="val">{{ fmtChf0(stats.drafts) }}<span class="unit">CHF</span></div>
      <div class="foot">{{ plural(stats.drafts_count, 'draft', 'drafts') }} not sent</div>
    </div>
  </div>

  <div class="filter-row">
    <button v-for="tab in TABS" :key="tab.id" class="chip" :class="{ 'is-red': tab.red }" :aria-pressed="filter === tab.id" @click="setFilter(tab.id)">
      {{ tab.label }} <span class="dim">{{ tab.count }}</span>
    </button>
    <label class="search">
      <input v-model="search" placeholder="filter…" aria-label="Filter" data-filter-input @input="onSearch" />
      <span class="kbd sm">/</span>
    </label>
  </div>

  <div class="table-wrap">
    <table class="table table--docs estimates-table">
      <thead>
        <tr>
          <th class="pad-l" style="width: 170px">No.</th>
          <th>Subject</th>
          <th style="width: 180px">Client</th>
          <th class="num is-sorted" style="width: 110px">Issued ↓</th>
          <th class="num" style="width: 110px">Valid until</th>
          <th class="num" style="width: 72px">Hours</th>
          <th class="num" style="width: 130px">CHF</th>
          <th class="pad-r" style="width: 140px">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="est in estimates.data" :key="est.id"
            class="is-clickable" :class="ROW_CLASS[rowStatus(est)]"
            @click="rowClick($event, `/estimates/${est.number}`)">
          <td class="pad-l code"><Link :href="`/estimates/${est.number}`" class="row-link" :aria-label="`Estimate ${est.number}${est.title ? ` — ${est.title}` : ''}`">{{ est.number }}</Link></td>
          <td class="subject" :title="est.title || undefined">{{ est.title || '—' }}</td>
          <td class="trunc" :title="est.client.name">{{ est.client.name }}</td>
          <td class="num">{{ fmtDate(est.issued_on) }}</td>
          <td class="num" :class="{ 'is-red': est.expired }">{{ fmtDate(est.valid_until) }}</td>
          <td class="num">{{ fmtHours(est.hours) }}</td>
          <td class="money">{{ fmtMoney(est.total) }}</td>
          <td class="pad-r"><span class="badge dot" :class="rowStatus(est)">{{ rowStatus(est) }}</span></td>
        </tr>
        <tr v-if="estimates.data.length === 0">
          <td colspan="8" class="is-empty">No estimates match this filter.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <Pagination :paginator="estimates" />
</template>
