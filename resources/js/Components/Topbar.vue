<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import RunningTimerChip from '@/Components/RunningTimerChip.vue';
import Icon from '@/Components/Icon.vue';
import GenerativeMark from '@/Components/GenerativeMark.vue';

defineProps({
  // Narrow windows only: state of the off-canvas sidebar (the toggle is hidden on desktop).
  navOpen: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const businessName = computed(() => (page.props.business?.name || '').toLowerCase());
// The mark spells "diluno", so it only stands in for that business name. A new one every day.
const showMark = computed(() => businessName.value.includes('diluno'));
const today = new Date().toISOString().slice(0, 10);

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
      <template v-if="showMark">
        <span class="biz" aria-hidden="true">/</span>
        <GenerativeMark :seed="today" :width="440" :step="9" :stroke="0.8" class="wordmark-gen" />
        <span class="sr-only">{{ businessName }}</span>
      </template>
      <span v-else-if="businessName" class="biz">/ {{ businessName }}</span>
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
