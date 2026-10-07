<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import VendorMark from '@/Components/VendorMark.vue';
import { fmtDate } from '@/formatters/date.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  receipts:          { type: Object, required: true },
  counts:            { type: Object, required: true },
  months:            { type: Array, required: true },
  filters:           { type: Object, required: true },
  quarter:           { type: Object, default: null },
  quarters:          { type: Array, default: () => [] },
  dropbox_connected: { type: Boolean, required: true },
  inbox_folder:      { type: String, required: true },
});

function fmtAmount(v) { return Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

const TABS = computed(() => [
  { id: 'all',       label: 'All',             count: props.counts.all },
  { id: 'attention', label: 'Needs attention', count: props.counts.attention, red: true },
  { id: 'unfiled',   label: 'Not filed',       count: props.counts.unfiled },
]);
function go(params) {
  router.get('/receipts', { quarter: props.filters.quarter || undefined, filter: props.filters.filter, month: props.filters.month || undefined, ...params }, { preserveState: true, preserveScroll: true });
}

// One word per state, in the badge language of the invoice list: ✓ done, ◐ in progress, ● needs you.
function status(r) {
  if (r.filing_status === 'missing') return { text: 'missing in Dropbox', kind: 'attention' };
  if (r.is_duplicate) return { text: 'duplicate', kind: 'attention' };
  if (r.filing_status === 'failed') return { text: r.source === 'inbox' ? 'stuck in inbox' : 'not filed', kind: 'attention' };
  if (r.extraction_status === 'pending') return { text: 'reading', kind: 'working' };
  if (r.filing_status === 'inbox') return { text: 'sorting', kind: 'working' };
  if (r.filing_status === 'pending') return { text: props.dropbox_connected ? 'filing' : 'waiting for Dropbox', kind: 'working' };
  if (r.extraction_status === 'failed') return { text: 'unreadable', kind: 'attention' };
  if (r.needs_attention) return { text: 'check fields', kind: 'attention' };
  return { text: 'filed', kind: 'filed' };
}
function paidBy(r) {
  if (r.number_prefix) return `No. ${r.number_prefix}`;
  if (r.match_state === 'matched') return 'row confirmed';
  if (r.match_state === 'proposed') return 'row proposed';
  return r.paid_by_card ? '' : '—';
}

// ── Upload: one file per request, so large photos never exceed the request limit ──
const fileInput = ref(null);
const dragging = ref(false);
const uploads = ref([]);
const busy = ref(false);

async function upload(fileList) {
  const files = Array.from(fileList ?? []);
  if (!files.length || busy.value) return;
  busy.value = true;
  uploads.value = files.map((f) => ({ name: f.name, state: 'waiting', detail: null, id: null }));

  for (let i = 0; i < files.length; i++) {
    const row = uploads.value[i];
    row.state = 'uploading';
    try {
      const form = new FormData();
      form.append('file', files[i]);
      const { data } = await window.axios.post('/receipts', form);
      row.state = data.status;
      row.id = data.receipt.id;
    } catch (e) {
      row.state = 'error';
      row.detail = e.response?.status === 413
        ? 'The file is larger than the server accepts.'
        : (e.response?.data?.errors?.file?.[0] ?? e.response?.data?.message ?? 'Upload failed.');
    }
  }

  busy.value = false;
  if (fileInput.value) fileInput.value.value = '';
  router.reload();
}
function onDrop(event) { dragging.value = false; upload(event.dataTransfer?.files); }

const UPLOAD_LABEL = { waiting: 'waiting', uploading: 'uploading…', created: 'uploaded', duplicate: 'already uploaded', error: 'failed' };

// While receipts are being read or filed in the background, keep the list fresh.
const working = computed(() => props.receipts.data.some((r) => r.extraction_status === 'pending' || r.filing_status === 'inbox' || (r.filing_status === 'pending' && props.dropbox_connected)));

function checkInbox() { router.post('/receipts/check-inbox', {}, { preserveScroll: true }); }
let timer = null;
watch(working, (on) => {
  clearInterval(timer);
  if (on) timer = setInterval(() => { if (!busy.value) router.reload({ only: ['receipts', 'counts', 'months', 'quarters'] }); }, 4000);
}, { immediate: true });
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
  <Head title="Receipts" />

  <div class="page-head">
    <div>
      <div class="crumb">Receipts · {{ quarter ? quarter.label : 'all quarters' }} · {{ counts.all }} in view</div>
      <h1 class="page-title">Receipts</h1>
    </div>
    <div class="page-actions">
      <button v-if="dropbox_connected" class="btn" :title="`Pick up new scans from ${inbox_folder} in Dropbox`" @click="checkInbox">Check Dropbox inbox</button>
      <button class="btn primary" :disabled="busy" @click="fileInput.click()"><Icon name="upload" />Upload receipts</button>
      <input ref="fileInput" type="file" accept="application/pdf,image/*" multiple hidden @change="upload($event.target.files)" />
    </div>
  </div>

  <nav v-if="quarters.length" class="quarter-bar" aria-label="Quarter">
    <button v-for="q in quarters" :key="q.key" class="chip" :aria-pressed="filters.quarter === q.key" @click="go({ quarter: q.key, month: undefined })">{{ q.label }}</button>
    <button class="chip" :aria-pressed="filters.quarter === 'all'" @click="go({ quarter: 'all', month: undefined })">All quarters</button>
  </nav>

  <div class="stats">
    <div class="stat">
      <div class="label">Receipts</div>
      <div class="val">{{ counts.all }}</div>
      <div class="foot">{{ counts.numbered }} numbered</div>
    </div>
    <div class="stat" :class="{ 'is-red': counts.attention > 0 }">
      <div class="label">Need attention</div>
      <div class="val">{{ counts.attention }}</div>
      <div class="foot">{{ counts.attention ? 'unreadable, undated or not filed' : 'all read and filed' }}</div>
    </div>
    <div class="stat">
      <div class="label">Not in Dropbox yet</div>
      <div class="val">{{ counts.unfiled }}</div>
      <div class="foot">{{ dropbox_connected ? 'being read or sorted' : 'Dropbox is not connected' }}</div>
    </div>
    <div class="stat">
      <div class="label">Without a bank row</div>
      <div class="val">{{ counts.unmatched }}</div>
      <div class="foot"><Link href="/bank" class="link-ink">match them on the Bank page</Link></div>
    </div>
  </div>

  <div
    class="drop-strip" :class="{ 'is-over': dragging }"
    @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop"
  >
    <template v-if="uploads.length">
      <div v-for="u in uploads" :key="u.name" class="drop-strip__file" :class="{ 'is-red': u.state === 'error' }">
        <span class="cell-trunc">{{ u.name }}</span>
        <span>
          <Link v-if="u.id" :href="`/receipts/${u.id}`" class="link-ink">{{ UPLOAD_LABEL[u.state] }}</Link>
          <template v-else>{{ u.detail ?? UPLOAD_LABEL[u.state] }}</template>
        </span>
      </div>
    </template>
    <template v-else>
      Drop PDFs or photos here. Scans saved to <code>{{ inbox_folder }}</code> in Dropbox are picked up every five minutes.
    </template>
    <span v-if="!dropbox_connected" class="is-red">
      Dropbox is not connected, so receipts wait here unfiled. <Link href="/settings" class="link-ink">Connect it in Settings</Link>
    </span>
  </div>

  <div class="filter-row">
    <button v-for="tab in TABS" :key="tab.id" class="chip" :class="{ 'is-red': tab.red && tab.count > 0 }" :aria-pressed="filters.filter === tab.id" @click="go({ filter: tab.id })">
      {{ tab.label }} <span class="dim">{{ tab.count }}</span>
    </button>
    <label v-if="months.length" class="rc-month">
      <span>Folder</span>
      <select class="ledger-select" :value="filters.month ?? ''" @change="go({ month: $event.target.value || undefined })">
        <option value="">All months</option>
        <option v-for="m in months" :key="m" :value="m">{{ m }}</option>
      </select>
    </label>
  </div>

  <div class="table-wrap">
    <table class="table table--docs">
      <thead>
        <tr>
          <th class="pad-l rc-wide" style="width: 150px">Date</th>
          <th style="width: 30%">Vendor</th>
          <th class="rc-wide">File</th>
          <th class="num" style="width: 160px">Total</th>
          <th class="rc-wide" style="width: 130px">Folder</th>
          <th class="rc-wide" style="width: 150px">Paid by</th>
          <th class="pad-r" style="width: 190px">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in receipts.data" :key="r.id" :class="{ 'is-overdue': status(r).kind === 'attention' }" @click="router.visit(`/receipts/${r.id}`)">
          <td class="pad-l code rc-wide">{{ fmtDate(r.document_date) }}</td>
          <td class="subject"><div class="vendor-name"><VendorMark :name="r.vendor" :logo="r.logo_url" /><span>{{ r.vendor ?? '—' }}</span></div></td>
          <td class="trunc rc-wide" :title="r.filename ?? r.original_name">{{ r.filename ?? r.original_name }}</td>
          <td class="money">
            <template v-if="r.total !== null">{{ fmtAmount(r.total) }}<span class="rc-unit">{{ r.currency }}</span></template>
            <template v-else>—</template>
          </td>
          <td class="code rc-wide">{{ r.folder ?? '—' }}</td>
          <td class="dim rc-wide">
            <span class="rc-paid" :title="r.paid_by_card ? 'Paid by credit card' : undefined">
              <Icon v-if="r.paid_by_card" name="credit-card" class="rc-card" />
              <span class="sr-only" v-if="r.paid_by_card">Credit card · </span>{{ paidBy(r) }}
            </span>
          </td>
          <td class="pad-r"><span class="badge dot" :class="status(r).kind">{{ status(r).text }}</span></td>
        </tr>
        <tr v-if="receipts.data.length === 0">
          <td colspan="7" class="pad-l muted" style="padding: 32px 40px; cursor: default">
            {{ counts.all ? 'No receipts match this filter.' : 'No receipts yet. Upload a PDF, or scan one into the Dropbox inbox.' }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <Pagination :paginator="receipts" />
</template>

<style scoped>
.rc-month { margin-left: auto; display: flex; align-items: center; gap: 10px; color: var(--ink-3); }
.rc-month .ledger-select { width: auto; }
.rc-paid { display: inline-flex; align-items: center; gap: 8px; }
.rc-card { font-size: 15px; color: var(--ink); }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.rc-unit { margin-left: 6px; font-size: 12px; font-weight: 400; color: var(--ink-3); }
@media (max-width: 900px) {
  .rc-wide { display: none; }
}
</style>
