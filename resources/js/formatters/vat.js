function dateString(value) {
  return value || new Date().toISOString().slice(0, 10);
}

function validOn(rate, date) {
  const day = dateString(date);
  return rate.valid_from <= day && (!rate.valid_until || rate.valid_until >= day);
}

export function activeVatRates(catalog, date) {
  return [...(catalog || [])]
    .filter((rate) => validOn(rate, date))
    .sort((a, b) => {
      if (a.is_default !== b.is_default) return a.is_default ? -1 : 1;
      const order = ['standard', 'reduced', 'special', 'exempt'];
      return order.indexOf(a.code) - order.indexOf(b.code);
    });
}

export function defaultVatCode(catalog, date) {
  return activeVatRates(catalog, date).find((rate) => rate.is_default)?.code ?? 'standard';
}

export function vatRateForCode(catalog, code, date, fallback = 0) {
  const rate = activeVatRates(catalog, date).find((item) => item.code === code);
  return rate ? Number(rate.rate) : Number(fallback);
}

export function vatLabelForCode(catalog, code, date) {
  const rate = activeVatRates(catalog, date).find((item) => item.code === code);
  return rate ? `${rate.label} · ${Number(rate.rate).toFixed(2).replace(/\.?0+$/, '')}%` : code;
}

export function lineAmountRappen(line) {
  return Math.round(Number(line.hours) * Number(line.rate) * 100);
}

export function totalsForLines(lines, catalog, date) {
  const bases = new Map();
  let subtotal = 0;

  for (const line of lines) {
    const amount = lineAmountRappen(line);
    const code = line.vat_code || (line.vat_exempt ? 'exempt' : defaultVatCode(catalog, date));
    const rate = vatRateForCode(catalog, code, date, line.vat_rate ?? 0);
    const key = rate.toFixed(2);

    subtotal += amount;
    bases.set(key, (bases.get(key) ?? 0) + amount);
  }

  const breakdown = [...bases.entries()]
    .sort(([a], [b]) => Number(a) - Number(b))
    .map(([rate, base]) => ({
      rate: Number(rate),
      base_rappen: base,
      vat_rappen: Math.round(base * Number(rate) / 100),
    }));

  const vat = breakdown.reduce((sum, row) => sum + row.vat_rappen, 0);

  return { subtotal, vat, total: subtotal + vat, breakdown };
}
