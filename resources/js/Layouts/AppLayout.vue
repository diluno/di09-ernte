<script setup>
import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Topbar from '@/Components/Topbar.vue';
import Sidebar from '@/Components/Sidebar.vue';
import Statusbar from '@/Components/Statusbar.vue';
import TweaksPanel from '@/Components/TweaksPanel.vue';

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
</script>

<template>
  <div class="app">
    <Topbar />
    <Sidebar />
    <main class="content">
      <slot />
    </main>
    <Statusbar />
    <TweaksPanel />
  </div>
</template>
