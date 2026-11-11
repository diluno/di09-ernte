<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sparkline from '@/Components/Sparkline.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import { fmtMoney } from '@/formatters/money.js';
import { fmtHours } from '@/formatters/duration.js';
import { glyphClass } from '@/formatters/glyph.js';
import { rowClick } from '@/composables/useRowLink.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients: { type: Array, required: true },
  stats:   { type: Object, required: true },
});

const filter = ref('active');
const search = ref('');

const TABS = computed(() => [
  { id: 'active',       label: 'Active',       count: props.clients.filter((c) => !c.archived).length },
  { id: 'all',          label: 'All',          count: props.clients.length },
  { id: 'with_balance', label: 'With balance', count: props.clients.filter((c) => c.outstanding > 0).length },
  { id: 'archived',     label: 'Archived',     count: props.clients.filter((c) => c.archived).length },
]);

const filtered = computed(() => {
  let list = props.clients;
  if (filter.value === 'active')       list = list.filter((c) => !c.archived);
  if (filter.value === 'with_balance') list = list.filter((c) => c.outstanding > 0);
  if (filter.value === 'archived')     list = list.filter((c) => c.archived);
  if (search.value) {
    const q = search.value.toLowerCase();
    list = list.filter((c) =>
      c.name.toLowerCase().includes(q) ||
      c.short_code.toLowerCase().includes(q) ||
      (c.default_contact?.name ?? '').toLowerCase().includes(q) ||
      (c.default_contact?.email ?? '').toLowerCase().includes(q));
  }
  return list;
});

function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }
const tile = (rappen) => fmtMoney(rappen, { rappen: true, whole: true });
const url = (c) => `/clients/${c.id}`;

function itemsFor(c) {
  return [
    { label: 'New invoice', href: `/invoices/new?client=${c.id}` },
    { label: 'New project', href: `/projects/create?client=${c.id}` },
    { label: 'Edit client', href: `/clients/${c.id}/edit` },
  ];
}
</script>

<template>
  <Head title="Clients" />

  <div class="page-head">
    <div>
      <div class="crumb">Clients · {{ stats.active }} active · {{ plural(clients.length, 'account', 'accounts') }}</div>
      <h1 class="page-title">Clients</h1>
    </div>
    <div class="page-actions">
      <Link href="/clients/create" class="btn primary">+ New client</Link>
    </div>
  </div>

  <div v-if="clients.length === 0" class="empty-state">
    <h2 class="empty-state__title">No clients yet</h2>
    <p>Add a client with its address and contacts, then create projects and invoices for it.</p>
    <div class="empty-state__actions"><Link href="/clients/create" class="btn primary">+ New client</Link></div>
  </div>

  <template v-else>
    <div class="stats">
      <div class="stat">
        <div class="label">Active clients</div>
        <div class="val">{{ stats.active }}</div>
        <div class="foot">{{ stats.archived }} archived</div>
      </div>
      <div class="stat">
        <div class="label">Outstanding</div>
        <div class="val">{{ tile(stats.outstanding_rappen) }}<span class="unit">CHF</span></div>
        <div class="foot">
          <template v-if="stats.outstanding_count > 0">
            {{ plural(stats.outstanding_count, 'invoice', 'invoices') }} sent<template v-if="stats.overdue_count > 0"> · <Link href="/invoices?filter=overdue" class="is-red">{{ stats.overdue_count }} overdue</Link></template>
          </template>
          <template v-else>nothing open</template>
        </div>
      </div>
      <div class="stat">
        <div class="label">Unbilled</div>
        <div class="val">{{ tile(stats.unbilled_rappen) }}<span class="unit">CHF</span></div>
        <div class="foot">{{ stats.unbilled_hours > 0 ? `${fmtHours(stats.unbilled_hours)} · ${plural(stats.unbilled_clients, 'client', 'clients')}` : 'nothing to bill' }}</div>
      </div>
      <div class="stat">
        <div class="label">Hours YTD</div>
        <div class="val">{{ fmtHours(stats.hours_ytd) }}</div>
        <div class="foot">since 1 Jan {{ new Date().getFullYear() }}</div>
      </div>
    </div>

    <div class="filter-row">
      <button v-for="tab in TABS" :key="tab.id" class="chip" :aria-pressed="filter === tab.id" @click="filter = tab.id">
        {{ tab.label }} <span class="dim">{{ tab.count }}</span>
      </button>
      <label class="search">
        <input v-model="search" placeholder="filter…" aria-label="Filter clients" data-filter-input />
        <span class="kbd sm">/</span>
      </label>
    </div>

    <div class="table-wrap">
      <table class="table table--docs table--clients">
        <thead>
          <tr>
            <th class="pad-l">Client</th>
            <th style="width: 200px">Contact</th>
            <th class="num" style="width: 110px">Rate</th>
            <th class="num" style="width: 90px">Projects</th>
            <th class="num" style="width: 100px">Hours YTD</th>
            <th class="num" style="width: 120px">Outstanding</th>
            <th style="width: 100px" title="Hours per day, last 14 days">14 days</th>
            <th class="row-actions" style="width: 96px"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in filtered" :key="c.id" class="is-clickable" :class="{ 'is-archived': c.archived }" @click="rowClick($event, url(c))">
            <td class="pad-l">
              <div class="proj-cell">
                <span class="proj-glyph" :class="glyphClass(c.id)" aria-hidden="true">{{ c.short_code[0] }}</span>
                <Link :href="url(c)" class="row-link cell-trunc strong-link" :title="c.name">{{ c.name }}</Link>
                <span v-if="c.archived" class="code-dim">archived</span>
              </div>
            </td>
            <td class="trunc" :title="[c.default_contact?.name, c.default_contact?.email].filter(Boolean).join(' · ') || undefined">
              <template v-if="c.default_contact?.name || c.default_contact?.email">
                <span class="ink">{{ c.default_contact.name || c.default_contact.email }}</span><template v-if="c.default_contact.name && c.default_contact.email"> · {{ c.default_contact.email }}</template>
              </template>
              <template v-else>—</template>
            </td>
            <td class="num">{{ c.default_rate ? `${fmtMoney(c.default_rate)}/h` : '—' }}</td>
            <td class="num">{{ c.projects_count }}</td>
            <td class="num">{{ fmtHours(c.hours_ytd) }}</td>
            <td :class="c.outstanding > 0 ? 'money' : 'num'">{{ c.outstanding > 0 ? fmtMoney(c.outstanding) : '—' }}</td>
            <td><Sparkline :data="c.sparkline" :w="110" :h="20" /></td>
            <td class="row-actions"><ActionMenu size="sm" :items="itemsFor(c)" :label="`Actions for ${c.name}`" /></td>
          </tr>
          <tr v-if="filtered.length === 0">
            <td colspan="8" class="is-empty">No clients match this filter.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</template>
