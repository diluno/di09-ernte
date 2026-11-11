import { todayIso } from '@/formatters/date.js';

function validOn(rate, date) {
  const day = date || todayIso();
  return rate.valid_from <= day && (!rate.valid_until || rate.valid_until >= day);
}

// The single VAT rate active on the given date (0 if the catalog has no cover).
export function vatRateForDate(catalog, date) {
  const rate = [...(catalog || [])]
    .filter((r) => validOn(r, date))
    .sort((a, b) => (a.valid_from < b.valid_from ? 1 : -1))[0];
  return rate ? Number(rate.rate) : 0;
}

// Round to an integer the way PHP's round() does for the values we see: snap
// float noise first (1.005 * 100 = 100.49999…) so a mathematical .5 rounds up.
function roundInt(value) {
  return Math.round(Number(value.toPrecision(15)));
}

// Line amount in integer rappen, mirroring the server (InvoiceBuilder): hours are
// rounded to 2 decimals, then multiplied by the integer rappen rate. Done in
// integers (centi-hours × rappen) so the preview matches to the rappen.
// `line.rate` is CHF/h (as typed in the editors).
export function lineAmountRappen(line) {
  const centiHours = roundInt((Number(line.hours) || 0) * 100);
  const rateRappen = roundInt((Number(line.rate) || 0) * 100);
  return Math.round((centiHours * rateRappen) / 100);
}

// Commercial rounding of a rappen amount to the nearest 5 rappen.
export function roundTotalRappen(exactRappen) {
  return Math.round(exactRappen / 5) * 5;
}

// Mirrors App\Support\LineTotals::compute for a known document rate (percent).
export function totalsForRate(lines, rate) {
  const pct = Number(rate) || 0;
  const subtotal = lines.reduce((sum, line) => sum + lineAmountRappen(line), 0);
  const vatBase = lines.reduce((sum, line) => sum + (line.vat_exempt ? 0 : lineAmountRappen(line)), 0);
  const vat = roundInt((vatBase * pct) / 100);
  const total = roundTotalRappen(subtotal + vat);
  return { subtotal, vat, rounding: total - (subtotal + vat), total, rate: pct };
}

export function totalsForLines(lines, catalog, date) {
  return totalsForRate(lines, vatRateForDate(catalog, date));
}
