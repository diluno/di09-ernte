<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import ActionMenu from '@/Components/ActionMenu.vue';
import { fmtDateShort, fmtDateTime } from '@/formatters/date.js';
import { fmtMoney } from '@/formatters/money.js';
import { fmtHours } from '@/formatters/duration.js';
import { usePendingAction, recipientList } from '@/composables/usePendingAction.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  estimate: { type: Object, required: true },
  events: { type: Array, required: true },
  preview_url: { type: String, required: true },
  pdf_url: { type: String, required: true },
});

const isDraft = computed(() => props.estimate.status === 'draft');
const isSent = computed(() => props.estimate.status === 'sent');
const isAccepted = computed(() => props.estimate.status === 'accepted');
const converted = computed(() => props.estimate.converted_invoice);

// Expired is virtual (sent + past valid_until) and wins over the stored status.
const statusLabel = computed(() => props.estimate.expired ? 'expired' : props.estimate.status);
const STATUS_MARK = { draft: '○', sent: '◐', expired: '●', accepted: '●', declined: '●' };
const title = computed(() => props.estimate.title || `Estimate #${props.estimate.number}`);

// ── Actions ──
// One request at a time: every action button is disabled while one is in flight.
const { pending, pendingKey, run: act } = usePendingAction();
const base = computed(() => `/estimates/${props.estimate.id}`);
const recipients = computed(() => recipientList(props.estimate.recipients, '(no recipients set)'));

function send() {
  act('post', `${base.value}/send`, {}, {
    key: 'send',
    confirm: `Email estimate #${props.estimate.number} to ${recipients.value}?\n\nThis stamps the validity date and locks the estimate from editing. It cannot be undone.`,
  });
}
function markSent() {
  act('post', `${base.value}/mark-sent`, {}, {
    confirm: `Mark estimate #${props.estimate.number} as sent? It will be locked from editing.`,
  });
}
function accept() { act('post', `${base.value}/accept`, {}, { key: 'accept' }); }
function decline() {
  act('post', `${base.value}/decline`, {}, { confirm: `Mark estimate #${props.estimate.number} as declined?` });
}
function convert() {
  act('post', `${base.value}/convert`, {}, {
    key: 'convert',
    confirm: `Create a draft invoice from estimate #${props.estimate.number}?`,
  });
}
function destroy() {
  act('delete', base.value, {}, {
    preserveScroll: false,
    confirm: `Delete estimate #${props.estimate.number}? This permanently removes it and its line items.`,
  });
}

// ── More-actions menu (rendered by ActionMenu) ──
// The primary button in the head carries the next step for the status; the rest lives here.
const menuItems = computed(() => [
  isDraft.value && { label: 'Edit', href: `/estimates/${props.estimate.number}/edit` },
  isDraft.value && { label: 'Mark as sent', action: markSent },
  isSent.value && { label: 'Decline', action: decline },
  { separator: true },
  { label: 'Delete…', action: destroy, danger: true },
].filter(Boolean).filter((item, i, all) => !(item.separator && i === 0)));

// ── Formatting ──
function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }

const issuedFoot = computed(() => {
  if (!props.estimate.issued_on) return 'not sent yet';
  const ch = props.estimate.issued_channel;
  return ch === 'manual' ? 'marked as sent' : ch === 'email' ? 'sent by email' : 'issued';
});
const validFoot = computed(() => {
  const e = props.estimate;
  if (!e.valid_until) return '30 days from sending';
  if (e.expired) return e.days_expired > 0 ? `expired ${plural(e.days_expired, 'day', 'days')} ago` : 'expired today';
  if (e.days_left !== null && e.days_left !== undefined) return `${plural(e.days_left, 'day', 'days')} left`;
  return e.status === 'accepted' ? 'accepted' : e.status === 'declined' ? 'declined' : '—';
});
const hoursFoot = computed(() => {
  const e = props.estimate;
  const parts = [e.sections.length > 0 ? plural(e.sections.length, 'section', 'sections') : plural(e.lines.length, 'line', 'lines')];
  if (e.avg_rate !== null && e.avg_rate !== undefined) parts.push(`${fmtMoney(e.avg_rate)} CHF/h`);
  return parts.join(' · ');
});

// ── Activity ──
const EVENT_LABEL = {
  created: 'Created', sent: 'Sent', accepted: 'Accepted', declined: 'Declined',
  converted: 'Converted to invoice', pdf_generated: 'Generated PDF',
};
const fmtWhen = (iso) => fmtDateTime(iso, { short: true });
function eventDetail(e) {
  const to = e.payload?.email_to;
  if (to?.length) return `to ${Array.isArray(to) ? to.join(', ') : to}`;
  if (e.kind === 'sent' && e.payload?.manual) return 'marked manually';
  if (e.kind === 'converted' && e.payload?.invoice_number) return `invoice #${e.payload.invoice_number}`;
  return null;
}
</script>

