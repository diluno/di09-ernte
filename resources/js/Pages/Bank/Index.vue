<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import VendorMark from '@/Components/VendorMark.vue';
import { fmtDate, fmtDayMonth } from '@/formatters/date.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  year:            { type: Number, required: true },
  quarter:         { type: Object, required: true },
  quarters:        { type: Array, required: true },
  months:          { type: Array, required: true },
  review:          { type: Array, required: true },
  gaps:            { type: Array, required: true },
  invoice_options: { type: Array, required: true },
  pool:            { type: Array, default: () => [] },
  open_receipts:   { type: Array, default: () => [] },
});

const METHOD_LABEL = { qr_reference: 'QR reference', number_in_text: 'invoice no. in text', amount: 'amount', manual: 'by hand' };
const NOTE_LABEL = { several_candidates: 'several invoices fit', amount_mismatch: 'amount differs' };
const RECEIPT_METHOD = {
  existing_prefix: 'already numbered', reference: 'payment reference', total_and_name: 'total + name', total: 'total',
  amount_and_name: 'amount + name', name_and_date: 'name + date', manual: 'by hand',
};
const RECEIPT_NOTE = { several_rows: 'several rows fit', amount_differs: 'amount differs (foreign currency)' };

function fmtChf(v) { return Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }
function invoiceLabel(i) { return `${i.number} · ${i.client ?? '—'} · ${fmtChf(i.total)}${i.status === 'paid' ? ' · paid' : ''}`; }

function setQuarter(key) { router.get('/bank', { quarter: key }, { preserveScroll: true }); }

// ── Upload ──
// PHP accepts 20 files per request by default and a quarter is 60+ daily files, so the
// files go up in batches; matching runs once at the end so it sees every new entry.
const BATCH = 10;
const fileInput = ref(null);
const busy = ref(false);
const dragging = ref(false);
const progress = ref('');
const summary = ref(null);

async function upload(fileList) {
  const files = Array.from(fileList ?? []).filter((f) => /\.(xml|csv)$/i.test(f.name));
  if (!files.length || busy.value) return;

  busy.value = true;
  const s = { files: files.length, added: 0, known: 0, rejected: [], matched: 0, proposed: 0, unmatched: 0, error: null };
  try {
    for (let i = 0; i < files.length; i += BATCH) {
      progress.value = `Reading ${Math.min(i + BATCH, files.length)} of ${files.length} files…`;
      const form = new FormData();
      files.slice(i, i + BATCH).forEach((f) => form.append('files[]', f));
      const { data } = await window.axios.post('/bank/statements', form);
      for (const f of data.files) {
        if (!f.ok) { s.rejected.push(f); continue; }
        s.added += f.lines_added;
        s.known += f.lines_known;
      }
    }
    progress.value = 'Matching payments…';
    const { data } = await window.axios.post('/bank/match');
    Object.assign(s, data);
  } catch (e) {
    s.error = e.response?.data?.message ?? e.message ?? 'Upload failed.';
  } finally {
    busy.value = false;
    progress.value = '';
    summary.value = s;
    if (fileInput.value) fileInput.value.value = '';
    router.reload();
  }
}

async function runMatching() {
  if (busy.value) return;
  busy.value = true;
  progress.value = 'Matching payments…';
  try {
    const { data } = await window.axios.post('/bank/match');
    summary.value = { files: 0, added: 0, known: 0, rejected: [], error: null, ...data };
  } catch (e) {
    summary.value = { files: 0, added: 0, known: 0, rejected: [], error: e.response?.data?.message ?? e.message };
  } finally {
    busy.value = false;
    progress.value = '';
    router.reload();
  }
}

function onDrop(event) {
  dragging.value = false;
  upload(event.dataTransfer?.files);
}

// ── Review actions ──
const chosen = reactive({});
function chosenFor(line) { return chosen[line.id] ?? line.invoice?.id ?? ''; }
function confirmMatch(line) {
  const invoiceId = chosenFor(line);
  if (!invoiceId) return;
  router.post(`/bank/lines/${line.id}/match`, { invoice_id: invoiceId }, { preserveScroll: true });
}
function ignore(line, ignored) { router.post(`/bank/lines/${line.id}/ignore`, { ignored }, { preserveScroll: true }); }
function unmatch(line) {
  if (!window.confirm(`Remove the match with invoice ${line.invoice?.number}?`)) return;
  router.post(`/bank/lines/${line.id}/unmatch`, {}, { preserveScroll: true });
}

