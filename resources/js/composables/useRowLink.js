import { router } from '@inertiajs/vue3';

const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="button"], [role="link"], [role="menuitem"], [contenteditable]';

/**
 * Row-click convenience for tables whose primary cell already holds a real <Link>.
 *
 *   <tr class="is-clickable" @click="rowClick($event, url)">
 *
 * Does nothing when the click landed on an interactive element (the link itself,
 * a button, a checkbox…), when a modifier key is held (the link handles
 * cmd/ctrl-click → new tab), on non-primary buttons, or while text is selected.
 */
export function rowClick(event, url) {
  if (event.defaultPrevented || event.button !== 0) return;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  if (event.target instanceof Element && event.target.closest(INTERACTIVE)) return;
  if (window.getSelection?.()?.toString()) return;
  router.visit(url);
}
