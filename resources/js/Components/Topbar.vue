<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import RunningTimerChip from '@/Components/RunningTimerChip.vue';
import Icon from '@/Components/Icon.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const businessName = computed(() => (page.props.business?.name || '').toLowerCase());

defineEmits(['open-command']);
</script>

<template>
  <header class="topbar">
    <Link href="/projects" class="wordmark">
      <Icon name="leaf" class="wordmark-mark" />
      <span>ernte</span>
      <span v-if="businessName" class="biz">/ {{ businessName }}</span>
    </Link>
    <div class="topbar-spacer" />
    <button class="cmdk" title="Command palette" @click="$emit('open-command')">
      <span>Jump to…</span>
      <span class="kbd">⌘K</span>
    </button>
    <div class="topbar-spacer" />
    <RunningTimerChip />
    <Link href="/settings" class="user-chip" title="Settings" aria-label="Open settings">{{ user?.name ?? 'guest' }}</Link>
  </header>
</template>
