<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import { fmtDate, parseDate } from '@/formatters/date.js';
import { fmtMoney } from '@/formatters/money.js';
import { usePendingAction } from '@/composables/usePendingAction.js';
import { rowClick } from '@/composables/useRowLink.js';

defineOptions({ layout: AppLayout });

defineProps({
  // [{ id, client:{id,name}, title, resolved_title, cadence, next_run_on, run_late,
  //    auto_send, paused, invoices_count, subtotal_per_run (rappen, excl. VAT) }]
  schedules: { type: Array, default: () => [] },
  // { active, active_auto_send, paused, next_run: {on, client, late} | null, yearly_rappen }
  stats: { type: Object, required: true },
});

const CADENCE_LABEL = {
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  'half-yearly': 'Half-yearly',
  yearly: 'Yearly',
};

const { pending, run: act } = usePendingAction();

function pause(s) { act('post', `/recurring-invoices/${s.id}/pause`); }
function resume(s) { act('post', `/recurring-invoices/${s.id}/resume`); }
function run(s) {
  const what = s.auto_send
    ? `Generate the next invoice for ${s.client.name} now and email it to the client? Auto-send is on — this cannot be undone.`
    : `Generate the next invoice for ${s.client.name} now? It will be created as a draft.`;
  act('post', `/recurring-invoices/${s.id}/run`, {}, { confirm: what });
}
function destroy(s) {
  act('delete', `/recurring-invoices/${s.id}`, {}, { confirm: 'Delete this recurring schedule? Past invoices are kept.' });
}

const editUrl = (s) => `/recurring-invoices/${s.id}/edit`;

function itemsFor(s) {
  return [
    { label: 'Edit', href: editUrl(s) },
    s.paused ? { label: 'Resume', action: () => resume(s) } : { label: 'Pause', action: () => pause(s) },
    !s.paused && { label: 'Generate now…', action: () => run(s) },
    { separator: true },
    { label: 'Delete…', action: () => destroy(s), danger: true },
  ];
}

function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }

// Stat tile: "09 Nov" in the 40px value, the year (when not this year) in the foot.
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function dayMonth(iso) {
  const d = parseDate(iso);
  return d ? `${String(d.getDate()).padStart(2, '0')} ${MONTHS[d.getMonth()]}` : '—';
}
function otherYear(iso) {
  const d = parseDate(iso);
  return d && d.getFullYear() !== new Date().getFullYear() ? d.getFullYear() : null;
}
</script>

<template>
  <Head title="Recurring invoices" />

  <div class="page-head">
    <div>
      <div class="crumb">Recurring · {{ plural(schedules.length, 'schedule', 'schedules') }}</div>
      <h1 class="page-title">Recurring invoices</h1>
    </div>
    <div class="page-actions">
      <Link href="/recurring-invoices/new" class="btn primary">+ New schedule</Link>
    </div>
  </div>

  <div v-if="!schedules.length" class="empty-state">
    <h2 class="empty-state__title">No recurring schedules yet</h2>
    <p>Bill retainers, hosting or maintenance automatically every month, quarter or year.</p>
    <div class="empty-state__actions"><Link href="/recurring-invoices/new" class="btn primary">New schedule</Link></div>
  </div>

  <template v-else>
    <div class="stats">
      <div class="stat">
        <div class="label">Active</div>
        <div class="val">{{ stats.active }}</div>
        <div class="foot">{{ stats.active_auto_send }} auto-send · {{ stats.active - stats.active_auto_send }} as draft</div>
      </div>
      <div class="stat">
        <div class="label">Paused</div>
        <div class="val">{{ stats.paused }}</div>
        <div class="foot">{{ stats.paused ? 'not generating' : 'none paused' }}</div>
      </div>
      <div class="stat" :class="{ 'is-red': stats.next_run?.late }">
        <div class="label">Next run</div>
        <div class="val">{{ stats.next_run ? dayMonth(stats.next_run.on) : '—' }}</div>
        <div class="foot">
          <span v-if="stats.next_run" class="stat-foot-trunc" :title="stats.next_run.client">
            <template v-if="stats.next_run.late">run date passed · </template>{{ stats.next_run.client }}<template v-if="otherYear(stats.next_run.on)"> · {{ otherYear(stats.next_run.on) }}</template>
          </span>
          <template v-else>no active schedule</template>
        </div>
      </div>
      <div class="stat">
        <div class="label">Yearly volume</div>
        <div class="val">{{ fmtMoney(stats.yearly_rappen, { rappen: true, whole: true }) }}<span class="unit">CHF</span></div>
        <div class="foot">excl. VAT · active schedules</div>
      </div>
    </div>

    <div class="table-wrap">
      <table class="table table--docs table--recurring">
        <thead>
          <tr>
            <th class="pad-l" style="width: 280px">Client</th>
            <th>Next invoice</th>
            <th style="width: 100px">Cadence</th>
            <th class="num" style="width: 110px">Next run</th>
            <th class="num" style="width: 120px">CHF / run</th>
            <th class="num" style="width: 80px">Invoices</th>
            <th style="width: 110px">Status</th>
            <th class="row-actions"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in schedules" :key="s.id" class="is-clickable" :class="{ 'is-paused': s.paused }" @click="rowClick($event, editUrl(s))">
            <td class="pad-l trunc-ink" :title="s.client.name">
              <Link :href="editUrl(s)" class="row-link" :aria-label="`Edit schedule for ${s.client.name}${s.resolved_title ? ` — ${s.resolved_title}` : ''}`">{{ s.client.name }}</Link>
            </td>
            <td class="subject" :title="s.resolved_title || undefined">{{ s.resolved_title || '—' }}</td>
            <td class="dim">{{ CADENCE_LABEL[s.cadence] ?? s.cadence }}</td>
            <td class="num" :class="{ 'is-red': s.run_late }" :title="s.run_late ? 'The run date has passed and no invoice was generated yet' : undefined">{{ fmtDate(s.next_run_on) }}</td>
            <td class="money" title="Excl. VAT">{{ fmtMoney(s.subtotal_per_run, { rappen: true }) }}</td>
            <td class="num">{{ s.invoices_count }}</td>
            <td>
              <span v-if="s.paused" class="badge dot paused">paused</span>
              <span v-else-if="s.auto_send" class="mono-tag" title="Each invoice is emailed when it is generated">auto-send</span>
              <span v-else class="badge dot draft" title="Each invoice waits as a draft for review">draft</span>
            </td>
            <td class="row-actions">
              <ActionMenu size="sm" :items="itemsFor(s)" :disabled="pending" :label="`Actions for ${s.client.name}`" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</template>
