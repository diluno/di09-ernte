import { onMounted, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// Elements that own their keystrokes: form controls, anything activatable with
// space/enter, and everything inside a dialog or menu.
const INTERACTIVE = [
  'input', 'textarea', 'select', 'button', 'a[href]', 'summary',
  '[contenteditable]:not([contenteditable="false"])',
  '[role="button"]', '[role="link"]', '[role="menuitem"]', '[role="tab"]',
  '[role="option"]', '[role="checkbox"]', '[role="switch"]', '[role="combobox"]',
  '[role="textbox"]', 'dialog', '[role="dialog"]', '[role="menu"]', '[role="listbox"]',
].join(',');

function isInteractiveTarget(target) {
  if (!(target instanceof Element)) return false;
  return target.isContentEditable || target.closest(INTERACTIVE) !== null;
}

function focusFilter() {
  const input = [...document.querySelectorAll('.search input, input[type="search"], [data-filter-input]')]
    .find((el) => el.offsetParent !== null && !el.disabled);
  input?.focus();
  input?.select?.();
}

export function useKeyboardShortcuts({ openCommandPalette, openProjectPalette }) {
  const page = usePage();
  const pendingG = ref(false);
  let gTimer = null;

  function visit(path) {
    router.visit(path);
  }

  function onKeydown(event) {
    if (event.repeat) return;

    const interactive = isInteractiveTarget(event.target);
    const mod = event.metaKey || event.ctrlKey;

    // ⌘K / Ctrl+K works everywhere, including inside inputs.
    if (mod && !event.altKey && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      openCommandPalette();
      return;
    }

    if (mod && !event.altKey && event.key.toLowerCase() === 'p') {
      if (interactive) return;
      event.preventDefault();
      openProjectPalette();
      return;
    }

    // Single-key shortcuts: never while a modifier is held (⌘N, Ctrl+G, …) and
    // never while a control, link or dialog has focus (space/enter belong to it).
    if (mod || event.altKey || interactive) {
      pendingG.value = false;
      return;
    }

    if (event.key === '/') {
      event.preventDefault();
      focusFilter();
      return;
    }

    if (event.key === ' ') {
      event.preventDefault();
      if (page.props.running_entry) {
        router.post('/timer/stop', {}, { preserveScroll: true });
      } else {
        openProjectPalette();
      }
      return;
    }

    if (event.key.toLowerCase() === 'n') {
      event.preventDefault();
      if (window.location.pathname === '/timer') {
        window.dispatchEvent(new CustomEvent('ernte:open-manual-entry'));
      } else {
        router.visit('/timer?manual=1');
      }
      return;
    }

    if (event.key.toLowerCase() === 'g') {
      pendingG.value = true;
      clearTimeout(gTimer);
      gTimer = setTimeout(() => { pendingG.value = false; }, 900);
      return;
    }

    if (pendingG.value) {
      const key = event.key.toLowerCase();
      const paths = { d: '/projects', t: '/timer', c: '/clients', i: '/invoices' };
      if (paths[key]) {
        event.preventDefault();
        pendingG.value = false;
        clearTimeout(gTimer);
        visit(paths[key]);
      }
    }
  }

  onMounted(() => window.addEventListener('keydown', onKeydown));
  onUnmounted(() => {
    clearTimeout(gTimer);
    window.removeEventListener('keydown', onKeydown);
  });
}
