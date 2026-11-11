<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import RunningTimerChip from '@/Components/RunningTimerChip.vue';
import Icon from '@/Components/Icon.vue';

defineProps({
  // Narrow windows only: state of the off-canvas sidebar (the toggle is hidden on desktop).
  navOpen: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const businessName = computed(() => (page.props.business?.name || '').toLowerCase());

defineEmits(['open-command', 'toggle-nav']);
</script>

<template>
  <header class="topbar">
    <button
      type="button"
      class="nav-toggle"
      :aria-label="navOpen ? 'Close navigation' : 'Open navigation'"
      aria-controls="app-nav"
      :aria-expanded="navOpen"
      @click="$emit('toggle-nav')"
    >
      <Icon :name="navOpen ? 'close' : 'menu'" />
    </button>
    <Link href="/projects" class="wordmark">
      <Icon name="leaf" class="wordmark-mark" />
      <span>ernte</span>
      <span v-if="businessName" class="biz">/ {{ businessName }}</span>
    </Link>
    <div class="topbar-spacer" />
    <button class="cmdk" title="Command palette" aria-label="Jump to…" @click="$emit('open-command')">
      <Icon name="search" class="cmdk__icon" />
      <span class="cmdk__label">Jump to…</span>
      <span class="kbd">⌘K</span>
    </button>
    <div class="topbar-spacer" />
    <RunningTimerChip />
    <Link href="/settings" class="user-chip" title="Settings" aria-label="Open settings">{{ user?.name ?? 'guest' }}</Link>
  </header>
</template>
