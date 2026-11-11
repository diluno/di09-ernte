<script setup>
import { computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BudgetBar from '@/Components/BudgetBar.vue';
import EntryRow from '@/Components/EntryRow.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import { fmtMoney } from '@/formatters/money.js';
import { fmtDate, toIsoDate } from '@/formatters/date.js';
import { fmtHours } from '@/formatters/duration.js';
import { pushRecent } from '@/composables/useRecent.js';
import { usePendingAction } from '@/composables/usePendingAction.js';
import { rowClick } from '@/composables/useRowLink.js';
import { glyphClass } from '@/formatters/glyph.js';

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

const defaultContact = computed(() => props.client.contacts.find((c) => c.is_default) || props.client.contacts[0] || null);
const LOCALES = { de: 'German', en: 'English' };

onMounted(() => {
  pushRecent({ url: `/clients/${props.client.id}`, label: props.client.name });
});

// ── Actions ──
const { pending, run: act } = usePendingAction();
function archive() {
  act('delete', `/clients/${props.client.id}`, {}, {
    preserveScroll: false,
    confirm: `Archive ${props.client.name}? Projects remain but the client is hidden from active lists.`,
  });
}
const menuItems = computed(() => [
  { label: 'Edit client', href: `/clients/${props.client.id}/edit` },
  { label: 'New project', href: `/projects/create?client=${props.client.id}` },
  // Archiving cannot be undone from the app (there is no unarchive), so it sits apart.
  !props.client.archived && { separator: true },
  !props.client.archived && { label: 'Archive client…', action: archive, danger: true },
]);

function editEntry(entry) {
  router.get('/timer', { date: toIsoDate(entry.started_at), edit: entry.id });
}

function deleteEntry(entry) {
  if (window.confirm('Delete this entry?')) {
    router.delete(`/entries/${entry.id}`, { preserveScroll: true });
  }
}

// ── Formatting ──
// Stat tiles show whole francs (aggregates); rows and rates show two decimals.
const tile = (rappen) => fmtMoney(rappen, { rappen: true, whole: true });
function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }
const invoiceStatus = (inv) => inv.overdue ? 'overdue' : inv.status;
function projectStatus(p) {
  if (p.status === 'archived') return { cls: 'archived', label: 'archived' };
  if (p.budget_hours > 0 && p.band === 'over') return { cls: 'over', label: 'over' };
  if (p.budget_hours > 0 && p.band === 'warn') return { cls: 'warn', label: 'at risk' };
  return { cls: 'active', label: 'active' };
}
</script>

