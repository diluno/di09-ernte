<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { fmtDate } from '@/formatters/date.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  estimates: { type: Array, required: true },
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

function fmtMoney(v)      { return 'CHF ' + Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtMoneyShort(v) { return 'CHF ' + Math.round(v).toLocaleString('de-CH'); }

// Map estimate status → an existing invoice badge class + a display label.
function badge(est) {
  if (est.expired) return { cls: 'overdue', label: 'expired' };
  return { cls: { draft: 'draft', sent: 'sent', accepted: 'paid', declined: 'void' }[est.status] ?? 'draft', label: est.status };
}

const TABS = computed(() => [
  { id: 'all',      label: 'All',      count: props.counts.all },
  { id: 'draft',    label: 'Draft',    count: props.counts.draft },
  { id: 'sent',     label: 'Sent',     count: props.counts.sent },
  { id: 'accepted', label: 'Accepted', count: props.counts.accepted },
  { id: 'declined', label: 'Declined', count: props.counts.declined },
  { id: 'expired',  label: 'Expired',  count: props.counts.expired },
]);
</script>

<template>
  <Head title="Estimates" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / estimates</div>
      <h1 class="page-title">
        Estimates
        <span class="meta">{{ counts.all }} total<span class="ascii-dot">·</span>FY {{ new Date().getFullYear() }}</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/estimates/new" class="btn primary">+ New estimate</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Open</div>
      <div class="val" style="color: var(--rust)">{{ fmtMoneyShort(stats.open) }}</div>
      <div class="delta">{{ counts.sent }} sent</div>
    </div>
    <div class="stat">
      <div class="label">Accepted YTD</div>
      <div class="val">{{ fmtMoneyShort(stats.accepted_ytd) }}</div>
    </div>
    <div class="stat">
      <div class="label">Acceptance rate</div>
      <div class="val">{{ stats.acceptance_rate ?? '—' }}<span v-if="stats.acceptance_rate !== null" class="unit">%</span></div>
    </div>
    <div class="stat">
      <div class="label">Total</div>
      <div class="val">{{ stats.count }}</div>
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
          <th class="pad-l">Estimate</th>
          <th style="width: 230px">Client</th>
          <th class="num" style="width: 120px">Issued</th>
          <th class="num" style="width: 120px">Valid until</th>
          <th class="num" style="width: 90px">Hours</th>
          <th class="num" style="width: 150px">Total</th>
          <th style="width: 130px">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="est in estimates" :key="est.id"
            :class="{ 'is-overdue': est.expired, 'is-open': est.status === 'sent' && !est.expired }"
            @click="router.visit(`/estimates/${est.number}`)">
          <td class="pad-l strong">
            <div class="doc-id">
              <span class="mono-tag" style="padding: 2px 6px; color: var(--ink); border-color: var(--border-strong)">#{{ est.number }}</span>
              <span v-if="est.title" class="doc-id__title" :title="est.title">{{ est.title }}</span>
            </div>
          </td>
          <td>{{ est.client.name }}</td>
          <td class="num">{{ fmtDate(est.issued_on) }}</td>
          <td class="num" :style="{ color: est.expired ? 'var(--red)' : undefined }">{{ fmtDate(est.valid_until) }}</td>
          <td class="num">{{ est.hours.toFixed(1) }}h</td>
          <td class="num strong">{{ fmtMoney(est.total) }}</td>
          <td><span class="badge dot" :class="badge(est).cls">{{ badge(est).label }}</span></td>
        </tr>
        <tr v-if="estimates.length === 0">
          <td colspan="7" class="pad-l muted" style="padding: 24px">No estimates match this filter.</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
