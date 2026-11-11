// Mirror of App\Support\BillingPeriod for previews: which period a recurring run
// bills. Pure "YYYY-MM-DD" arithmetic on calendar parts, so no timezone can shift a day.

const MONTHS = { monthly: 1, quarterly: 3, 'half-yearly': 6, yearly: 12 };
const pad = (n) => String(n).padStart(2, '0');

function parts(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso ?? ''));
  return m ? { y: Number(m[1]), m: Number(m[2]), d: Number(m[3]) } : null;
}
const iso = ({ y, m, d }) => `${y}-${pad(m)}-${pad(d)}`;
const daysInMonth = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate();

// One cadence step forward, keeping anchorDay clamped to the target month's length.
function advance(cadence, p, anchorDay) {
  const total = p.y * 12 + (p.m - 1) + (MONTHS[cadence] ?? 1);
  const y = Math.floor(total / 12);
  const m = (total % 12) + 1;
  return { y, m, d: Math.min(anchorDay, daysInMonth(y, m)) };
}

function dayBefore({ y, m, d }) {
  if (d > 1) return { y, m, d: d - 1 };
  const prev = m === 1 ? { y: y - 1, m: 12 } : { y, m: m - 1 };
  return { ...prev, d: daysInMonth(prev.y, prev.m) };
}

/** The first run on or after `fromIso`, stepping from `startIso` by the cadence (no backfill). */
export function nextRunOnOrAfter(cadence, startIso, fromIso, anchorDay = null) {
  let next = parts(startIso);
  if (!next) return null;
  const anchor = anchorDay ?? next.d;
  for (let guard = 0; iso(next) < fromIso && guard < 1200; guard++) next = advance(cadence, next, anchor);
  return iso(next);
}

/** Last day of the period a run on `runIso` bills: the day before the next run. */
export function periodEnd(cadence, runIso, anchorDay = null) {
  const run = parts(runIso);
  if (!run) return null;
  return iso(dayBefore(advance(cadence, run, anchorDay ?? run.d)));
}
