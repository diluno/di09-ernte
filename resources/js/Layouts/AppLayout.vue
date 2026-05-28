<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Topbar from '@/Components/Topbar.vue';
import Sidebar from '@/Components/Sidebar.vue';
import Statusbar from '@/Components/Statusbar.vue';
import TweaksPanel from '@/Components/TweaksPanel.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts.js';

const DEFAULT_SETTINGS = Object.freeze({ theme: 'paper', density: 'comfortable', accent: '#2d4a3a' });

const page = usePage();
const settings = computed(() => page.props.auth?.user?.settings ?? DEFAULT_SETTINGS);

function applyTokens() {
  const r = document.documentElement;
  r.setAttribute('data-theme', settings.value.theme);
  r.setAttribute('data-density', settings.value.density);
  r.style.setProperty('--accent', settings.value.accent);
}

watch(settings, applyTokens, { immediate: true, deep: true });

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
</script>

<template>
  <div class="app">
    <Topbar @open-command="openCommandPalette" />
    <Sidebar />
    <main class="content">
      <slot />
    </main>
    <Statusbar />
    <TweaksPanel />
    <CommandPalette :show="paletteOpen" :mode="paletteMode" @close="paletteOpen = false" />
  </div>
</template>
