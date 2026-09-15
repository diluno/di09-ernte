<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

// Renders a Laravel paginator (data/links/from/to/total/last_page/current_page).
// Hidden when there is only a single page.
const props = defineProps({
  paginator: { type: Object, required: true },
});

const show = computed(() => (props.paginator.last_page ?? 1) > 1);

function go(url) {
  if (!url) return;
  router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function label(raw) {
  if (raw.includes('Previous')) return '← Prev';
  if (raw.includes('Next')) return 'Next →';
  if (raw === '...') return '…';
  return raw;
}
</script>

<template>
  <div v-if="show" class="pagination">
    <div class="pagination__range">
      {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
    </div>
    <div class="pagination__links">
      <button
        v-for="(link, i) in paginator.links"
        :key="i"
        type="button"
        class="btn sm"
        :class="{ primary: link.active, ghost: !link.url && link.label === '...' }"
        :disabled="!link.url"
        @click="go(link.url)"
      >{{ label(link.label) }}</button>
    </div>
  </div>
</template>

<style scoped>
.pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 40px;
  flex-wrap: wrap;
  font-size: 12px;
  color: var(--ink-3);
}
.pagination__links { display: flex; gap: 4px; }
.pagination__links .btn { min-width: 32px; justify-content: center; }
</style>
