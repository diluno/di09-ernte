<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

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

const badge = computed(() => {
  const e = props.estimate;
  if (e.expired) return { cls: 'overdue', label: 'expired' };
  return { cls: { draft: 'draft', sent: 'sent', accepted: 'paid', declined: 'void' }[e.status] ?? 'draft', label: e.status };
});

function send()    { router.post(`/estimates/${props.estimate.id}/send`,    {}, { preserveScroll: true }); }
function accept()  { router.post(`/estimates/${props.estimate.id}/accept`,  {}, { preserveScroll: true }); }
function decline() {
  if (!window.confirm(`Mark estimate #${props.estimate.number} as declined?`)) return;
  router.post(`/estimates/${props.estimate.id}/decline`, {}, { preserveScroll: true });
}
function convert() {
  if (!window.confirm(`Create a draft invoice from estimate #${props.estimate.number}?`)) return;
  router.post(`/estimates/${props.estimate.id}/convert`, {}, { preserveScroll: true });
}

const EVENT_LABEL = {
  created: 'Created', sent: 'Sent', accepted: 'Accepted', declined: 'Declined',
  converted: 'Converted to invoice', pdf_generated: 'Generated PDF',
};
function fmtWhen(iso) { return new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '—'; }
</script>

<template>
  <Head :title="`Estimate #${estimate.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">~ / estimates</Link><span class="ascii-dot">/</span><span>#{{ estimate.number }}</span>
      </div>
      <h1 class="page-title">
        Estimate #{{ estimate.number }}
        <span class="meta">{{ estimate.client.name }}<span class="ascii-dot">·</span><span class="badge dot" :class="badge.cls">{{ badge.label }}</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <a :href="pdf_url" class="btn">Download PDF</a>
      <button v-if="isDraft" class="btn primary" @click="send">Send</button>
      <template v-else-if="isSent">
        <button class="btn primary" @click="accept">Accept</button>
        <button class="btn ghost" @click="decline">Decline</button>
      </template>
      <Link v-if="converted" :href="`/invoices/${converted.number}`" class="btn">Invoice #{{ converted.number }}</Link>
      <button v-else-if="isAccepted" class="btn primary" @click="convert">Convert to invoice</button>
    </div>
  </div>

  <div class="invoice-page">
    <div class="invoice-doc-wrap">
      <iframe :src="preview_url" title="Estimate document" style="width: 100%; height: 1100px; border: 1px solid var(--border); background: #fff"></iframe>
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

      <h3 class="section-title" style="margin-top: 24px">Validity</h3>
      <div style="font-size: var(--fs-sm); color: var(--ink-2)">
        <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--border)">
          <span>Valid until</span><span class="muted">{{ fmtDate(estimate.valid_until) }}</span>
        </div>
      </div>
    </aside>
  </div>
</template>