<template>
  <Head :title="`Estimate #${estimate.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">Estimates</Link><span>/</span><span>{{ estimate.number }}</span><span>·</span><span class="estimates-crumb-client">{{ estimate.client.name }}</span>
      </div>
      <h1 class="page-title">
        <span>{{ title }}</span>
        <span class="meta" :class="{ 'is-red': estimate.expired }">
          {{ STATUS_MARK[statusLabel] }} {{ statusLabel }}<template v-if="estimate.expired && estimate.days_expired > 0"> · {{ plural(estimate.days_expired, 'day', 'days') }}</template><template v-if="converted"> · invoiced</template>
        </span>
      </h1>
    </div>

    <div class="page-actions">
      <a :href="pdf_url" class="btn"><Icon name="download" />PDF</a>
      <Link v-if="converted" :href="`/invoices/${converted.number}`" class="btn">Invoice #{{ converted.number }} →</Link>
      <button v-if="isDraft" class="btn primary" :disabled="pending" @click="send">{{ pendingKey === 'send' ? 'Sending…' : 'Send by email' }}</button>
      <button v-else-if="isSent" class="btn primary" :disabled="pending" @click="accept"><Icon name="check" />{{ pendingKey === 'accept' ? 'Saving…' : 'Accept' }}</button>
      <button v-else-if="isAccepted && !converted" class="btn primary" :disabled="pending" @click="convert">{{ pendingKey === 'convert' ? 'Converting…' : 'Convert to invoice' }}</button>
      <ActionMenu :items="menuItems" :disabled="pending" />
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Total</div>
      <div class="val">{{ fmtMoney(estimate.total) }}<span class="unit">CHF</span></div>
      <div class="foot">incl. {{ estimate.vat_rate }}% VAT</div>
    </div>
    <div class="stat">
      <div class="label">Issued</div>
      <div class="val">{{ fmtDateShort(estimate.issued_on) }}</div>
      <div class="foot">{{ issuedFoot }}</div>
    </div>
    <div class="stat" :class="{ 'is-red': estimate.expired }">
      <div class="label">Valid until</div>
      <div class="val">{{ fmtDateShort(estimate.valid_until) }}</div>
      <div class="foot">{{ validFoot }}</div>
    </div>
    <div class="stat">
      <div class="label">Hours</div>
      <div class="val">{{ fmtHours(estimate.hours) }}</div>
      <div class="foot">{{ hoursFoot }}</div>
    </div>
  </div>

  <div class="invoice-page">
    <div class="invoice-doc-wrap">
      <iframe :src="preview_url" title="Estimate document" class="invoice-sheet"></iframe>
    </div>

    <aside class="invoice-side">
      <section>
        <h3 class="section-title">Recipients</h3>
        <div v-for="r in estimate.recipients" :key="r.email" class="side-row">
          {{ r.name || r.email }}<span v-if="r.name" class="sub">{{ r.email }}</span>
        </div>
        <div v-if="estimate.recipients.length === 0" class="side-row is-muted">No recipients</div>
      </section>

      <section v-if="converted">
        <h3 class="section-title">Invoice</h3>
        <div class="side-row split">
          <span>Converted to #{{ converted.number }}</span>
          <Link :href="`/invoices/${converted.number}`" class="link-ink">View →</Link>
        </div>
      </section>

      <section v-if="estimate.sections.length">
        <h3 class="section-title">Scope</h3>
        <div v-for="(s, i) in estimate.sections" :key="i" class="side-row split">
          <span class="cell-trunc">{{ [s.label, s.title].filter(Boolean).join(' · ') || '—' }}<span class="sub">{{ fmtHours(s.hours) }} · {{ plural(s.lines_count, 'line', 'lines') }}</span></span>
          <span class="mono estimates-side-amount">{{ fmtMoney(s.amount) }}</span>
        </div>
      </section>

      <section>
        <h3 class="section-title">Activity</h3>
        <!-- Events are newest first; an expiry is newer than anything recorded. -->
        <div v-if="estimate.expired" class="side-row grid is-red">
          <span class="when">{{ fmtDateShort(estimate.valid_until) }}</span>
          <span>Validity ran out</span>
        </div>
        <div v-for="(e, i) in events" :key="i" class="side-row grid">
          <span class="when">{{ fmtWhen(e.occurred_at) }}</span>
          <span>
            {{ EVENT_LABEL[e.kind] ?? e.kind }}
            <span v-if="eventDetail(e)" class="sub">{{ eventDetail(e) }}</span>
          </span>
        </div>
        <div v-if="events.length === 0" class="side-row is-muted">No activity yet.</div>
      </section>
    </aside>
  </div>
</template>
