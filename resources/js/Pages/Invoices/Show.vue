<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { fmtDayMonth as dayMonth } from '@/formatters/date.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoice: { type: Object, required: true },
  events: { type: Array, required: true },
  linked_entries: { type: Object, required: true },
  next_reminder_on: { type: String, default: null },
  preview_url: { type: String, required: true },
  pdf_url: { type: String, required: true },
});

const isDraft = computed(() => props.invoice.status === 'draft');
const isSent = computed(() => props.invoice.status === 'sent');
const isClosed = computed(() => props.invoice.status === 'paid' || props.invoice.status === 'void');
const statusLabel = computed(() => props.invoice.overdue ? 'overdue' : props.invoice.status);
const STATUS_MARK = { draft: '○', sent: '◐', overdue: '●', paid: '●', void: '●' };
const title = computed(() => props.invoice.title || `Invoice #${props.invoice.number}`);

// ── Actions ──
function send()    { router.post(`/invoices/${props.invoice.id}/send`,  {}, { preserveScroll: true }); }
function markSent() {
  if (!window.confirm(`Mark invoice #${props.invoice.number} as sent? It will be locked from editing.`)) return;
  router.post(`/invoices/${props.invoice.id}/mark-sent`, {}, { preserveScroll: true });
}
function markPaid(){ router.post(`/invoices/${props.invoice.id}/paid`,  {}, { preserveScroll: true }); }
function voidIt()  {
  if (!window.confirm(`Void invoice #${props.invoice.number}? Linked entries will become billable again.`)) return;
  router.post(`/invoices/${props.invoice.id}/void`,  {}, { preserveScroll: true });
}
function pauseReminders()  { router.post(`/invoices/${props.invoice.id}/pause-reminders`,  {}, { preserveScroll: true }); }
function resumeReminders() { router.post(`/invoices/${props.invoice.id}/resume-reminders`, {}, { preserveScroll: true }); }
function remindNow()       { router.post(`/invoices/${props.invoice.id}/remind`, {}, { preserveScroll: true }); }
function destroy() {
  if (!window.confirm(`Delete invoice #${props.invoice.number}? This permanently removes it and its line items. Linked time entries return to unbilled.`)) return;
  router.delete(`/invoices/${props.invoice.id}`);
}

// ── More-actions menu ──
const menuOpen = ref(false);
const menuRef = ref(null);
function onDocMouseDown(e) { if (menuRef.value && !menuRef.value.contains(e.target)) menuOpen.value = false; }
function onDocKeydown(e)   { if (e.key === 'Escape') menuOpen.value = false; }
watch(menuOpen, (open) => {
  const fn = open ? 'addEventListener' : 'removeEventListener';
  document[fn]('mousedown', onDocMouseDown);
  document[fn]('keydown', onDocKeydown);
});
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocMouseDown);
  document.removeEventListener('keydown', onDocKeydown);
});
function run(fn) { menuOpen.value = false; fn(); }

const menuItems = computed(() => {
  const items = [];
  if (isDraft.value) {
    items.push({ label: 'Edit', href: `/invoices/${props.invoice.number}/edit` });
    items.push({ label: 'Mark as sent', action: markSent });
  }
  if (isSent.value) {
    items.push(props.invoice.reminders_paused
      ? { label: 'Resume reminders', action: resumeReminders }
      : { label: 'Pause reminders', action: pauseReminders });
    items.push({ label: 'Send reminder now', action: remindNow });
  }
  if (!isClosed.value) items.push({ label: 'Void invoice', action: voidIt });
  items.push({ label: 'Delete…', action: destroy, danger: true });
  return items;
});

// ── Formatting ──
function fmtChf0(v) { return Math.round(v).toLocaleString('de-CH'); }
function fmtChf(v)  { return Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function year(iso)     { return iso ? new Date(iso).getFullYear() : ''; }
function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }

const issuedFoot = computed(() => {
  if (!props.invoice.issued_on) return 'not issued';
  const channel = props.invoice.issued_channel === 'manual' ? 'marked as sent' : props.invoice.issued_channel === 'email' ? 'sent by email' : null;
  return [year(props.invoice.issued_on), channel].filter(Boolean).join(' · ');
});
const dueFoot = computed(() => {
  const parts = [];
  if (props.invoice.terms_days !== null) parts.push(`terms ${props.invoice.terms_days}`);
  if (props.invoice.overdue) parts.push(`${plural(props.invoice.days_late, 'day', 'days')} late`);
  return parts.join(' · ') || '—';
});
const hoursFoot = computed(() => {
  const parts = [props.linked_entries.count > 0 ? plural(props.linked_entries.count, 'linked entry', 'linked entries') : 'manual lines'];
  if (props.invoice.avg_rate !== null) parts.push(`${fmtChf0(props.invoice.avg_rate)} CHF/h`);
  return parts.join(' · ');
});
const totalHours = computed(() => props.invoice.lines.reduce((a, l) => a + l.hours, 0));

