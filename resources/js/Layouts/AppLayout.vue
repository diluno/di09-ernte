<script setup>
import { ref } from 'vue';
import Topbar from '@/Components/Topbar.vue';
import Sidebar from '@/Components/Sidebar.vue';
import Statusbar from '@/Components/Statusbar.vue';
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
</script>

<template>
  <div class="app">
    <Topbar @open-command="openCommandPalette" />
    <Sidebar />
    <main class="content">
      <slot />
    </main>
    <Statusbar />
    <CommandPalette :show="paletteOpen" :mode="paletteMode" @close="paletteOpen = false" />
    <FlashMessages />
  </div>
</template>
