<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

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

<template>
  <div class="flash-stack" aria-live="polite">
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="flash"
      :class="toast.type"
      role="status"
      @click="dismiss(toast.id)"
    >
      <span class="flash-dot" />
      <span class="flash-msg">{{ toast.message }}</span>
      <button class="flash-close" type="button" aria-label="Dismiss" @click.stop="dismiss(toast.id)">×</button>
    </div>
  </div>
</template>

<style scoped>
.flash-stack {
  position: fixed;
  bottom: 48px;
  right: 16px;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-width: min(420px, calc(100vw - 32px));
}

.flash {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: var(--bg-2);
  border: 1px solid var(--border-strong);
  border-left-width: 3px;
  border-radius: 4px;
  font-family: var(--font-sans);
  font-size: var(--fs-sm);
  color: var(--ink);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
  cursor: pointer;
}

.flash.success {
  border-left-color: var(--forest);
}

.flash.error {
  border-left-color: var(--red);
}

.flash-dot {
  flex: none;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--ink-4);
}

.flash.success .flash-dot {
  background: var(--forest);
}

.flash.error .flash-dot {
  background: var(--red);
}

.flash-msg {
  flex: 1;
}

.flash-close {
  flex: none;
  border: none;
  background: none;
  color: var(--ink-3);
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  padding: 0 2px;
}

.flash-close:hover {
  color: var(--ink);
}
</style>
