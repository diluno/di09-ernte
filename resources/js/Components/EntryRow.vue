<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import { formatDuration } from '@/formatters/duration.js';

const props = defineProps({
  entry: { type: Object, required: true },
  colorIndex: { type: Number, default: 0 },
});

const emit = defineEmits(['edit', 'delete']);

const COLORS = ['#2d4a3a', '#c97b3c', '#b8941f', '#1a1a1a', '#7a8c5c', '#b54834'];

// Main label: the description, else the task name. When both are missing the
// template shows a muted "no description" placeholder so the row never collapses
// to an unreadable blank.
const label = computed(() => props.entry.description || props.entry.task_name || '');

// Secondary line: always the project, so every row stays identifiable. When a
// description occupies the main line, append the task for extra context.
const context = computed(() => {
  const project = props.entry.project?.name;
  const task = props.entry.task_name;
  if (task && props.entry.description && task !== props.entry.description) {
    return project ? `${project} · ${task}` : task;
  }
  return project || '';
});

const durationLabel = computed(() => formatDuration(Math.round(props.entry.duration_seconds / 60)));
</script>

<template>
  <div class="entry-row">
    <div class="bar-color" :style="{ background: COLORS[colorIndex % COLORS.length] }" />
    <div class="desc">
      <span v-if="label">{{ label }}</span>
      <span v-else class="no-desc">no description</span>
      <span v-if="context" class="sub">{{ context }}</span>
    </div>
    <div class="dur">{{ durationLabel }}</div>
    <div class="billable" :class="{ no: !entry.billable }">{{ entry.billable ? 'billable' : '—' }}</div>
    <div class="actions">
      <template v-if="!entry.running">
        <button type="button" class="row-action" title="Edit entry" aria-label="Edit entry" @click="emit('edit', entry)"><Icon name="edit" /></button>
        <button type="button" class="row-action row-action--danger" title="Delete entry" aria-label="Delete entry" @click="emit('delete', entry)"><Icon name="trash" /></button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.no-desc { color: var(--ink-4); font-style: italic; }
.actions { display: flex; gap: 4px; justify-content: flex-end; }
.row-action {
  border: none;
  background: none;
  cursor: pointer;
  color: var(--ink-3);
  font-size: 16px; /* crisp pixel-art icons render best at a clean 16px */
  line-height: 1;
  padding: 2px 4px;
}
.row-action:hover { color: var(--ink); }
.row-action--danger:hover { color: var(--red); }
</style>
