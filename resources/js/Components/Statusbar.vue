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
</script>

<template>
  <footer class="statusbar">
    <span><span class="dot" />connected</span>
    <span class="sep">│</span>
    <span>localhost<span class="muted">:{{ app?.port }}</span></span>
    <span class="sep">│</span>
    <span>v{{ app?.version }} <span class="muted">(self-hosted)</span></span>
    <span class="sep">│</span>
    <span>db <span class="muted">{{ sys?.db_driver }} {{ sys?.db_version }}</span></span>
    <span class="spacer" />
    <span class="muted">uptime {{ uptime }}</span>
  </footer>
</template>
