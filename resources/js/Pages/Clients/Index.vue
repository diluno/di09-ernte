<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import Sparkline from '@/Components/Sparkline.vue';
import { formatChf } from '@/formatters/money.js';
import { glyphClass } from '@/formatters/glyph.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients: { type: Array, required: true },
});

const filter = ref('active');
const search = ref('');

const filtered = computed(() => {
  let list = props.clients;
  if (filter.value === 'active')       list = list.filter((c) => !c.archived);
  if (filter.value === 'with_balance') list = list.filter((c) => c.outstanding > 0);
  if (filter.value === 'archived')     list = list.filter((c) => c.archived);
  if (search.value) {
    const q = search.value.toLowerCase();
    list = list.filter((c) =>
      c.name.toLowerCase().includes(q) ||
      (c.default_contact?.name ?? '').toLowerCase().includes(q));
  }
  return list;
});

const totalOutstanding = computed(() => props.clients.reduce((a, c) => a + c.outstanding, 0));
function fmtMoneyShort(v) { return formatChf(v); }
</script>

<template>
  <Head title="Clients" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / clients</div>
      <h1 class="page-title">
        Clients
        <span class="meta">{{ clients.length }} accounts<span v-if="totalOutstanding" class="ascii-dot">·</span><span v-if="totalOutstanding">{{ fmtMoneyShort(totalOutstanding) }} outstanding</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/clients/create" class="btn primary">+ New client</Link>
    </div>
  </div>

  <div class="filter-row">
    <button class="chip" :aria-pressed="filter === 'active'" @click="filter = 'active'">
      Active <span class="dim" style="margin-left: 4px">{{ clients.filter((c) => !c.archived).length }}</span>
    </button>
    <button class="chip" :aria-pressed="filter === 'all'" @click="filter = 'all'">
      All <span class="dim" style="margin-left: 4px">{{ clients.length }}</span>
    </button>
    <button class="chip" :aria-pressed="filter === 'with_balance'" @click="filter = 'with_balance'">
      With balance <span class="dim" style="margin-left: 4px">{{ clients.filter((c) => c.outstanding > 0).length }}</span>
    </button>
    <button class="chip" :aria-pressed="filter === 'archived'" @click="filter = 'archived'">
      Archived <span class="dim" style="margin-left: 4px">{{ clients.filter((c) => c.archived).length }}</span>
    </button>
    <div class="search">
      <Icon name="search" style="color: var(--ink-4)" />
      <input v-model="search" placeholder="filter…" />
    </div>
  </div>

  <div class="table-wrap">
    <table class="table table--docs">
      <thead>
        <tr>
          <th class="pad-l">Client</th>
          <th>Contact</th>
          <th class="num" style="width: 100px">Default rate</th>
          <th class="num" style="width: 90px">Projects</th>
          <th class="num" style="width: 110px">Hours YTD</th>
          <th class="num" style="width: 130px">Outstanding</th>
          <th class="pad-r" style="width: 150px">Activity</th>
          <th class="pad-r" style="width: 110px"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="c in filtered" :key="c.id">
          <td class="pad-l strong">
            <Link :href="`/clients/${c.id}`" class="proj-cell" style="color: inherit">
              <span class="proj-glyph" :class="glyphClass(c.id)">{{ c.short_code[0] }}</span>
              <span class="cell-trunc" :title="c.name">{{ c.name }}</span>
            </Link>
          </td>
          <td>
            <div class="cell-trunc" :title="[c.default_contact?.name, c.default_contact?.email].filter(Boolean).join(' · ')">
              <template v-if="c.default_contact?.name">
                {{ c.default_contact.name }}<span v-if="c.default_contact.email" class="dim" style="margin-left: 6px">{{ c.default_contact.email }}</span>
              </template>
              <span v-else class="dim">—</span>
            </div>
          </td>
          <td class="num">
            <template v-if="c.default_rate">{{ fmtMoneyShort(c.default_rate) }}/h</template>
            <span v-else class="dim">—</span>
          </td>
          <td class="num">{{ c.projects_count }}</td>
          <td class="num">{{ c.hours_ytd }}h</td>
          <td class="num strong" :style="{ color: c.outstanding > 0 ? 'var(--rust)' : 'var(--ink-3)' }">
            <template v-if="c.outstanding > 0">{{ fmtMoneyShort(c.outstanding) }}</template>
            <span v-else class="dim">—</span>
          </td>
          <td class="pad-r">
            <Sparkline :data="c.sparkline" :w="110" :h="20" color="var(--ink-3)" />
          </td>
          <td class="pad-r">
            <Link :href="`/invoices/new?client=${c.id}`" class="btn ghost" style="padding: 2px 8px; white-space: nowrap" @click.stop>+ Invoice</Link>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
