// Parse a flexible duration string into total MINUTES, or null if it can't be
// read or is <= 0. Accepts:
//   "1:30"            -> 90   (H:MM)
//   "1h 30m" / "1h30m" / "1h30" / "1h" / "30m" / "90m"
//   "1.5" / "1.5h" / "2" / "2h"  -> a bare number is decimal HOURS (1.5 = 90, 2 = 120)
export function parseDuration(input) {
  if (input == null) return null;
  const s = String(input).trim().toLowerCase().replace(/\s+/g, '');
  if (s === '') return null;

  let minutes = null;

  // H:MM
  let m = s.match(/^(\d+):([0-5]?\d)$/);
  if (m) {
    minutes = parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
  }

  // unit form with an h and/or m: 1h30m, 1h, 30m, 90m, 1h30
  if (minutes === null && /[hm]/.test(s)) {
    m = s.match(/^(?:(\d+)h)?(?:(\d+)m?)?$/);
    if (m && (m[1] !== undefined || m[2] !== undefined)) {
      minutes = (m[1] ? parseInt(m[1], 10) : 0) * 60 + (m[2] ? parseInt(m[2], 10) : 0);
    }
  }

  // bare decimal hours, optional trailing h: 1.5, 1.5h, 2
  if (minutes === null) {
    m = s.match(/^(\d+(?:\.\d+)?)h?$/);
    if (m) minutes = Math.round(parseFloat(m[1]) * 60);
  }

  if (minutes === null || !Number.isFinite(minutes) || minutes <= 0) return null;
  return minutes;
}

const pad = (n) => String(n).padStart(2, '0');

// Decimal hours with one decimal: "4.0h", "0.3h". The standard for tables, stats and lists.
export function fmtHours(hours) {
  const h = Number(hours ?? 0);
  return `${(Number.isFinite(h) ? h : 0).toFixed(1)}h`;
}

export function fmtHoursFromSeconds(seconds) {
  return fmtHours(Number(seconds ?? 0) / 3600);
}

// "01:30" (HH:MM) — only for the live timer clock and the timer page's day totals.
export function fmtClock(seconds) {
  const total = Math.max(0, Math.floor(Number(seconds ?? 0)));
  return `${pad(Math.floor(total / 3600))}:${pad(Math.floor((total % 3600) / 60))}`;
}

// "01:23:45" — the live timer clock with seconds.
export function fmtClockSeconds(seconds) {
  const total = Math.max(0, Math.floor(Number(seconds ?? 0)));
  return `${fmtClock(total)}:${pad(total % 60)}`;
}

// Minutes as an "H:MM" string that parseDuration reads back (duration inputs).
export function minutesToInput(minutes) {
  const total = Math.max(0, Math.round(Number(minutes ?? 0)));
  return `${Math.floor(total / 60)}:${pad(total % 60)}`;
}
