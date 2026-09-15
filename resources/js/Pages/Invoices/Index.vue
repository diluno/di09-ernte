<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import InvoiceBarChart from '@/Components/InvoiceBarChart.vue';
import { fmtDate } from '@/formatters/date.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoices:     { type: Object, required: true },
  stats:        { type: Object, required: true },
  counts:       { type: Object, required: true },
  filters:      { type: Object, required: true },
  invoiceChart: { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');
const filter = computed(() => props.filters.filter ?? 'all');

function setFilter(f) {
  router.get('/invoices', { filter: f, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
}
let t = null;
function onSearch() {
  if (t) clearTimeout(t);
  t = setTimeout(() => router.get('/invoices', { filter: filter.value, q: search.value || undefined }, { preserveState: true, preserveScroll: true }), 250);
}

function changeChartYear(year) {
  router.reload({
    only: ['invoiceChart'],
    data: { chart_year: year },
    preserveState: true,
    preserveScroll: true,
  });
}

// Ledger numerals: Swiss apostrophe thousands, no currency prefix (the unit sits beside the value).
function fmtChf(v)  { return Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtChf0(v) { return Math.round(v).toLocaleString('de-CH'); }
function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }

const exportQuarters = computed(() => {
  const now = new Date();
  let year = now.getFullYear();
  let quarter = Math.floor(now.getMonth() / 3) + 1;
  const list = [];
  for (let i = 0; i < 8; i++) {
    list.push({ year, quarter, label: `Q${quarter} ${year}` });
    quarter -= 1;
    if (quarter === 0) { quarter = 4; year -= 1; }
  }
  return list;
});

function exportQuarter(event) {
  const value = event.target.value;
  if (!value) return;
  const [year, quarter] = value.split('-');
  window.location.href = `/invoices/export?year=${year}&quarter=${quarter}`;
  event.target.value = '';
}

const TABS = computed(() => [
  { id: 'all',     label: 'All',     count: props.counts.all },
  { id: 'draft',   label: 'Draft',   count: props.counts.draft },
  { id: 'sent',    label: 'Sent',    count: props.counts.sent },
  { id: 'overdue', label: 'Overdue', count: props.counts.overdue, red: true },
  { id: 'paid',    label: 'Paid',    count: props.counts.paid },
]);

const rowStatus = (inv) => inv.overdue ? 'overdue' : inv.status;
</script>

<template>
  <Head title="Invoices" />

  <div class="page-head">
    <div>
      <div class="crumb">Invoices · FY {{ new Date().getFullYear() }} · {{ plural(counts.all, 'document', 'documents') }}</div>
      <h1 class="page-title">Invoices</h1>
    </div>
    <div class="page-actions">
      <select class="btn" title="Download all paid invoices of a quarter as ZIP" @change="exportQuarter">
        <option value="">↓ Paid PDFs</option>
        <option v-for="q in exportQuarters" :key="q.label" :value="`${q.year}-${q.quarter}`">{{ q.label }}</option>
      </select>
      <Link href="/invoices/new" class="btn primary">+ New invoice</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Outstanding</div>
      <div class="val">{{ fmtChf0(stats.outstanding) }}<span class="unit">CHF</span></div>
      <div class="foot">{{ counts.sent }} sent</div>
    </div>
    <div class="stat" :class="{ 'is-red': stats.overdue_count > 0 }">
      <div class="label">Overdue</div>
      <div class="val">{{ fmtChf0(stats.overdue) }}<span class="unit">CHF</span></div>
      <div class="foot">
        <template v-if="stats.overdue_count > 0">{{ plural(stats.overdue_count, 'invoice', 'invoices') }} · {{ plural(stats.overdue_max_days, 'day', 'days') }}</template>
        <template v-else>nothing overdue</template>
      </div>
    </div>
    <div class="stat">
      <div class="label">Paid YTD</div>
      <div class="val">{{ fmtChf0(stats.paid_ytd) }}<span class="unit">CHF</span></div>
      <div class="foot">{{ stats.paid_ytd_count }} paid</div>
    </div>
    <div class="stat">
      <div class="label">Avg days to pay</div>
      <div class="val">{{ stats.avg_days_to_pay ?? '—' }}</div>
      <div class="foot">{{ stats.avg_terms_days !== null ? `terms ${stats.avg_terms_days}` : '—' }}</div>
    </div>
  </div>

  <InvoiceBarChart
    :year="invoiceChart.year"
    :min-year="invoiceChart.min_year"
    :max-year="invoiceChart.max_year"
    :months="invoiceChart.months"
    @update:year="changeChartYear"
  />

  <div class="filter-row">
    <button v-for="tab in TABS" :key="tab.id" class="chip" :class="{ 'is-red': tab.red }" :aria-pressed="filter === tab.id" @click="setFilter(tab.id)">
      {{ tab.label }} <span class="dim">{{ tab.count }}</span>
    </button>
    <label class="search">
      <input v-model="search" placeholder="filter…" data-filter-input @input="onSearch" />
      <span class="kbd sm">/</span>
    </label>
  </div>

  <div class="table-wrap">
    <table class="table table--docs">
      <thead>
        <tr>
          <th class="pad-l" style="width: 130px">No.</th>
          <th style="width: 260px">Subject</th>
          <th style="width: 220px">Client</th>
          <th class="num is-sorted" style="width: 120px">Issued ↓</th>
          <th class="num" style="width: 120px">Due</th>
          <th class="num" style="width: 80px">Hours</th>
          <th class="num" style="width: 150px">CHF</th>
          <th class="pad-r" style="width: 130px">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="inv in invoices.data" :key="inv.id"
            :class="`is-${rowStatus(inv)}`"
            @click="router.visit(`/invoices/${inv.number}`)">
          <td class="pad-l code">{{ inv.number }}</td>
          <td class="subject" :title="inv.title || undefined">{{ inv.title || '—' }}</td>
          <td class="trunc" :title="inv.client.name">{{ inv.client.name }}</td>
          <td class="num">{{ fmtDate(inv.issued_on) }}</td>
          <td class="num" :class="{ 'is-red': inv.overdue }">{{ fmtDate(inv.due_on) }}</td>
          <td class="num">{{ inv.hours.toFixed(1) }}</td>
          <td class="money">{{ fmtChf(inv.total) }}</td>
          <td class="pad-r"><span class="badge dot" :class="rowStatus(inv)">{{ rowStatus(inv) }}</span></td>
        </tr>
        <tr v-if="invoices.data.length === 0">
          <td colspan="8" class="pad-l muted" style="padding: 24px 40px; cursor: default">No invoices match this filter.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <Pagination :paginator="invoices" />
</template>
