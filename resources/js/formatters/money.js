// Single source of truth for money display.
//
//   fmtMoney(486.45)                     -> "486.45"
//   fmtMoney(1193600, { rappen: true })  -> "11’936.00"
//   fmtMoney(486.45, { currency: true }) -> "CHF 486.45"
//   fmtMoney(11936.4, { whole: true })   -> "11’936"   (dashboard stat tiles only)
//
// Swiss grouping with a typographic apostrophe (what de-CH Intl renders), always
// two decimals unless `whole` is set. Never use `whole` for a single document total.
const GROUP = '’';
const MINUS = '−';

export function fmtMoney(value, { rappen = false, whole = false, currency = false } = {}) {
  let amount = Number(value ?? 0);
  if (!Number.isFinite(amount)) amount = 0;
  if (rappen) amount /= 100;

  const fixed = Math.abs(amount).toFixed(whole ? 0 : 2);
  const [int, frac] = fixed.split('.');
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, GROUP);
  const negative = amount < 0 && Number(fixed) !== 0;

  return `${currency ? 'CHF ' : ''}${negative ? MINUS : ''}${grouped}${frac ? `.${frac}` : ''}`;
}

// A percentage/rate without trailing zeros: 8.1 -> "8.1", 8 -> "8".
export function fmtPercent(rate) {
  return Number(rate ?? 0).toFixed(2).replace(/\.?0+$/, '');
}

// CHF (possibly a decimal string from an input) -> integer rappen.
export function toRappen(chf) {
  return Math.round((Number(chf) || 0) * 100);
}
