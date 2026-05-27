import { computed, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Reactive elapsed-seconds reader for the running timer.
 *
 * Reads `running_entry` from Inertia shared props on every page.
 * Display-only — server is authoritative for the actual entry duration.
 */
export function useTimer() {
  const page = usePage();
  const tick = ref(Date.now());

  const interval = setInterval(() => { tick.value = Date.now(); }, 1000);
  onUnmounted(() => clearInterval(interval));

  const running = computed(() => page.props.running_entry || null);

  const elapsedSeconds = computed(() => {
    if (!running.value) return 0;
    const started = new Date(running.value.started_at).getTime();
    return Math.max(0, Math.floor((tick.value - started) / 1000));
  });

  function stop()    { router.post('/timer/stop',    {}, { preserveScroll: true }); }
  function discard() { router.post('/timer/discard', {}, { preserveScroll: true }); }

  return { running, elapsedSeconds, stop, discard };
}

/** "01:23:45" from a seconds count. */
export function fmtHMS(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = Math.floor(sec % 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}
