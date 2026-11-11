// Form state for invoice / recurring-invoice line items (flat list, unlike the
// sectioned estimate scope in estimateScope.js).
import { toRappen } from '@/formatters/money.js';

let nextKey = 0;

/** A line with a unique `key`: { key, description, hours, rate (CHF/h), vat_exempt, ...values }. */
export function makeLineItem(values = {}) {
  return { key: `line-${nextKey++}`, description: '', hours: 0, rate: 0, vat_exempt: false, ...values };
}

/** Request payload for `lines` (rate in rappen). `vat: false` omits the vat_exempt flag. */
export function lineItemsPayload(lines, { vat = true } = {}) {
  return lines.map((l) => ({
    description: l.description,
    hours: Number(l.hours),
    rate_rappen: toRappen(l.rate),
    ...(vat ? { vat_exempt: Boolean(l.vat_exempt) } : {}),
  }));
}
