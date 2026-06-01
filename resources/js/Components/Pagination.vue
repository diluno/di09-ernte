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
  if (raw.includes('Previous')) return '‹ Prev';
  if (raw.includes('Next')) return 'Next ›';
  if (raw === '...') return '…';
  return raw;
}
</script>

<template>
  <div v-if="show" class="pagination">
    <div class="pagination__range">
      Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
    </div>
    <div class="pagination__links">
      <button
        v-for="(link, i) in paginator.links"
        :key="i"
        type="button"
        class="pagination__link"
        :class="{ 'is-active': link.active, 'is-gap': !link.url && link.label === '...' }"
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
  margin-top: 14px;
  flex-wrap: wrap;
}
.pagination__range {
  color: var(--ink-4);
  font-size: 13px;
}
.pagination__links {
  display: flex;
  gap: 4px;
}
.pagination__link {
  min-width: 32px;
  padding: 5px 9px;
  border: 1px solid var(--border-strong);
  background: var(--bg);
  color: var(--ink);
  border-radius: 6px;
  cursor: pointer;
  font: inherit;
  font-size: 13px;
}
.pagination__link:hover:not(:disabled):not(.is-active) {
  background: var(--bg-hover);
}
.pagination__link.is-active {
  background: var(--accent);
  color: var(--accent-on);
  border-color: var(--accent);
  cursor: default;
}
.pagination__link:disabled {
  opacity: 0.4;
  cursor: default;
}
.pagination__link.is-gap {
  border-color: transparent;
  background: transparent;
}
</style>