// ── Activity ──
const EVENT_LABEL = {
  created: 'Created', sent: 'Sent', reminded: 'Reminder sent', paid: 'Marked paid',
  pdf_generated: 'Generated PDF', voided: 'Voided', overdue_stamped: 'Marked overdue',
  reminders_paused: 'Reminders paused', reminders_resumed: 'Reminders resumed',
};
function fmtWhen(iso) {
  const d = new Date(iso);
  return `${dayMonth(iso)} ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
}
function eventDetail(e) {
  // email_to was a single string before multi-contact support; an array since.
  const to = e.payload?.email_to;
  if (to?.length) return `to ${Array.isArray(to) ? to.join(', ') : to}`;
  if (e.kind === 'sent' && e.payload?.manual) return 'marked manually';
  if (e.kind === 'overdue_stamped') return 'due date passed';
  return null;
}
</script>

<template>
  <Head :title="`Invoice #${invoice.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">Invoices</Link><span>/</span><span>{{ invoice.number }}</span><span>·</span><span>{{ invoice.client.name }}</span>
        <template v-if="invoice.recurring"><span>·</span><Link :href="`/recurring-invoices/${invoice.recurring.id}/edit`" title="Generated from recurring schedule">↻ recurring</Link></template>
      </div>
      <h1 class="page-title">
        <span>{{ title }}</span>
        <span class="meta" :class="{ 'is-red': invoice.overdue }">
          {{ STATUS_MARK[statusLabel] }} {{ statusLabel }}<template v-if="invoice.overdue"> · {{ plural(invoice.days_late, 'day', 'days') }}</template><template v-if="invoice.reminders_paused"> · reminders paused</template>
        </span>
      </h1>
    </div>

    <div ref="menuRef" class="page-actions">
      <a :href="pdf_url" class="btn"><Icon name="download" />PDF</a>
      <button v-if="isDraft" class="btn primary" @click="send">Send by email</button>
      <button v-else-if="isSent" class="btn primary" @click="markPaid"><Icon name="check" />Mark paid</button>
      <button class="btn icon" aria-label="More actions" aria-haspopup="menu" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
        <Icon name="more-horizontal" style="font-size: 14px" />
      </button>
      <div v-if="menuOpen" class="menu" role="menu">
        <template v-for="item in menuItems" :key="item.label">
          <Link v-if="item.href" :href="item.href" class="menu-item" role="menuitem">{{ item.label }}</Link>
          <button v-else class="menu-item" :class="{ 'is-danger': item.danger }" role="menuitem" @click="run(item.action)">{{ item.label }}</button>
        </template>
      </div>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Total</div>
      <div class="val">{{ fmtChf0(invoice.total) }}<span class="unit">CHF</span></div>
      <div class="foot">incl. {{ invoice.vat_rate }}% VAT</div>
    </div>
    <div class="stat">
      <div class="label">Issued</div>
      <div class="val">{{ dayMonth(invoice.issued_on) }}</div>
      <div class="foot">{{ issuedFoot }}</div>
    </div>
    <div class="stat" :class="{ 'is-red': invoice.overdue }">
      <div class="label">Due</div>
      <div class="val">{{ dayMonth(invoice.due_on) }}</div>
      <div class="foot">{{ dueFoot }}</div>
    </div>
    <div class="stat">
      <div class="label">Hours</div>
      <div class="val">{{ totalHours.toFixed(1) }}</div>
      <div class="foot">{{ hoursFoot }}</div>
    </div>
  </div>

  <div class="invoice-page">
    <div class="invoice-doc-wrap">
      <iframe :src="preview_url" title="Invoice document" class="invoice-sheet"></iframe>
    </div>

    <aside class="invoice-side">
      <section>
        <h3 class="section-title">Recipients</h3>
        <div v-for="r in invoice.recipients" :key="r.email" class="side-row">
          {{ r.name || r.email }}<span v-if="r.name" class="sub">{{ r.email }}</span>
        </div>
        <div v-if="invoice.recipients.length === 0" class="side-row is-muted">No recipients</div>
      </section>

      <section>
        <h3 class="section-title">Activity</h3>
        <div v-for="(e, i) in events" :key="i" class="side-row grid" :class="{ 'is-red': e.kind === 'overdue_stamped' }">
          <span class="when">{{ fmtWhen(e.occurred_at) }}</span>
          <span>
            {{ EVENT_LABEL[e.kind] ?? e.kind }}
            <span v-if="eventDetail(e)" class="sub">{{ eventDetail(e) }}</span>
          </span>
        </div>
        <div v-if="next_reminder_on" class="side-row grid is-muted">
          <span class="when">{{ dayMonth(next_reminder_on) }}</span>
          <span>Next reminder scheduled</span>
        </div>
        <div v-if="events.length === 0" class="side-row is-muted">No activity yet.</div>
      </section>

      <section v-if="linked_entries.count > 0">
        <h3 class="section-title">Linked time</h3>
        <div class="side-row split">
          <span class="cell-trunc">{{ linked_entries.project?.name ?? invoice.project_name ?? '—' }}</span>
          <span class="mono" style="font-size: 12px">{{ linked_entries.hours.toFixed(1) }} h</span>
        </div>
        <div class="side-row split meta" style="border-bottom: 0">
          <span>{{ plural(linked_entries.count, 'entry', 'entries') }}<template v-if="linked_entries.from"> · {{ dayMonth(linked_entries.from) }}<template v-if="linked_entries.to !== linked_entries.from"> – {{ dayMonth(linked_entries.to) }}</template></template></span>
          <Link v-if="linked_entries.project" :href="`/projects/${linked_entries.project.code}`">View →</Link>
        </div>
      </section>
    </aside>
  </div>
</template>
