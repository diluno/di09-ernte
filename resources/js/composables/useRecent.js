export function pushRecent(entry) {
  try {
    const list = JSON.parse(localStorage.getItem('ernte.recent') ?? '[]');
    const dedup = [entry, ...list.filter((e) => e.url !== entry.url)].slice(0, 5);
    localStorage.setItem('ernte.recent', JSON.stringify(dedup));
  } catch {}
}
