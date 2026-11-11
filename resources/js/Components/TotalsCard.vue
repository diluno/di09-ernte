<script setup>
import { fmtMoney, fmtPercent } from '@/formatters/money.js';

// Subtotal / VAT / rounding / total grid for the document editors.
// `totals` is the value of useDocumentTotals(): integer rappen + the VAT rate in percent.
defineProps({
  totals: { type: Object, required: true }, // { subtotal, vat, rounding, total, rate }
  // Overrides totals.rate in the label (documents with a rate snapshot).
  rate: { type: [Number, String], default: null },
});

const money = (rappen) => fmtMoney(rappen, { rappen: true, currency: true });
</script>

<template>
  <div class="invoice-totals totals-card">
    <div class="label">Subtotal</div><div class="v">{{ money(totals.subtotal) }}</div>
    <div class="label">VAT {{ fmtPercent(rate ?? totals.rate) }}%</div><div class="v">{{ money(totals.vat) }}</div>
    <template v-if="totals.rounding">
      <div class="label">Rounding</div><div class="v">{{ money(totals.rounding) }}</div>
    </template>
    <div class="grand-l">Total</div><div class="v grand">{{ money(totals.total) }}</div>
  </div>
</template>
