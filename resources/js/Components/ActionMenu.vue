<script setup>
// "…" more-actions menu: an icon button that opens a .menu of links/actions.
// Used in a page head (.page-actions) and inside table rows (size="sm").
//
//   <ActionMenu :items="[
//     { label: 'Edit', href: `/things/${id}/edit` },
//     { label: 'Pause', action: pause },
//     { separator: true },
//     { label: 'Delete…', action: destroy, danger: true },
//   ]" :disabled="pending" />
//
// The menu is position: fixed (placed under the trigger, flipped above when there
// is no room) so it is not clipped by a scrolling .table-wrap. Keyboard: Enter/
// Space/↓ open and focus the first item, ↑ the last; ↑/↓/Home/End move; Esc
// closes and returns focus to the trigger; Tab closes. Outside click, scrolling
// and resizing close it too.
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
  items: { type: Array, required: true },
  label: { type: String, default: 'More actions' },
  size: { type: String, default: 'md' }, // 'md' = 40px page-head button, 'sm' = 28px row button
  disabled: { type: Boolean, default: false }, // disables the action items (e.g. while a request runs)
});

const id = useId();
const open = ref(false);
const triggerEl = ref(null);
const menuEl = ref(null);
const pos = ref({});

const visibleItems = computed(() => props.items.filter(Boolean));

function itemEls() {
  return menuEl.value ? [...menuEl.value.querySelectorAll('[role="menuitem"]:not([disabled])')] : [];
}
function focusItem(index) {
  const els = itemEls();
  if (!els.length) return;
  els[(index + els.length) % els.length].focus({ preventScroll: true });
}

function place() {
  const t = triggerEl.value?.getBoundingClientRect();
  if (!t) return;
  const right = document.documentElement.clientWidth - t.right;
  pos.value = { top: `${t.bottom + 6}px`, right: `${right}px` };
  nextTick(() => {
    const h = menuEl.value?.offsetHeight ?? 0;
    if (t.bottom + 6 + h > window.innerHeight - 8 && t.top - 6 - h >= 8) {
      pos.value = { top: `${t.top - 6 - h}px`, right: `${right}px` };
    }
  });
}

function show(focus = 'first') {
  open.value = true;
  place();
  nextTick(() => focusItem(focus === 'last' ? -1 : 0));
}
function hide({ restoreFocus = false } = {}) {
  if (!open.value) return;
  open.value = false;
  if (restoreFocus) triggerEl.value?.focus();
}
function toggle() { open.value ? hide() : show(); }

function onTriggerKeydown(e) {
  if (e.key === 'ArrowDown') { e.preventDefault(); show('first'); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); show('last'); }
}
function onMenuKeydown(e) {
  const els = itemEls();
  const i = els.indexOf(document.activeElement);
  switch (e.key) {
    case 'ArrowDown': e.preventDefault(); focusItem(i + 1); break;
    case 'ArrowUp': e.preventDefault(); focusItem(i - 1); break;
    case 'Home': e.preventDefault(); focusItem(0); break;
    case 'End': e.preventDefault(); focusItem(-1); break;
    case 'Escape': e.preventDefault(); e.stopPropagation(); hide({ restoreFocus: true }); break;
    case 'Tab': hide(); break;
  }
}

function choose(item) {
  hide({ restoreFocus: true });
  item.action?.();
}

// Global listeners only while open.
function onDocPointer(e) {
  if (triggerEl.value?.contains(e.target) || menuEl.value?.contains(e.target)) return;
  hide();
}
function onDocKeydown(e) { if (e.key === 'Escape') hide({ restoreFocus: true }); }
function onScroll(e) { if (!menuEl.value?.contains(e.target)) hide(); }
function onResize() { hide(); }
function listen(on) {
  const fn = on ? 'addEventListener' : 'removeEventListener';
  document[fn]('mousedown', onDocPointer);
  document[fn]('touchstart', onDocPointer);
  document[fn]('keydown', onDocKeydown);
  window[fn]('scroll', onScroll, true);
  window[fn]('resize', onResize);
}
watch(open, listen);
onBeforeUnmount(() => listen(false));
</script>

<template>
  <div class="action-menu">
    <button
      ref="triggerEl"
      type="button"
      class="btn icon"
      :class="{ sm: size === 'sm' }"
      :aria-label="label"
      :title="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-controls="open ? id : undefined"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <Icon name="more-horizontal" style="font-size: 14px" />
    </button>
    <div
      v-if="open"
      :id="id"
      ref="menuEl"
      class="menu menu--fixed"
      role="menu"
      :aria-label="label"
      :style="pos"
      @keydown="onMenuKeydown"
      @click.stop
    >
      <template v-for="(item, i) in visibleItems" :key="item.label ?? `sep-${i}`">
        <div v-if="item.separator" class="menu-sep" role="separator" />
        <Link v-else-if="item.href" :href="item.href" class="menu-item" :class="{ 'is-danger': item.danger }" role="menuitem" tabindex="-1" @click="hide()">{{ item.label }}</Link>
        <button v-else type="button" class="menu-item" :class="{ 'is-danger': item.danger }" role="menuitem" tabindex="-1" :disabled="disabled || item.disabled" @click="choose(item)">{{ item.label }}</button>
      </template>
    </div>
  </div>
</template>
