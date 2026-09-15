const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const pad = (n) => String(n).padStart(2, '0');

// "14 Aug" — fixed three-letter months (Intl gives "Sept" in en-GB). Em dash for null.
export function fmtDayMonth(d) {
  if (!d) return '—';
  const x = new Date(d);
  return `${pad(x.getDate())} ${MONTHS[x.getMonth()]}`;
}

// Short date with year, e.g. "28 May 2026". Returns an em dash for null/empty.
export function fmtDate(d) {
  return d ? `${fmtDayMonth(d)} ${new Date(d).getFullYear()}` : '—';
}