// ── Receipts on rows ──
const pickedReceipt = reactive({});
function confirmReceipt(receipt, line) { router.post(`/bank/receipts/${receipt.id}/match`, { line_id: line.id }, { preserveScroll: true }); }
function unmatchReceipt(receipt) { router.post(`/bank/receipts/${receipt.id}/unmatch`, {}, { preserveScroll: true }); }
function attachReceipt(line) {
  const id = pickedReceipt[line.id];
  if (!id) return;
  router.post(`/bank/receipts/${id}/match`, { line_id: line.id }, { preserveScroll: true, onSuccess: () => { pickedReceipt[line.id] = ''; } });
}
function setNoReceipt(line, value) { router.post(`/bank/lines/${line.id}/no-receipt`, { no_receipt: value }, { preserveScroll: true }); }
function numberMonth(month) {
  if (!window.confirm(`Number ${month.to_number} file(s) of ${month.label} in Dropbox? Receipts get their row number as a prefix, card receipts move into Kreditkarte, and standing documents and paid invoices are added as numbered PDFs.`)) return;
  router.post(`/bank/months/${month.key}/number`, {}, { preserveScroll: true });
}
function numberLine(line) { router.post(`/bank/lines/${line.id}/number`, {}, { preserveScroll: true }); }
function numberReceipt(receipt) { router.post(`/bank/receipts/${receipt.id}/number`, {}, { preserveScroll: true }); }
function unnumberReceipt(receipt) {
  if (!window.confirm('Remove the number and move the file back to its month folder?')) return;
  router.post(`/bank/receipts/${receipt.id}/unnumber`, {}, { preserveScroll: true });
}
function confirmMonth(month) { router.post(`/bank/months/${month.key}/confirm`, {}, { preserveScroll: true }); }
function dissolveBill(section) {
  if (!window.confirm('Dissolve this card bill? Its rows become unbilled again; nothing changes in Dropbox.')) return;
  router.post(`/bank/bills/${section.bill_id}/dissolve`, {}, { preserveScroll: true });
}
const totals = computed(() => ({
  missing: props.months.reduce((n, m) => n + m.missing, 0),
  proposed: props.months.reduce((n, m) => n + m.proposed, 0),
  toNumber: props.months.reduce((n, m) => n + m.to_number, 0),
  rows: props.months.reduce((n, m) => n + m.sections.reduce((k, s) => k + s.lines.length, 0), 0),
}));
// The row has the document the accountant will look for (or needs none).
function documented(line) {
  if (line.is_credit) return line.source === 'viseca' || !line.invoice || line.invoice.filed;
  return !line.needs_receipt || line.receipts.some((r) => r.state === 'matched');
}
function amountText(line, kind) {
  const minus = kind === 'bank' ? !line.is_credit : line.is_credit;
  return `${minus ? '−' : ''}${fmtChf(line.amount)}`;
}
const matched = (line) => line.receipts.filter((r) => r.state === 'matched');
const proposals = (line) => line.receipts.filter((r) => r.state === 'proposed');

// ── Notes ──
const editing = ref(null);
const draft = ref('');
function editNote(line) {
  editing.value = line.id;
  draft.value = line.note ?? '';
  nextTick(() => document.getElementById(`note-${line.id}`)?.focus());
}
function saveNote(line) {
  if (editing.value !== line.id) return;
  editing.value = null;
  const note = draft.value.trim();
  if (note === (line.note ?? '')) return;
  router.patch(`/bank/lines/${line.id}/note`, { note: note || null }, { preserveScroll: true });
}
</script>

