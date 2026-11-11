<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Topbar from '@/Components/Topbar.vue';
import Sidebar from '@/Components/Sidebar.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts.js';

const paletteOpen = ref(false);
const paletteMode = ref('all');

function openCommandPalette() {
  paletteMode.value = 'all';
  paletteOpen.value = true;
}

function openProjectPalette() {
  paletteMode.value = 'project';
  paletteOpen.value = true;
}

useKeyboardShortcuts({ openCommandPalette, openProjectPalette });

// ── Off-canvas sidebar (below 900px; see the narrow-window rules in base.css) ──
// Opening moves focus into the drawer and makes the page behind it inert; Esc,
// the backdrop, a link inside it, any navigation, or widening the window close it.
const NARROW = '(max-width: 899px)';
const navOpen = ref(false);
const page = usePage();

function focusToggle() {
  document.querySelector('.nav-toggle')?.focus();
}
function openNav() {
  navOpen.value = true;
  nextTick(() => document.querySelector('#app-nav a')?.focus());
}
function closeNav({ restoreFocus = false } = {}) {
  if (!navOpen.value) return;
  navOpen.value = false;
  if (restoreFocus) nextTick(focusToggle);
}
function toggleNav() {
  navOpen.value ? closeNav() : openNav();
}
function onNavKeydown(e) {
  if (e.key === 'Escape') closeNav({ restoreFocus: true });
}
watch(navOpen, (open) => {
  document[open ? 'addEventListener' : 'removeEventListener']('keydown', onNavKeydown);
});
watch(() => page.url, () => closeNav());

let mq = null;
function onMediaChange(e) { if (!e.matches) closeNav(); }
onMounted(() => {
  mq = window.matchMedia(NARROW);
  mq.addEventListener('change', onMediaChange);
});
onBeforeUnmount(() => {
  mq?.removeEventListener('change', onMediaChange);
  document.removeEventListener('keydown', onNavKeydown);
});
</script>

<template>
  <div class="app">
    <Topbar :nav-open="navOpen" @open-command="openCommandPalette" @toggle-nav="toggleNav" />
    <Sidebar :open="navOpen" @navigate="closeNav()" />
    <div v-if="navOpen" class="nav-backdrop" aria-hidden="true" @click="closeNav({ restoreFocus: true })" />
    <main class="content" :inert="navOpen || undefined">
      <slot />
    </main>
    <CommandPalette :show="paletteOpen" :mode="paletteMode" @close="paletteOpen = false" />
    <FlashMessages />
  </div>
</template>
