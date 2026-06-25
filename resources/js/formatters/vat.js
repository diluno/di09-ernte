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

// Commercial rounding of a rappen amount to the nearest 5 rappen.
export function roundTotalRappen(exactRappen) {
  return Math.round(exactRappen / 5) * 5;
}

export function totalsForLines(lines, catalog, date) {
  const rate = vatRateForDate(catalog, date);
  const subtotal = lines.reduce((sum, line) => sum + lineAmountRappen(line), 0);
  const vat = Math.round((subtotal * rate) / 100);
  const total = roundTotalRappen(subtotal + vat);
  return { subtotal, vat, rounding: total - (subtotal + vat), total, rate };
}
