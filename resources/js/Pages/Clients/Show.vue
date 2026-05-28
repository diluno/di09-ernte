<script setup>
import { computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BudgetBar from '@/Components/BudgetBar.vue';
import EntryRow from '@/Components/EntryRow.vue';
import { formatChf } from '@/formatters/money.js';
import { pushRecent } from '@/composables/useRecent.js';
import { glyphClass } from '@/glyph.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, required: true },
  stats: { type: Object, required: true },
  projects: { type: Array, required: true },
  recent_entries: { type: Array, required: true },
  invoices: { type: Array, required: true },
});

const address = computed(() => [
  props.client.address_line_1,
  props.client.address_line_2,
  [props.client.postal_code, props.client.city].filter(Boolean).join(' '),
  props.client.country,
].filter(Boolean));

onMounted(() => {
  pushRecent({ url: `/clients/${props.client.id}`, label: props.client.name });
});

function fmtDate(d) {
  return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '-';
}
</script>

<template>
  <Head :title="client.name" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/clients">~ / clients</Link>
        <span class="ascii-dot">/</span>
        <span>{{ client.short_code }}</span>
      </div>
      <h1 class="page-title">
        <span class="proj-glyph" :class="glyphClass(client.id)" style="width: 28px; height: 28px; font-size: 14px">{{ client.short_code[0] }}</span>
        {{ client.name }}
        <span class="meta">
          {{ client.contact_name || 'no contact' }}
          <span v-if="client.archived" class="ascii-dot">·</span>
          <span v-if="client.archived" class="badge dot void">archived</span>
        </span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link :href="`/invoices/new?client=${client.id}`" class="btn primary">+ Invoice</Link>
      <Link :href="`/clients/${client.id}/edit`" class="btn">Edit</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Outstanding</div>
      <div class="val" style="color: var(--rust)">{{ formatChf(stats.outstanding) }}</div>
    </div>
    <div class="stat">
      <div class="label">Paid YTD</div>
      <div class="val">{{ formatChf(stats.paid_ytd) }}</div>
    </div>
    <div class="stat">
      <div class="label">Hours YTD</div>
      <div class="val">{{ stats.hours_ytd.toFixed(1) }}<span class="unit">h</span></div>
      <div class="delta">{{ stats.unbilled_hours.toFixed(1) }}h unbilled</div>
    </div>
    <div class="stat">
      <div class="label">Projects</div>
      <div class="val">{{ stats.active_projects }}<span class="unit">active</span></div>
      <div class="delta">{{ stats.projects }} total</div>
    </div>
  </div>

  <div class="detail-grid">
    <div class="detail-main">
      <h3 class="section-title">Projects</h3>
      <div class="table-wrap" style="margin: 0 0 28px">
        <table class="table">
          <thead>
            <tr>
              <th class="pad-l" style="width: 260px">Project</th>
              <th class="num" style="width: 100px">Rate</th>
              <th style="width: 260px">Budget</th>
              <th style="width: 110px">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="project in projects" :key="project.id">
              <td class="pad-l strong">
                <Link :href="`/projects/${project.code}`" class="proj-cell" style="color: inherit">
                  <span class="proj-glyph" :class="glyphClass(project.id)">{{ project.code[0] }}</span>
                  <span>
                    {{ project.name }}
                    <span class="dim" style="margin-left: 8px; font-weight: 400">{{ project.code }}</span>
                  </span>
                </Link>
              </td>
              <td class="num">
                <template v-if="project.billable">{{ formatChf(project.rate) }}/h</template>
                <span v-else class="dim">-</span>
              </td>
              <td>
                <BudgetBar v-if="project.budget_hours > 0" :spent="project.spent_hours" :budget="project.budget_hours" unit="h" />
                <span v-else class="dim">{{ project.spent_hours.toFixed(1) }}h logged</span>
              </td>
              <td>
                <span class="badge dot" :class="project.status === 'archived' ? 'void' : project.band">
                  {{ project.status === 'archived' ? 'archived' : project.band }}
                </span>
              </td>
            </tr>
            <tr v-if="projects.length === 0">
              <td colspan="4" class="pad-l muted" style="padding: 24px">No projects yet.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <h3 class="section-title">Invoices</h3>
      <div class="table-wrap" style="margin: 0">
        <table class="table">
          <thead>
            <tr>
              <th class="pad-l" style="width: 140px">Invoice</th>
              <th class="num" style="width: 90px">Issued</th>
              <th class="num" style="width: 90px">Due</th>
              <th class="num" style="width: 80px">Hours</th>
              <th class="num" style="width: 130px">Total</th>
              <th style="width: 120px">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="invoice in invoices" :key="invoice.id">
              <td class="pad-l strong">
                <Link :href="invoice.url" class="mono-tag" style="padding: 2px 6px; color: var(--ink); border-color: var(--border-strong)">
                  #{{ invoice.number }}
                </Link>
              </td>
              <td class="num">{{ fmtDate(invoice.issued_on) }}</td>
              <td class="num" :style="{ color: invoice.overdue ? 'var(--red)' : undefined }">{{ fmtDate(invoice.due_on) }}</td>
              <td class="num">{{ invoice.hours.toFixed(1) }}h</td>
              <td class="num strong">{{ formatChf(invoice.total, 2) }}</td>
              <td>
                <span class="badge dot" :class="invoice.overdue ? 'overdue' : invoice.status">{{ invoice.overdue ? 'overdue' : invoice.status }}</span>
              </td>
            </tr>
            <tr v-if="invoices.length === 0">
              <td colspan="6" class="pad-l muted" style="padding: 24px">No invoices yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <aside class="detail-side">
      <h3 class="section-title">Contact</h3>
      <dl class="kv">
        <dt>Contact</dt><dd>{{ client.contact_name || '-' }}</dd>
        <dt>Email</dt><dd>{{ client.email || '-' }}</dd>
        <dt>Default rate</dt><dd>{{ client.default_rate ? `${formatChf(client.default_rate)}/h` : '-' }}</dd>
        <dt>VAT ID</dt><dd>{{ client.vat_id || '-' }}</dd>
      </dl>

      <h3 class="section-title" style="margin-top: 24px">Address</h3>
      <p style="font-size: var(--fs-sm); color: var(--ink-2); line-height: 1.6; margin: 0">
        <template v-if="address.length">
          <span v-for="line in address" :key="line" style="display: block">{{ line }}</span>
        </template>
        <span v-else>-</span>
      </p>

      <h3 class="section-title" style="margin-top: 24px">Recent entries</h3>
      <div>
        <EntryRow v-for="(entry, i) in recent_entries" :key="entry.id" :entry="entry" :color-index="i" />
        <div v-if="recent_entries.length === 0" class="muted" style="padding: 12px">No entries yet.</div>
      </div>
    </aside>
  </div>
</template>