<template>
  <Head title="Bank" />

  <div class="page-head">
    <div>
      <div class="crumb">Bank · {{ quarter.label }}</div>
      <h1 class="page-title">Bank</h1>
    </div>
    <div class="page-actions">
      <button class="btn" :disabled="busy" @click="runMatching">Run matching</button>
      <button class="btn primary" :disabled="busy" @click="fileInput.click()"><Icon name="upload" />Upload statements</button>
      <input ref="fileInput" type="file" accept=".xml,.csv,text/xml,application/xml,text/csv" multiple hidden @change="upload($event.target.files)" />
    </div>
  </div>

  <nav v-if="quarters.length" class="quarter-bar" aria-label="Quarter">
    <button v-for="q in quarters" :key="q.key" class="chip" :aria-pressed="q.key === quarter.key" @click="setQuarter(q.key)">{{ q.label }}</button>
  </nav>

  <div class="stats">
    <div class="stat" :class="{ 'is-red': totals.missing > 0 }">
      <div class="label">Without document</div>
      <div class="val">{{ totals.missing }}</div>
      <div class="foot">of {{ totals.rows }} rows in {{ quarter.label }}</div>
    </div>
    <div class="stat">
      <div class="label">Proposals to confirm</div>
      <div class="val">{{ totals.proposed }}</div>
      <div class="foot">receipt ↔ row</div>
    </div>
    <div class="stat">
      <div class="label">Ready to number</div>
      <div class="val">{{ totals.toNumber }}</div>
      <div class="foot">written to Dropbox on your click</div>
    </div>
    <div class="stat" :class="{ 'is-red': review.length > 0 }">
      <div class="label">Payments to review</div>
      <div class="val">{{ review.length }}</div>
      <div class="foot">{{ review.length ? 'incoming, no invoice yet' : 'every payment has its invoice' }}</div>
    </div>
  </div>

  <div
    class="drop-strip" :class="{ 'is-over': dragging, 'is-busy': busy }"
    @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop"
  >
    <template v-if="busy">{{ progress }}</template>
    <template v-else-if="summary">
      <span v-if="summary.error" class="is-red">{{ summary.error }}</span>
      <span v-else class="drop-strip__result">
        <template v-if="summary.files">{{ plural(summary.files, 'file', 'files') }} read, {{ plural(summary.added, 'entry', 'entries') }} added, {{ summary.known }} already known.</template>
        {{ plural(summary.matched, 'invoice', 'invoices') }} matched, {{ summary.proposed + summary.unmatched }} to review<template v-if="summary.bills_assigned">, {{ plural(summary.bills_assigned, 'card bill', 'card bills') }} assigned</template><template v-if="summary.receipts">, {{ plural(summary.receipts.proposed, 'receipt', 'receipts') }} proposed for a row</template>.
      </span>
      <span v-for="f in summary.rejected" :key="f.name" class="is-red">{{ f.name }}: {{ f.reason }}</span>
    </template>
    <template v-else>Drop bank statements (.xml) or Viseca exports (.csv) here. Entries already imported are skipped.</template>
  </div>

  <div v-for="g in gaps" :key="g.after + g.before" class="ledger-warn">
    Statements are missing between {{ fmtDate(g.after) }} and {{ fmtDate(g.before) }}: the balances do not connect.
  </div>

  <section v-if="review.length" class="ledger">
    <header class="ledger-head">
      <h2 class="ledger-title">Payments to review</h2>
      <div class="ledger-tally">{{ plural(review.length, 'credit', 'credits') }} without a certain invoice</div>
    </header>
    <table class="table table--docs table--ledger">
      <thead>
        <tr>
          <th class="pad-l" style="width: 150px">Booked</th>
          <th>Payer</th>
          <th class="num" style="width: 150px">CHF</th>
          <th style="width: 380px">Invoice</th>
          <th class="pad-r" style="width: 190px"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="line in review" :key="line.id" class="is-flagged">
          <td class="pad-l folio">{{ fmtDate(line.booked_on) }}</td>
          <td>
            <div class="cell-trunc">{{ line.counterparty ?? '—' }}</div>
            <div class="sub cell-trunc">{{ line.remittance_text ?? 'no text from the payer' }}<template v-if="line.is_collective"> · collective booking</template></div>
            <div v-if="line.note" class="sub cell-trunc">✎ {{ line.note }}</div>
          </td>
          <td class="money">{{ fmtChf(line.amount) }}</td>
          <td>
            <select class="ledger-select" :value="chosenFor(line)" :aria-label="`Invoice for payment of ${fmtChf(line.amount)}`" @change="chosen[line.id] = Number($event.target.value) || ''">
              <option value="">Choose invoice…</option>
              <option v-for="i in invoice_options" :key="i.id" :value="i.id">{{ invoiceLabel(i) }}</option>
            </select>
            <div v-if="line.match_note" class="sub">{{ NOTE_LABEL[line.match_note] ?? line.match_note }}</div>
          </td>
          <td class="pad-r ledger-actions is-shown">
            <button class="btn sm" :disabled="!chosenFor(line)" @click="confirmMatch(line)">Confirm</button>
            <button class="btn sm ghost" title="Not an invoice payment" @click="ignore(line, true)">Ignore</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <div v-if="months.length === 0" class="ledger-empty">
    No statements for {{ quarter.label }} yet. Upload the camt.053 files from e-banking to start.
  </div>

  <section v-for="month in months" :key="month.key" class="ledger">
    <header class="ledger-head">
      <h2 class="ledger-title">{{ month.label }}</h2>
      <div class="ledger-tally">
        <span :class="{ 'is-red': month.missing > 0 }">{{ month.missing ? `${month.missing} without document` : 'every row documented' }}</span>
        <template v-if="!month.complete"> · numbers provisional until next month's statements are in</template>
      </div>
      <div class="ledger-head__actions">
        <button v-if="month.confident" class="btn sm" @click="confirmMonth(month)">Confirm {{ plural(month.confident, 'proposal', 'proposals') }}</button>
        <button v-if="month.to_number" class="btn sm primary" :disabled="!month.complete" :title="month.complete ? 'Write the numbers into Dropbox' : 'Import the following month\'s statements first'" @click="numberMonth(month)">Number {{ plural(month.to_number, 'file', 'files') }} in Dropbox</button>
      </div>
    </header>

    <template v-for="section in month.sections" :key="section.bill_id ?? 'bank'">
      <div v-if="section.kind === 'card'" class="ledger-sub">
        <span class="ledger-sub__title">Kreditkarte</span>
        <span class="ledger-tally">bill paid {{ fmtDate(section.paid_on) }} · CHF {{ fmtChf(section.total) }} · {{ plural(section.lines.length, 'row', 'rows') }}</span>
        <button class="btn sm ghost ledger-sub__action" title="Put these rows back among the unbilled charges" @click="dissolveBill(section)">Dissolve bill</button>
      </div>
      <table class="table table--docs table--ledger">
        <thead>
          <tr>
            <th class="pad-l" style="width: 84px">No.</th>
            <th style="width: 84px">{{ section.kind === 'card' ? 'Date' : 'Booked' }}</th>
            <th>Entry</th>
            <th class="num" style="width: 150px">CHF</th>
            <th style="width: 380px">Document</th>
            <th class="pad-r" style="width: 130px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="line in section.lines" :key="line.id" :class="{ 'is-open': !documented(line), 'is-flagged': line.match_state === 'unmatched' || line.match_state === 'proposed' }">
            <td class="pad-l folio">{{ line.position ? String(line.position).padStart(2, '0') : '—' }}</td>
            <td class="folio-date">{{ fmtDayMonth(line.booked_on) }}</td>
            <td>
              <div class="cell-trunc" :title="line.description">{{ line.counterparty ?? line.description ?? '—' }}</div>
              <div v-if="line.remittance_text" class="sub cell-trunc" :title="line.remittance_text">{{ line.remittance_text }}</div>
              <input
                v-if="editing === line.id" :id="`note-${line.id}`" v-model="draft" class="ledger-note-input" maxlength="1000"
                placeholder="Note for the accountant" @keydown.enter.prevent="saveNote(line)" @keydown.esc="editing = null" @blur="saveNote(line)"
              />
              <button v-else class="ledger-note" :class="{ 'is-empty': !line.note }" @click="editNote(line)">{{ line.note ? `✎ ${line.note}` : 'Add a note' }}</button>
            </td>
            <td class="money" :class="{ 'is-out': section.kind === 'bank' ? !line.is_credit : false }">
              {{ amountText(line, section.kind) }}
              <div v-if="line.original" class="sub">{{ fmtChf(line.original.amount) }} {{ line.original.currency }}</div>
            </td>
            <td class="doc">
              <!-- incoming payment ↔ invoice -->
              <template v-if="line.match_state === 'matched' && line.invoice">
                <div class="doc-line">
                  <span class="doc-mark">{{ line.invoice.filed ? '✓' : '◐' }}</span>
                  <Link :href="`/invoices/${line.invoice.number}`" class="link-ink">{{ line.invoice.number }}</Link>
                  <span class="sub cell-trunc">{{ line.invoice.client }}</span>
                </div>
                <div class="sub doc-indent">
                  matched by {{ METHOD_LABEL[line.match_method] ?? line.match_method }}<template v-if="line.invoice.filed"> · PDF in Dropbox</template>
                  <button v-if="line.creates" class="ledger-link" :disabled="!month.complete" @click="numberLine(line)">File the PDF</button>
                </div>
              </template>
              <div v-else-if="line.match_state === 'ignored'" class="doc-line is-quiet"><span class="doc-mark">–</span>not an invoice payment</div>
              <div v-else-if="line.match_state" class="doc-line is-red"><span class="doc-mark">●</span>payment needs review</div>
              <div v-else-if="line.is_fee" class="doc-line is-quiet"><span class="doc-mark">–</span>bank fee</div>
              <div v-else-if="line.is_credit && section.kind === 'card'" class="doc-line is-quiet"><span class="doc-mark">–</span>credit, not numbered</div>

              <!-- debit or card charge ↔ receipt -->
              <template v-if="!line.is_credit || line.receipts.length">
                <div v-for="r in matched(line)" :key="r.id" class="doc-line">
                  <span class="doc-mark">{{ r.numbered ? '✓' : '◐' }}</span>
                  <VendorMark :name="r.label" :logo="r.logo_url" :size="16" />
                  <Link :href="`/receipts/${r.id}`" class="link-ink cell-trunc" :title="r.filename">{{ r.label }}</Link>
                  <span class="sub">{{ r.numbered ? 'numbered' : 'confirmed' }}</span>
                  <span class="doc-tools">
                    <button v-if="!r.numbered" class="ledger-link" :disabled="!month.complete" title="Write the number into Dropbox now" @click="numberReceipt(r)">Number</button>
                    <button v-if="!r.numbered" class="ledger-link" title="This receipt does not belong to this row" @click="unmatchReceipt(r)">Remove</button>
                    <button v-else-if="r.numbered_by_ernte" class="ledger-link" title="Take the number off in Dropbox" @click="unnumberReceipt(r)">Undo number</button>
                  </span>
                </div>
                <div v-for="r in proposals(line)" :key="r.id" class="doc-line is-proposal">
                  <span class="doc-mark">?</span>
                  <VendorMark :name="r.label" :logo="r.logo_url" :size="16" />
                  <Link :href="`/receipts/${r.id}`" class="cell-trunc" :title="r.filename">{{ r.label }}</Link>
                  <span class="sub">{{ RECEIPT_NOTE[r.note] ?? RECEIPT_METHOD[r.method] ?? r.method }}</span>
                  <span class="doc-tools is-shown">
                    <button class="btn sm" @click="confirmReceipt(r, line)">Confirm</button>
                    <button class="ledger-link" title="Not this receipt" @click="unmatchReceipt(r)">Reject</button>
                  </span>
                </div>
                <div v-if="line.creates && line.creates.kind === 'standing'" class="doc-line">
                  <span class="doc-mark">◐</span>
                  <button class="ledger-link" :disabled="!month.complete" @click="numberLine(line)">Copy {{ line.creates.label }}</button>
                </div>
                <template v-else-if="line.needs_receipt && !matched(line).length && !proposals(line).length">
                  <div class="doc-line is-quiet">
                    <span class="doc-mark">○</span>
                    <span>{{ line.card_bill === 'missing' ? 'card bill not imported' : 'no document yet' }}</span>
                    <select v-if="open_receipts.length" v-model="pickedReceipt[line.id]" class="ledger-select ledger-pick" aria-label="Attach a receipt" @change="attachReceipt(line)">
                      <option value="">Attach…</option>
                      <option v-for="o in open_receipts" :key="o.id" :value="o.id">{{ o.label }}</option>
                    </select>
                  </div>
                </template>
                <div v-else-if="line.no_receipt" class="doc-line is-quiet"><span class="doc-mark">–</span>no document needed</div>
              </template>
            </td>
            <td class="pad-r ledger-actions">
              <button v-if="line.match_state === 'matched' && line.invoice" class="btn sm ghost" @click="unmatch(line)">Undo</button>
              <button v-else-if="line.match_state === 'ignored'" class="btn sm ghost" @click="ignore(line, false)">Restore</button>
              <button v-else-if="line.needs_receipt && !line.receipts.length && !line.creates" class="btn sm ghost" title="This row never has a document" @click="setNoReceipt(line, true)">None needed</button>
              <button v-else-if="line.no_receipt" class="btn sm ghost" @click="setNoReceipt(line, false)">Needs one</button>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </section>

  <section v-if="pool.length" class="ledger">
    <header class="ledger-head">
      <h2 class="ledger-title">Card charges not yet billed</h2>
      <div class="ledger-tally">{{ plural(pool.length, 'charge', 'charges') }} · numbered once the bill's bank debit is imported</div>
    </header>
    <table class="table table--docs table--ledger">
      <tbody>
        <tr v-for="line in pool" :key="line.id">
          <td class="pad-l folio" style="width: 168px">{{ fmtDate(line.booked_on) }}</td>
          <td><div class="cell-trunc">{{ line.counterparty }}</div></td>
          <td class="money" style="width: 150px">{{ fmtChf(line.amount) }}<div v-if="line.original" class="sub">{{ fmtChf(line.original.amount) }} {{ line.original.currency }}</div></td>
          <td class="pad-r doc" style="width: 510px">
            <div v-for="r in line.receipts" :key="r.id" class="doc-line"><span class="doc-mark">{{ r.state === 'proposed' ? '?' : '◐' }}</span><Link :href="`/receipts/${r.id}`" class="link-ink cell-trunc">{{ r.label }}</Link></div>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
