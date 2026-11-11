import { computed, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// Shared across every useTimer() consumer (topbar chip + timer hero) so a stop or
// discard in flight disables the controls everywhere.
const pending = ref(false);

function post(url) {
  if (pending.value) return;
  router.post(url, {}, {
    preserveScroll: true,
    onStart: () => { pending.value = true; },
    onFinish: () => { pending.value = false; },
  });
}

/**
 * Reactive elapsed-seconds reader for the running timer.
 *
 * Reads `running_entry` from Inertia shared props on every page.
 * Display-only — server is authoritative for the actual entry duration.
 * `pending` is true while a stop/discard request is in flight.
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

  function stop() { post('/timer/stop'); }
  function discard() {
    if (pending.value) return;
    if (!window.confirm('Discard the running timer? The tracked time will be lost.')) return;
    post('/timer/discard');
  }

  return { running, elapsedSeconds, stop, discard, pending };
}
