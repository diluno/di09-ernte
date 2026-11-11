// Single source of truth for date display. Standard form is "09 Nov 2026";
// fixed three-letter English months (Intl gives "Sept" in en-GB).
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const DASH = '—';

const pad = (n) => String(n).padStart(2, '0');

// Parse a Date, ISO timestamp or bare "YYYY-MM-DD". A bare date is anchored on
// local midnight so it can't slip a day across timezones. Returns null if unreadable.
export function parseDate(value) {
  if (value == null || value === '') return null;
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value));
  const d = m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

// "YYYY-MM-DD" from LOCAL date parts (toISOString() is UTC: yesterday just after midnight).
export function toIsoDate(date) {
  const d = parseDate(date) ?? new Date();
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function todayIso() {
  return toIsoDate(new Date());
}

// "09 Nov 2026" — the standard display. Em dash for null/empty.
export function fmtDate(value) {
  const d = parseDate(value);
  return d ? `${pad(d.getDate())} ${MONTHS[d.getMonth()]} ${d.getFullYear()}` : DASH;
}

// "09 Nov" when the date is in the current year, otherwise "09 Nov 2025".
// Only for places where the year is unambiguous from context.
export function fmtDateShort(value) {
  const d = parseDate(value);
  if (!d) return DASH;
  if (d.getFullYear() !== new Date().getFullYear()) return fmtDate(d);
  return `${pad(d.getDate())} ${MONTHS[d.getMonth()]}`;
}

// "14:05" (24h, local).
export function fmtTime(value) {
  const d = parseDate(value);
  return d ? `${pad(d.getHours())}:${pad(d.getMinutes())}` : DASH;
}

// "09 Nov 2026, 14:05"; with { short: true } the year is dropped for the current year.
export function fmtDateTime(value, { short = false } = {}) {
  const d = parseDate(value);
  return d ? `${short ? fmtDateShort(d) : fmtDate(d)}, ${fmtTime(d)}` : DASH;
}

// "Monday"
export function fmtWeekday(value) {
  const d = parseDate(value);
  return d ? WEEKDAYS[d.getDay()] : DASH;
}
