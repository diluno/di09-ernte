<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

const page = usePage();

// Each toast: { id, type: 'success' | 'error', message }
const toasts = ref([]);
let nextId = 0;
const timers = new Map();

function dismiss(id) {
  toasts.value = toasts.value.filter((t) => t.id !== id);
  const timer = timers.get(id);
  if (timer) {
    clearTimeout(timer);
    timers.delete(id);
  }
}

function push(type, message) {
  const id = nextId++;
  toasts.value.push({ id, type, message });
  timers.set(id, setTimeout(() => dismiss(id), type === 'error' ? 8000 : 4000));
}

const flash = computed(() => page.props.flash ?? {});

watch(
  flash,
  (value) => {
    if (value?.success) push('success', value.success);
    if (value?.error) push('error', value.error);
  },
  { immediate: true, deep: true },
);
</script>

<!-- Styles: resources/css/pages/timer-settings.css (Flash toasts). -->
<template>
  <div class="flash-stack" aria-live="polite">
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="flash"
      :class="toast.type"
      :role="toast.type === 'error' ? 'alert' : 'status'"
      @click="dismiss(toast.id)"
    >
      <span class="flash-mark" aria-hidden="true">{{ toast.type === 'error' ? '●' : '✓' }}</span>
      <span class="flash-msg">{{ toast.message }}</span>
      <button class="btn ghost icon sm" type="button" title="Dismiss" :aria-label="toast.type === 'error' ? 'Dismiss error' : 'Dismiss message'" @click.stop="dismiss(toast.id)"><Icon name="close" /></button>
    </div>
  </div>
</template>
