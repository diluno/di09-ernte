export function formatChf(value, fractionDigits = 0) {
  return 'CHF ' + Number(value ?? 0).toLocaleString('de-CH', {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  });
}
