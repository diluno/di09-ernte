<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sparkline from '@/Components/Sparkline.vue';
import BudgetBar from '@/Components/BudgetBar.vue';
import { formatChf } from '@/formatters/money.js';
import { glyphClass } from '@/formatters/glyph.js';

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

function fmtMoneyShort(v) { return formatChf(v); }

const sparkColor = (band) => band === 'over' ? 'var(--red)' : band === 'warn' ? 'var(--rust)' : 'var(--forest)';

function relativeTime(iso) {
  if (!iso) return '—';
  const sec = Math.max(1, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
  if (sec < 60)        return `${sec}s ago`;
  if (sec < 3600)      return `${Math.floor(sec / 60)}m ago`;
  if (sec < 86400)     return `${Math.floor(sec / 3600)}h ago`;
  if (sec < 86400 * 7) return `${Math.floor(sec / 86400)}d ago`;
  return new Date(iso).toLocaleDateString();
}
</script>

<template>
  <Head title="Projects" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / projects</div>
      <h1 class="page-title">
        Projects
        <span class="meta">{{ projects.length }} of {{ counts.all }}</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/projects/create" class="btn primary">+ New project</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Active projects</div>
      <div class="val">{{ stats.active }}<span class="unit">running</span></div>
    </div>
    <div class="stat">
      <div class="label">This week</div>
      <div class="val">{{ stats.week_hours.toFixed(1) }}<span class="unit">h / 40h</span></div>
    </div>
    <div class="stat">
      <div class="label">Unbilled</div>
      <div class="val">{{ fmtMoneyShort(stats.unbilled_amount) }}<span class="unit">· {{ stats.unbilled_hours.toFixed(1) }}h</span></div>
    </div>
    <div class="stat">
      <div class="label">Outstanding</div>
      <div class="val" style="color: var(--rust)">{{ fmtMoneyShort(stats.outstanding_amount) }}</div>
      <div class="delta muted">(Phase 2b)</div>
    </div>
  </div>

  <div class="filter-row">
    <button
      v-for="c in [
        { id: 'active',   label: 'Active',   n: counts.active },
        { id: 'all',      label: 'All',      n: counts.all },
        { id: 'archived', label: 'Archived', n: counts.archived },
      ]" :key="c.id"
      class="chip"
      :aria-pressed="filter === c.id"
      @click="setFilter(c.id)"
    >{{ c.label }} <span class="dim" style="margin-left: 4px">{{ c.n }}</span></button>

    <span class="filter-divider" />
    <div class="search">
      <span style="color: var(--ink-4)">⌕</span>
      <input v-model="search" placeholder="filter…" @input="onSearch" />
      <span class="kbd">/</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="pad-l" style="width: 280px">Project</th>
          <th>Client</th>
          <th class="num" style="width: 90px">Rate</th>
          <th style="width: 260px">Hours budget</th>
          <th style="width: 240px">Fees budget</th>
          <th style="width: 130px">14-day</th>
          <th style="width: 90px">Status</th>
          <th class="pad-r num" style="width: 110px">Last activity</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in projects" :key="p.id">
          <td class="pad-l strong">
            <Link :href="`/projects/${p.code}`" class="proj-cell" style="color: inherit">
              <span class="proj-glyph" :class="glyphClass(p.id)">{{ p.code[0] }}</span>
              <span>
                {{ p.name }}
                <span class="dim" style="margin-left: 8px; font-weight: 400">{{ p.code }}</span>
              </span>
            </Link>
          </td>
          <td>{{ p.client.name }}</td>
          <td class="num">
            <template v-if="p.rate">{{ fmtMoneyShort(p.rate) }}/h</template>
            <span v-else class="dim">—</span>
          </td>
          <td>
            <BudgetBar v-if="p.budget_hours > 0" :spent="p.spent_hours" :budget="p.budget_hours" unit="h" />
            <span v-else class="dim">no budget · {{ p.spent_hours.toFixed(1) }}h logged</span>
          </td>
          <td>
            <BudgetBar v-if="p.budget_amount > 0" :spent="p.spent_amount" :budget="p.budget_amount" unit="CHF" />
            <span v-else class="dim">non-billable</span>
          </td>
          <td><Sparkline :data="p.sparkline" :w="120" :h="20" :color="sparkColor(p.band)" /></td>
          <td>
            <span v-if="p.retainer" class="badge dot active">retainer</span>
            <span v-else-if="p.band === 'over'" class="badge dot over">over</span>
            <span v-else-if="p.band === 'warn'" class="badge dot warn">at risk</span>
            <span v-else class="badge dot active">active</span>
          </td>
          <td class="pad-r num dim">{{ relativeTime(p.last_activity_at) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
