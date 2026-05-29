<script setup>
// A single-line-looking <textarea> that grows downward as content wraps or gains
// newlines — used for line-item descriptions so they can hold multiple lines.
// `class` and `placeholder` fall through to the textarea, so callers keep styling
// it with `cell-input` exactly like the <input> it replaces.
import { ref, onMounted, watch, nextTick } from 'vue';

const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);

const el = ref(null);

function resize() {
  const ta = el.value;
  if (!ta) return;
  ta.style.height = 'auto';
  // Add the border delta so border-box textareas don't end up one scrollbar short.
  ta.style.height = `${ta.scrollHeight + (ta.offsetHeight - ta.clientHeight)}px`;
}

function onInput(e) {
  emit('update:modelValue', e.target.value);
  resize();
}

onMounted(resize);
watch(() => props.modelValue, () => nextTick(resize));
</script>

<template>
  <textarea ref="el" rows="1" :value="modelValue" @input="onInput" />
</template>

<style scoped>
textarea {
  box-sizing: border-box;
  display: block;
  resize: none;
  overflow: hidden;
  line-height: 1.4;
}
</style>
