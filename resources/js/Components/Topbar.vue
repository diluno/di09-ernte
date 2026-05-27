<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import RunningTimerChip from '@/Components/RunningTimerChip.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const initials = computed(() => {
  const n = user.value?.name ?? '?';
  return n.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
});
</script>

<template>
  <header class="topbar">
    <Link href="/projects" class="wordmark">
      <span class="wordmark-mark" />
      <span>ernte</span>
    </Link>
    <div class="mono-tag" title="Workspace">workspace: {{ user?.name?.toLowerCase() ?? 'guest' }}@ernte</div>
    <div class="topbar-spacer" />
    <button class="cmdk" title="Command palette (Phase 2b)" disabled>
      <span style="color: var(--ink-4)">›</span>
      <span style="flex: 1; text-align: left">Jump to project, client, invoice…</span>
      <span class="kbd">⌘K</span>
    </button>
    <div class="topbar-spacer" />
    <RunningTimerChip />
    <div class="user-chip">
      <span class="avatar">{{ initials }}</span>
      <span>{{ user?.name ?? 'guest' }}</span>
    </div>
  </header>
</template>
