import { onMounted, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

function isTypingTarget(target) {
  const tag = target?.tagName?.toLowerCase();
  return tag === 'input' || tag === 'textarea' || tag === 'select' || target?.isContentEditable;
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

    const typing = isTypingTarget(event.target);

    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      openCommandPalette();
      return;
    }

    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'p') {
      if (typing) return;
      event.preventDefault();
      openProjectPalette();
      return;
    }

    if (typing) return;

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
