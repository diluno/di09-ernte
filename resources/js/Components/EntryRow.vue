<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import { fmtClock } from '@/formatters/duration.js';
import { fmtDate } from '@/formatters/date.js';

const props = defineProps({
  entry: { type: Object, required: true },
  // Kept for the callers (Timer, Projects/Show, Clients/Show); rows are no longer colour-coded.
  colorIndex: { type: Number, default: 0 },
  showDate: { type: Boolean, default: false },
});

const emit = defineEmits(['edit', 'delete']);

// Main label: the description, else the task name. When both are missing the
// template shows a muted "no description" placeholder so the row never collapses
// to an unreadable blank.
const label = computed(() => props.entry.description || props.entry.task_name || '');

const dateLabel = computed(() => {
  if (!props.showDate || !props.entry.started_at) return '';

  return fmtDate(props.entry.started_at);
});

// Secondary line: the optional date plus project/task context. When a
// description occupies the main line, append the task for extra context.
// Billable is the default, so only the exception is spelled out.
const context = computed(() => {
  const project = props.entry.project?.name;
  const task = props.entry.task_name;
  const details = dateLabel.value ? [dateLabel.value] : [];

  if (task && props.entry.description && task !== props.entry.description) {
    if (project) details.push(project);
    details.push(task);
  } else if (project) {
    details.push(project);
  }
  if (props.entry.running) details.push('running');
  if (props.entry.billable === false) details.push('non-billable');

  return details.join(' · ');
});

// One entry's duration, exact: "0:15", "1:30", "10:05" (decimal hours are for aggregates).
const durationLabel = computed(() => fmtClock(props.entry.duration_seconds).replace(/^0(?=\d:)/, ''));

// Billed on an issued invoice: the server refuses edits and deletes (EntryController).
// `is_locked` comes from the timer payload; other payloads may not carry it.
const locked = computed(() => !!props.entry.is_locked);
const lockTitle = computed(() => props.entry.invoice_number
  ? `Billed on invoice ${props.entry.invoice_number}. Void the invoice to change this entry.`
  : 'Billed on an issued invoice. Void the invoice to change this entry.');
const name = computed(() => label.value || 'entry without description');
</script>

<template>
  <div class="entry-row" :class="{ 'is-locked': locked, 'is-running': entry.running }">
    <div class="desc">
      <span v-if="label">{{ label }}</span>
      <span v-else class="no-desc">no description</span>
      <span v-if="context" class="sub">{{ context }}</span>
    </div>
    <div class="dur" :title="`${durationLabel} h`">{{ durationLabel }}</div>
    <div class="entry-row__tools">
      <span v-if="locked" class="badge dot paid" :title="lockTitle">billed<span class="sr-only">: {{ lockTitle }}</span></span>
      <template v-else-if="!entry.running">
        <button type="button" class="btn ghost icon sm" title="Edit entry" :aria-label="`Edit ${name}`" @click="emit('edit', entry)"><Icon name="edit" /></button>
        <button type="button" class="btn ghost icon sm is-danger" title="Delete entry" :aria-label="`Delete ${name}`" @click="emit('delete', entry)"><Icon name="trash" /></button>
      </template>
    </div>
  </div>
</template>
