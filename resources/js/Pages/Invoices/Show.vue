<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoice: { type: Object, required: true },
  events: { type: Array, required: true },
  linked_entries: { type: Object, required: true },
  preview_url: { type: String, required: true },
  pdf_url: { type: String, required: true },
});

const isDraft = computed(() => props.invoice.status === 'draft');
const isSent = computed(() => props.invoice.status === 'sent');
const statusLabel = computed(() => props.invoice.overdue ? 'overdue' : props.invoice.status);

function send()    { router.post(`/invoices/${props.invoice.id}/send`,  {}, { preserveScroll: true }); }
function markPaid(){ router.post(`/invoices/${props.invoice.id}/paid`,  {}, { preserveScroll: true }); }
function voidIt()  { router.post(`/invoices/${props.invoice.id}/void`,  {}, { preserveScroll: true }); }

const EVENT_LABEL = {
  created: 'Created', sent: 'Sent', reminded: 'Reminder sent', paid: 'Marked paid',
  pdf_generated: 'Generated PDF', voided: 'Voided', overdue_stamped: 'Marked overdue',
};
function fmtWhen(iso) { return new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">~ / invoices</Link><span class="ascii-dot">/</span><span>#{{ invoice.number }}</span>
      </div>
      <h1 class="page-title">
        Invoice #{{ invoice.number }}
        <span class="meta">{{ invoice.client.name }}<span class="ascii-dot">·</span><span class="badge dot" :class="statusLabel">{{ statusLabel }}</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <a :href="pdf_url" class="btn">Download PDF</a>
      <button v-if="isDraft" class="btn primary" @click="send">Send</button>
      <button v-else-if="isSent" class="btn primary" @click="markPaid">Mark paid</button>
      <button v-if="invoice.status !== 'paid' && invoice.status !== 'void'" class="btn ghost" @click="voidIt">Void</button>
    </div>
  </div>

  <div class="invoice-page">
    <div class="invoice-doc-wrap">
      <iframe :src="preview_url" title="Invoice document" style="width: 100%; height: 1100px; border: 1px solid var(--border); background: #fff"></iframe>
    </div>

    <aside class="invoice-side">
      <h3 class="section-title">Activity</h3>
      <div style="display: flex; flex-direction: column; gap: 10px; font-size: var(--fs-sm)">
        <div v-for="(e, i) in events" :key="i" style="display: flex; gap: 10px; align-items: baseline">
          <span style="color: var(--ink-4); font-size: 10px; min-width: 96px">{{ fmtWhen(e.occurred_at) }}</span>
          <span style="color: var(--ink-2)">{{ EVENT_LABEL[e.kind] ?? e.kind }}</span>
        </div>
        <div v-if="events.length === 0" class="muted">No activity yet.</div>
      </div>

      <h3 class="section-title" style="margin-top: 24px">Linked entries</h3>
      <div style="font-size: var(--fs-sm); color: var(--ink-2)">
        <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--border)">
          <span>{{ linked_entries.hours }}h</span><span class="muted">{{ linked_entries.count }} entries</span>
        </div>
      </div>
    </aside>
  </div>
</template>
