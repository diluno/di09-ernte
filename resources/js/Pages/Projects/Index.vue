<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BudgetBar from '@/Components/BudgetBar.vue';
import { fmtMoney } from '@/formatters/money.js';
import { fmtHours } from '@/formatters/duration.js';
import { glyphClass } from '@/formatters/glyph.js';
import { rowClick } from '@/composables/useRowLink.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  projects: { type: Array, required: true },
  stats:    { type: Object, required: true },
  counts:   { type: Object, required: true },
  filters:  { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');
const filter = computed(() => props.filters.filter ?? 'active');

function setFilter(f) {
  router.get('/projects', { filter: f, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

let searchTimer = null;
function onSearch() {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get('/projects', { filter: filter.value, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
  }, 250);
}

const TABS = computed(() => [
  { id: 'active',   label: 'Active',   count: props.counts.active },
  { id: 'all',      label: 'All',      count: props.counts.all },
  { id: 'archived', label: 'Archived', count: props.counts.archived },
]);

function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }
const monthName = new Date().toLocaleString('en-GB', { month: 'long' });

const url = (p) => `/projects/${p.code}`;
// Status word + badge class: archived beats everything; over budget is the only red.
function status(p) {
  if (p.status === 'archived') return { cls: 'archived', label: 'archived' };
  if (p.band === 'over' && p.budget_hours > 0) return { cls: 'over', label: 'over' };
  if (p.band === 'warn' && p.budget_hours > 0) return { cls: 'warn', label: 'at risk' };
  if (p.retainer) return { cls: 'active', label: 'retainer' };
  return { cls: 'active', label: 'active' };
}
</script>

<template>
  <Head title="Projects" />

  <div class="page-head">
    <div>
      <div class="crumb">Projects · {{ counts.active }} active · {{ plural(counts.all, 'project', 'projects') }}</div>
      <h1 class="page-title">Projects</h1>
    </div>
    <div class="page-actions">
      <Link href="/projects/create" class="btn primary">+ New project</Link>
    </div>
  </div>

  <div v-if="counts.all === 0" class="empty-state">
    <h2 class="empty-state__title">No projects yet</h2>
    <p>A project holds the tasks, time and budget you bill a client for.</p>
    <div class="empty-state__actions"><Link href="/projects/create" class="btn primary">+ New project</Link></div>
  </div>

  <template v-else>
    <div class="stats">
      <div class="stat">
        <div class="label">Active projects</div>
        <div class="val">{{ stats.active }}</div>
        <div class="foot">{{ counts.archived }} archived</div>
      </div>
      <div class="stat">
        <div class="label">Hours this month</div>
        <div class="val">{{ fmtHours(stats.month_hours) }}</div>
        <div class="foot">{{ monthName }} · {{ fmtHours(stats.week_hours) }} this week</div>
      </div>
      <div class="stat">
        <div class="label">Unbilled</div>
        <div class="val">{{ fmtMoney(stats.unbilled_rappen, { rappen: true, whole: true }) }}<span class="unit">CHF</span></div>
        <div class="foot">{{ stats.unbilled_hours > 0 ? `${fmtHours(stats.unbilled_hours)} billable time` : 'nothing to bill' }}</div>
      </div>
      <div class="stat" :class="{ 'is-red': stats.over_budget_count > 0 }">
        <div class="label">Over budget</div>
        <div class="val">{{ stats.over_budget_count }}</div>
        <div class="foot">{{ stats.over_budget_count > 0 ? `active ${stats.over_budget_count === 1 ? 'project' : 'projects'} past their hours` : 'all within budget' }}</div>
      </div>
    </div>

    <div class="filter-row">
      <button v-for="tab in TABS" :key="tab.id" class="chip" :aria-pressed="filter === tab.id" @click="setFilter(tab.id)">
        {{ tab.label }} <span class="dim">{{ tab.count }}</span>
      </button>
      <label class="search">
        <input v-model="search" placeholder="filter…" aria-label="Filter projects" data-filter-input @input="onSearch" />
        <span class="kbd sm">/</span>
      </label>
    </div>

    <div class="table-wrap">
      <table class="table table--docs table--projects">
        <thead>
          <tr>
            <th class="pad-l">Project</th>
            <th style="width: 180px">Client</th>
            <th class="num" style="width: 110px">Rate</th>
            <th style="width: 240px">Hours budget</th>
            <th class="pad-r" style="width: 150px">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in projects" :key="p.id"
              class="is-clickable" :class="{ 'is-over': status(p).cls === 'over', 'is-archived': p.status === 'archived' }"
              @click="rowClick($event, url(p))">
            <td class="pad-l">
              <div class="proj-cell">
                <span class="proj-glyph" :class="glyphClass(p.id)" aria-hidden="true">{{ p.code[0] }}</span>
                <Link :href="url(p)" class="row-link cell-trunc strong-link" :title="p.name">{{ p.name }}</Link>
                <span class="code-dim">{{ p.code }}</span>
              </div>
            </td>
            <td class="trunc" :title="p.client.name">{{ p.client.name }}</td>
            <td class="num">
              <template v-if="p.billable && p.rate">{{ fmtMoney(p.rate) }}/h</template>
              <template v-else-if="!p.billable">non-billable</template>
              <template v-else>—</template>
            </td>
            <td>
              <BudgetBar v-if="p.budget_hours > 0" :spent="p.spent_hours" :budget="p.budget_hours" unit="h" />
              <span v-else class="dim">no budget · {{ fmtHours(p.spent_hours) }} logged</span>
            </td>
            <td class="pad-r"><span class="badge dot" :class="status(p).cls">{{ status(p).label }}</span></td>
          </tr>
          <tr v-if="projects.length === 0">
            <td colspan="5" class="is-empty">No projects match this filter.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</template>
