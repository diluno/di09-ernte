import { computed, unref } from 'vue';
import { totalsForLines, totalsForRate } from '@/formatters/vat.js';

const read = (source) => (typeof source === 'function' ? source() : unref(source));

/**
 * Live totals for a document's line editor, in integer rappen, mirroring the
 * server's arithmetic (App\Support\LineTotals).
 *
 * @param lines  ref/getter of [{ hours, rate (CHF/h), vat_exempt? }]
 * @param options.rate      ref/getter/number — fixed document VAT rate in percent, or
 * @param options.catalog   ref/getter/array — VAT rate catalog, with
 * @param options.date      ref/getter/string — the date the rate is looked up for
 * @returns ComputedRef<{ subtotal, vat, rounding, total, rate }>
 */
export function useDocumentTotals(lines, { rate, catalog, date } = {}) {
  return computed(() => {
    const list = read(lines) ?? [];
    return rate !== undefined
      ? totalsForRate(list, read(rate))
      : totalsForLines(list, read(catalog), read(date));
  });
}
