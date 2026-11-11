import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * One-click server actions with a double-submit guard.
 *
 *   const { pending, run } = usePendingAction();
 *   run('post', `/invoices/${id}/send`, {}, { confirm: 'Send to a@b.ch?' });
 *   <button :disabled="pending">…
 *
 * `pending` is true from request start to finish; `run` is a no-op while pending
 * and when the optional `confirm` prompt is declined. Remaining options are
 * passed to the Inertia visit (preserveScroll defaults to true).
 * `pendingKey` holds the `key` option of the running action (for per-button labels).
 */
export function usePendingAction() {
  const pending = ref(false);
  const pendingKey = ref(null);

  function run(method, url, data = {}, { confirm, key = null, onFinish, ...options } = {}) {
    if (pending.value) return false;
    if (confirm && !window.confirm(confirm)) return false;

    pending.value = true;
    pendingKey.value = key;
    router.visit(url, {
      method,
      data,
      preserveScroll: true,
      ...options,
      onFinish: (visit) => {
        pending.value = false;
        pendingKey.value = null;
        onFinish?.(visit);
      },
    });
    return true;
  }

  return { pending, pendingKey, run };
}

// "a@b.ch, c@d.ch" for confirm prompts; falls back when the list is empty.
export function recipientList(recipients, fallback = 'the client') {
  const emails = (recipients ?? []).map((r) => r?.email).filter(Boolean);
  return emails.length ? emails.join(', ') : fallback;
}
