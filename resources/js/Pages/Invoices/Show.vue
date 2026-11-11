<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import { fmtDate, fmtDateShort, fmtDateTime } from '@/formatters/date.js';
import { fmtMoney } from '@/formatters/money.js';
import { fmtHours } from '@/formatters/duration.js';
import { usePendingAction, recipientList } from '@/composables/usePendingAction.js';

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
// One request at a time: every action button is disabled while one is in flight.
const { pending, pendingKey, run: act } = usePendingAction();
const recipients = computed(() => recipientList(props.invoice.recipients, '(no recipients set)'));

function send() {
  act('post', `/invoices/${props.invoice.id}/send`, {}, {
    key: 'send',
    confirm: `Email invoice #${props.invoice.number} to ${recipients.value}?\n\nThis sends the invoice with its QR bill and locks it from editing. It cannot be undone.`,
  });
}
function markSent() {
  act('post', `/invoices/${props.invoice.id}/mark-sent`, {}, {
    confirm: `Mark invoice #${props.invoice.number} as sent? It will be locked from editing.`,
  });
}
function markPaid() { act('post', `/invoices/${props.invoice.id}/paid`, {}, { key: 'paid' }); }
function voidIt() {
  act('post', `/invoices/${props.invoice.id}/void`, {}, {
    confirm: `Void invoice #${props.invoice.number}? Linked entries will become billable again.`,
  });
}
function pauseReminders()  { act('post', `/invoices/${props.invoice.id}/pause-reminders`); }
function resumeReminders() { act('post', `/invoices/${props.invoice.id}/resume-reminders`); }
function remindNow() {
  act('post', `/invoices/${props.invoice.id}/remind`, {}, {
    confirm: `Email a payment reminder for invoice #${props.invoice.number} to ${recipients.value} now?`,
  });
}
function destroy() {
  act('delete', `/invoices/${props.invoice.id}`, {}, {
    preserveScroll: false,
    // A void invoice already released its entries when it was voided.
    confirm: isDraft.value
      ? `Delete draft invoice #${props.invoice.number}? This permanently removes it and its line items. Linked time entries return to unbilled.`
      : `Delete void invoice #${props.invoice.number}? It was never sent, so this permanently removes it and its line items. The number is not reused.`,
  });
}

// ── More-actions menu (rendered by ActionMenu) ──
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
  // Only drafts and never-sent void invoices can be deleted (Invoice::isDeletable); the rest stay on record.
  if (props.invoice.deletable) items.push({ label: 'Delete…', action: destroy, danger: true });
  return items;
});

// ── Formatting ──
function year(iso)     { return iso ? new Date(iso).getFullYear() : ''; }
function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }

const issuedFoot = computed(() => {
  if (!props.invoice.issued_on) return 'not issued';
  const channel = props.invoice.issued_channel === 'manual' ? 'marked as sent' : props.invoice.issued_channel === 'email' ? 'sent by email' : null;
  return channel ?? 'issued';
});
const dueFoot = computed(() => {
  const parts = [];
  if (props.invoice.terms_days !== null) parts.push(`terms ${props.invoice.terms_days}`);
  if (props.invoice.overdue) parts.push(`${plural(props.invoice.days_late, 'day', 'days')} late`);
  return parts.join(' · ') || '—';
});
const hoursFoot = computed(() => {
  const parts = [props.linked_entries.count > 0 ? plural(props.linked_entries.count, 'linked entry', 'linked entries') : 'manual lines'];
  if (props.invoice.avg_rate !== null) parts.push(`${fmtMoney(props.invoice.avg_rate)} CHF/h`);
  return parts.join(' · ');
});
const totalHours = computed(() => props.invoice.lines.reduce((a, l) => a + l.hours, 0));

