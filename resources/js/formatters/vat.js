function dateString(value) {
  return value || new Date().toISOString().slice(0, 10);
}

function validOn(rate, date) {
  const day = dateString(date);
  return rate.valid_from <= day && (!rate.valid_until || rate.valid_until >= day);
}

// The single VAT rate active on the given date (0 if the catalog has no cover).
export function vatRateForDate(catalog, date) {
  const rate = [...(catalog || [])]
    .filter((r) => validOn(r, date))
    .sort((a, b) => (a.valid_from < b.valid_from ? 1 : -1))[0];
  return rate ? Number(rate.rate) : 0;
}

export function lineAmountRappen(line) {
  return Math.round(Number(line.hours) * Number(line.rate) * 100);
}

export function totalsForLines(lines, catalog, date) {
  const rate = vatRateForDate(catalog, date);
  const subtotal = lines.reduce((sum, line) => sum + lineAmountRappen(line), 0);
  const vat = Math.round((subtotal * rate) / 100);
  return { subtotal, vat, total: subtotal + vat, rate };
}
