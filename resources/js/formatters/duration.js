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

// Render minutes as "1h 30m" / "2h" / "45m" / "0m".
export function formatDuration(minutes) {
  const total = Math.max(0, Math.round(minutes));
  const h = Math.floor(total / 60);
  const m = total % 60;
  if (h && m) return `${h}h ${m}m`;
  if (h) return `${h}h`;
  return `${m}m`;
}