// ── Activity ──
const EVENT_LABEL = {
  created: 'Created', sent: 'Sent', reminded: 'Reminder sent', paid: 'Marked paid',
  pdf_generated: 'Generated PDF', voided: 'Voided', overdue_stamped: 'Marked overdue',
  reminders_paused: 'Reminders paused', reminders_resumed: 'Reminders resumed',
  payment_matched: 'Bank payment linked', reopened: 'Reopened',
};
const MATCH_METHOD = { qr_reference: 'QR reference', number_in_text: 'invoice no. in text', amount: 'amount', manual: 'by hand' };
const fmtWhen = (iso) => fmtDateTime(iso, { short: true });
function eventDetail(e) {
  // email_to was a single string before multi-contact support; an array since.
  const to = e.payload?.email_to;
  if (to?.length) return `to ${Array.isArray(to) ? to.join(', ') : to}`;
  if (e.kind === 'sent' && e.payload?.manual) return 'marked manually';
  if (e.kind === 'overdue_stamped') return 'due date passed';
  if (e.kind === 'paid' && e.payload?.statement_line_id) return 'from bank statement';
  if (e.kind === 'reopened') return 'bank match removed';
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

    <div class="page-actions">
      <a :href="pdf_url" class="btn"><Icon name="download" />PDF</a>
      <button v-if="isDraft" class="btn primary" :disabled="pending" @click="send">{{ pendingKey === 'send' ? 'Sending…' : 'Send by email' }}</button>
      <button v-else-if="isSent" class="btn primary" :disabled="pending" @click="markPaid"><Icon name="check" />{{ pendingKey === 'paid' ? 'Saving…' : 'Mark paid' }}</button>
      <ActionMenu v-if="menuItems.length" :items="menuItems" :disabled="pending" />
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Total</div>
      <div class="val">{{ fmtMoney(invoice.total) }}<span class="unit">CHF</span></div>
      <div class="foot">incl. {{ invoice.vat_rate }}% VAT</div>
    </div>
    <div class="stat">
      <div class="label">Issued</div>
      <div class="val">{{ fmtDateShort(invoice.issued_on) }}</div>
      <div class="foot">{{ issuedFoot }}</div>
    </div>
    <div class="stat" :class="{ 'is-red': invoice.overdue }">
      <div class="label">Due</div>
      <div class="val">{{ fmtDateShort(invoice.due_on) }}</div>
      <div class="foot">{{ dueFoot }}</div>
    </div>
    <div class="stat">
      <div class="label">Hours</div>
      <div class="val">{{ fmtHours(totalHours) }}</div>
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

      <section v-if="invoice.payments.length">
        <h3 class="section-title">Payment</h3>
        <div v-for="p in invoice.payments" :key="p.bank_ref" class="side-row">
          Paid {{ fmtDate(p.booked_on) }} · {{ fmtMoney(p.amount) }} CHF
          <span class="sub">
            <Link :href="`/bank?quarter=${year(p.booked_on)}-Q${Math.floor(new Date(p.booked_on).getMonth() / 3) + 1}`" class="link-ink">bank entry {{ p.bank_ref }}</Link>
            · matched by {{ MATCH_METHOD[p.method] ?? p.method }}
          </span>
        </div>
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
          <span class="when">{{ fmtDateShort(next_reminder_on) }}</span>
          <span>Next reminder scheduled</span>
        </div>
        <div v-if="events.length === 0" class="side-row is-muted">No activity yet.</div>
      </section>

      <section v-if="linked_entries.count > 0">
        <h3 class="section-title">Linked time</h3>
        <div class="side-row split">
          <span class="cell-trunc">{{ linked_entries.project?.name ?? invoice.project_name ?? 'Several projects' }}</span>
          <span class="mono" style="font-size: 12px">{{ fmtHours(linked_entries.hours) }}</span>
        </div>
        <div class="side-row split meta" style="border-bottom: 0">
          <span>{{ plural(linked_entries.count, 'entry', 'entries') }}<template v-if="linked_entries.from"> · {{ fmtDateShort(linked_entries.from) }}<template v-if="linked_entries.to !== linked_entries.from"> – {{ fmtDateShort(linked_entries.to) }}</template></template></span>
          <Link v-if="linked_entries.project" :href="`/projects/${linked_entries.project.code}`">View →</Link>
        </div>
      </section>
    </aside>
  </div>
</template>
