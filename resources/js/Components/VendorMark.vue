<script setup>
// The vendor's site icon in its own colours; an initial in a ruled box while there is none.
import { computed, ref, watch } from 'vue';

const props = defineProps({
  name: { type: String, default: null },
  logo: { type: String, default: null },
  size: { type: Number, default: 18 },
});

const broken = ref(false);
watch(() => props.logo, () => { broken.value = false; });
const initial = computed(() => (props.name ?? '').trim().charAt(0).toUpperCase() || '·');
</script>

<template>
  <img v-if="logo && !broken" :src="logo" alt="" class="vendor-mark" :style="{ width: `${size}px`, height: `${size}px` }" loading="lazy" @error="broken = true" />
  <span v-else class="vendor-mark is-initial" :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.56)}px` }" aria-hidden="true">{{ initial }}</span>
</template>