<template>
  <Head :title="client.name" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/clients">Clients</Link><span>/</span><span>{{ client.short_code }}</span>
        <template v-if="defaultContact"><span>·</span><span>{{ defaultContact.name || defaultContact.email }}</span></template>
      </div>
      <h1 class="page-title">
        <span class="title-with-glyph">
          <span class="proj-glyph proj-glyph--title" :class="glyphClass(client.id)" aria-hidden="true">{{ client.short_code[0] }}</span>
          <span>{{ client.name }}</span>
        </span>
        <span class="meta">{{ client.archived ? '● archived' : `● ${plural(stats.active_projects, 'active project', 'active projects')}` }}</span>
      </h1>
    </div>
    <div class="page-actions">
      <Link :href="`/invoices/new?client=${client.id}`" class="btn primary">+ Invoice</Link>
      <ActionMenu :items="menuItems" :disabled="pending" />
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Outstanding</div>
      <div class="val">{{ tile(stats.outstanding_rappen) }}<span class="unit">CHF</span></div>
      <div class="foot">
        <template v-if="stats.outstanding_count > 0">
          {{ plural(stats.outstanding_count, 'invoice', 'invoices') }} sent<template v-if="stats.overdue_count > 0"> · <span class="is-red">{{ stats.overdue_count }} overdue</span></template>
        </template>
        <template v-else>nothing open</template>
      </div>
    </div>
    <div class="stat">
      <div class="label">Paid YTD</div>
      <div class="val">{{ tile(stats.paid_ytd_rappen) }}<span class="unit">CHF</span></div>
      <div class="foot">since 1 Jan {{ new Date().getFullYear() }}</div>
    </div>
    <div class="stat">
      <div class="label">Hours YTD</div>
      <div class="val">{{ fmtHours(stats.hours_ytd) }}</div>
      <div class="foot">{{ stats.unbilled_hours > 0 ? `${fmtHours(stats.unbilled_hours)} unbilled · CHF ${tile(stats.unbilled_rappen)}` : 'nothing unbilled' }}</div>
    </div>
    <div class="stat">
      <div class="label">Projects</div>
      <div class="val">{{ stats.active_projects }}<span class="unit">active</span></div>
      <div class="foot">{{ stats.projects }} total</div>
    </div>
  </div>

  <div class="detail-grid">
    <div class="detail-main">
      <section class="detail-section">
        <h2 class="section-title">Projects <span v-if="projects.length" class="count">{{ projects.length }}</span></h2>
        <div v-if="projects.length" class="table-wrap detail-table">
          <table class="table table--docs">
            <thead>
              <tr>
                <th>Project</th>
                <th class="num" style="width: 100px">Rate</th>
                <th style="width: 220px">Hours budget</th>
                <th style="width: 120px">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="project in projects" :key="project.id"
                  class="is-clickable" :class="{ 'is-over': projectStatus(project).cls === 'over', 'is-archived': project.status === 'archived' }"
                  @click="rowClick($event, `/projects/${project.code}`)">
                <td>
                  <div class="proj-cell">
                    <span class="proj-glyph" :class="glyphClass(project.id)" aria-hidden="true">{{ project.code[0] }}</span>
                    <Link :href="`/projects/${project.code}`" class="row-link cell-trunc strong-link" :title="project.name">{{ project.name }}</Link>
                    <span class="code-dim">{{ project.code }}</span>
                  </div>
                </td>
                <td class="num">{{ !project.billable ? 'non-billable' : project.rate ? `${fmtMoney(project.rate)}/h` : '—' }}</td>
                <td>
                  <BudgetBar v-if="project.budget_hours > 0" :spent="project.spent_hours" :budget="project.budget_hours" unit="h" />
                  <span v-else class="dim">no budget · {{ fmtHours(project.spent_hours) }} logged</span>
                </td>
                <td><span class="badge dot" :class="projectStatus(project).cls">{{ projectStatus(project).label }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="section-empty">
          No projects yet. <Link :href="`/projects/create?client=${client.id}`" class="link-ink">Create one</Link>
        </div>
      </section>

      <section class="detail-section">
        <h2 class="section-title">Invoices <span v-if="invoices.length" class="count">latest {{ invoices.length }}</span></h2>
        <div v-if="invoices.length" class="table-wrap detail-table">
          <table class="table table--docs">
            <thead>
              <tr>
                <th style="width: 120px">No.</th>
                <th class="num">Issued</th>
                <th class="num">Due</th>
                <th class="num" style="width: 80px">Hours</th>
                <th class="num" style="width: 130px">CHF</th>
                <th style="width: 120px">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="invoice in invoices" :key="invoice.id" class="is-clickable" :class="`is-${invoiceStatus(invoice)}`" @click="rowClick($event, invoice.url)">
                <td class="code"><Link :href="invoice.url" class="row-link" :aria-label="`Invoice ${invoice.number}`">{{ invoice.number }}</Link></td>
                <td class="num">{{ fmtDate(invoice.issued_on) }}</td>
                <td class="num" :class="{ 'is-red': invoice.overdue }">{{ fmtDate(invoice.due_on) }}</td>
                <td class="num">{{ fmtHours(invoice.hours) }}</td>
                <td class="money">{{ fmtMoney(invoice.total) }}</td>
                <td><span class="badge dot" :class="invoiceStatus(invoice)">{{ invoiceStatus(invoice) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="section-empty">No invoices yet.</div>
      </section>

      <section class="detail-section">
        <h2 class="section-title">Recent entries</h2>
        <EntryRow v-for="(entry, i) in recent_entries" :key="entry.id" :entry="entry" :color-index="i" show-date @edit="editEntry" @delete="deleteEntry" />
        <div v-if="recent_entries.length === 0" class="section-empty">No entries yet.</div>
      </section>
    </div>

    <aside class="detail-side side-sections">
      <section>
        <h3 class="section-title">Contacts</h3>
        <div v-for="c in client.contacts" :key="c.id" class="side-row">
          <span class="side-row__line">
            <span class="cell-trunc">{{ c.name || c.email }}</span>
            <span v-if="c.is_default" class="mono-tag">default</span>
          </span>
          <span class="sub"><a :href="`mailto:${c.email}`" class="side-mail">{{ c.email }}</a><template v-if="c.role"> · {{ c.role }}</template></span>
        </div>
        <div v-if="client.contacts.length === 0" class="side-row is-muted">No contacts.</div>
      </section>

      <section>
        <h3 class="section-title">Billing</h3>
        <div class="side-row split"><span class="k">Default rate</span><span>{{ client.default_rate ? `CHF ${fmtMoney(client.default_rate)}/h` : '—' }}</span></div>
        <div class="side-row split"><span class="k">VAT ID</span><span class="mono">{{ client.vat_id || '—' }}</span></div>
        <div class="side-row split"><span class="k">Documents</span><span>{{ LOCALES[client.locale] ?? client.locale ?? '—' }}</span></div>
      </section>

      <section>
        <h3 class="section-title">Address</h3>
        <div v-if="address.length" class="side-row">
          <span v-for="line in address" :key="line" class="side-row__block">{{ line }}</span>
        </div>
        <div v-else class="side-row is-muted">No address.</div>
      </section>
    </aside>
  </div>
</template>
