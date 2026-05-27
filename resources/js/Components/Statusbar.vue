<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const app = computed(() => page.props.app);
const sys = computed(() => page.props.system);

const uptime = computed(() => {
  const s = sys.value?.uptime_seconds ?? 0;
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  return d > 0 ? `${d}d ${h}h` : `${h}h`;
});

const dbSize = computed(() => {
  const b = sys.value?.db_size_bytes ?? 0;
  if (b < 1024) return `${b}B`;
  if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)}KB`;
  if (b < 1024 ** 3) return `${(b / 1024 / 1024).toFixed(0)}MB`;
  return `${(b / 1024 / 1024 / 1024).toFixed(1)}GB`;
});

const backupAgo = computed(() => {
  const iso = sys.value?.backup_last_at;
  if (!iso) return 'never';
  const sec = Math.max(1, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
  if (sec < 60)        return `${sec}s ago`;
  if (sec < 3600)      return `${Math.floor(sec / 60)}m ago`;
  if (sec < 86400)     return `${Math.floor(sec / 3600)}h ago`;
  return `${Math.floor(sec / 86400)}d ago`;
});
</script>

<template>
  <footer class="statusbar">
    <span><span class="dot" />connected</span>
    <span class="sep">│</span>
    <span>localhost<span class="muted">:{{ app?.port }}</span></span>
    <span class="sep">│</span>
    <span>v{{ app?.version }} <span class="muted">(self-hosted)</span></span>
    <span class="sep">│</span>
    <span>db <span class="muted">{{ sys?.db_driver }} {{ sys?.db_version }} · {{ dbSize }}</span></span>
    <span class="sep">│</span>
    <span>backup <span class="muted">{{ backupAgo }}</span></span>
    <span class="spacer" />
    <span class="muted">uptime {{ uptime }}</span>
  </footer>
</template>
