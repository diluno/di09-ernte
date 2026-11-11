import { computed, onBeforeUnmount, onMounted, ref, unref } from 'vue';
import { router } from '@inertiajs/vue3';

const MESSAGE = 'You have unsaved changes. Leave this page and discard them?';

/**
 * Warn before losing unsaved form changes.
 *
 * @param source  an Inertia form (its `isDirty` is watched), or a ref/getter
 *                returning a boolean, or — see useDirtySnapshot — a snapshot getter.
 * @returns { allowNext } — call allowNext() right before a navigation that
 *          should not prompt (e.g. the form's own submit when it isn't an Inertia form).
 *
 * Confirms on Inertia navigations (not on the form's own submit, partial
 * reloads, or prefetches) and registers `beforeunload` while dirty. Cleans up on unmount.
 */
export function useUnsavedGuard(source, { message = MESSAGE } = {}) {
  let skipNext = false;

  const isDirty = () => {
    if (typeof source === 'function') return Boolean(source());
    if (source && typeof source === 'object' && 'isDirty' in source) return Boolean(source.isDirty);
    return Boolean(unref(source));
  };
  const isSubmitting = () => Boolean(source && typeof source === 'object' && source.processing);

  function onBeforeUnload(event) {
    if (!isDirty() || isSubmitting()) return;
    event.preventDefault();
    event.returnValue = '';
  }

  let removeListener = null;

  onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    removeListener = router.on('before', (event) => {
      const visit = event.detail.visit;
      if (skipNext) { skipNext = false; return; }
      if (!isDirty()) return;
      // The form's own submit (any non-GET visit from this page) and partial
      // reloads / prefetches keep the page — don't prompt.
      if (visit.method !== 'get') return;
      if (visit.only?.length || visit.except?.length || visit.prefetch) return;
      if (!window.confirm(message)) event.preventDefault();
    });
  });

  onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    removeListener?.();
  });

  return { allowNext: () => { skipNext = true; } };
}

/**
 * Dirty flag for forms whose state lives in several refs: pass a getter that
 * returns everything that gets submitted; it is compared (as JSON) against the
 * value captured at setup. Call `markClean()` to re-baseline (e.g. after a
 * server-driven refresh of part of the state).
 */
export function useDirtySnapshot(getter) {
  const baseline = ref(JSON.stringify(getter()));
  const isDirty = computed(() => JSON.stringify(getter()) !== baseline.value);
  return { isDirty, markClean: () => { baseline.value = JSON.stringify(getter()); } };
}
