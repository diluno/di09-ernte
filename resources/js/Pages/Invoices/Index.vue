<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import { fmtDate } from '@/formatters/date.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoices: { type: Object, required: true },
  stats:    { type: Object, required: true },
  counts:   { type: Object, required: true },
  filters:  { type: Object, required: true },
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

function fmtMoney(v)      { return 'CHF ' + Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtMoneyShort(v) { return 'CHF ' + Math.round(v).toLocaleString('de-CH'); }

const TABS = computed(() => [
  { id: 'all',     label: 'All',     count: props.counts.all },
  { id: 'draft',   label: 'Draft',   count: props.counts.draft },
  { id: 'sent',    label: 'Sent',    count: props.counts.sent },
  { id: 'overdue', label: 'Overdue', count: props.counts.overdue },
  { id: 'paid',    label: 'Paid',    count: props.counts.paid },
]);
</script>

<template>
  <Head title="Invoices" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / invoices</div>
      <h1 class="page-title">
        Invoices
        <span class="meta">{{ counts.all }} total<span class="ascii-dot">·</span>FY {{ new Date().getFullYear() }}</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/invoices/new" class="btn primary">+ New invoice</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Outstanding</div>
      <div class="val" style="color: var(--rust)">{{ fmtMoneyShort(stats.outstanding) }}</div>
      <div class="delta">{{ counts.sent }} sent</div>
    </div>
    <div class="stat">
      <div class="label">Overdue</div>
      <div class="val" style="color: var(--red)">{{ fmtMoneyShort(stats.overdue) }}</div>
      <div class="delta down">{{ counts.overdue }} invoice(s)</div>
    </div>
    <div class="stat">
      <div class="label">Paid YTD</div>
      <div class="val">{{ fmtMoneyShort(stats.paid_ytd) }}</div>
    </div>
    <div class="stat">
      <div class="label">Avg days to pay</div>
      <div class="val">{{ stats.avg_days_to_pay ?? '—' }}<span class="unit">days</span></div>
    </div>
  </div>

  <div class="filter-row">
    <button v-for="tab in TABS" :key="tab.id" class="chip" :aria-pressed="filter === tab.id" @click="setFilter(tab.id)">
      {{ tab.label }} <span class="dim" style="margin-left: 4px">{{ tab.count }}</span>
    </button>
    <div class="search">
      <Icon name="search" style="color: var(--ink-4)" />
      <input v-model="search" placeholder="filter…" @input="onSearch" />
    </div>
  </div>

  <div class="table-wrap">
    <table class="table table--docs">
      <thead>
        <tr>
          <th class="pad-l">Invoice</th>
          <th style="width: 230px">Client</th>
          <th class="num" style="width: 120px">Issued</th>
          <th class="num" style="width: 120px">Due</th>
          <th class="num" style="width: 90px">Hours</th>
          <th class="num" style="width: 150px">Total</th>
          <th style="width: 130px">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="inv in invoices.data" :key="inv.id"
            :class="{ 'is-overdue': inv.overdue, 'is-open': inv.status === 'sent' && !inv.overdue }"
            @click="router.visit(`/invoices/${inv.number}`)">
          <td class="pad-l strong">
            <div class="doc-id">
              <span class="mono-tag" style="padding: 2px 6px; color: var(--ink); border-color: var(--border-strong)">#{{ inv.number }}</span>
              <span v-if="inv.title" class="doc-id__title" :title="inv.title">{{ inv.title }}</span>
            </div>
          </td>
          <td>{{ inv.client.name }}</td>
          <td class="num">{{ fmtDate(inv.issued_on) }}</td>
          <td class="num" :style="{ color: inv.overdue ? 'var(--red)' : undefined }">{{ fmtDate(inv.due_on) }}</td>
          <td class="num">{{ inv.hours.toFixed(1) }}h</td>
          <td class="num strong">{{ fmtMoney(inv.total) }}</td>
          <td><span class="badge dot" :class="inv.overdue ? 'overdue' : inv.status">{{ inv.overdue ? 'overdue' : inv.status }}</span></td>
        </tr>
        <tr v-if="invoices.data.length === 0">
          <td colspan="7" class="pad-l muted" style="padding: 24px">No invoices match this filter.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <Pagination :paginator="invoices" />
</template>
