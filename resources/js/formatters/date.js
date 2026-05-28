// Short date with year, e.g. "28 May 2026". Returns an em dash for null/empty.
export function fmtDate(d) {
  return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
}
