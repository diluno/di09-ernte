<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import { fmtHours } from '@/formatters/duration.js';

const props = defineProps({
  task: { type: Object, required: true },
  projectId: { type: Number, required: true },
});

const hasBudget = computed(() => props.task.budget_hours > 0);
const pct = computed(() => hasBudget.value
  ? Math.round((props.task.spent_hours / props.task.budget_hours) * 100)
  : 0);
const band = computed(() => pct.value > 100 ? 'over' : pct.value >= 85 ? 'warn' : 'ok');

function toggleDone() {
  router.patch(`/tasks/${props.task.id}`, { done: !props.task.done }, { preserveScroll: true });
}
</script>

<template>
  <div class="task-row" :class="{ 'is-done': task.done, 'is-over': hasBudget && band === 'over' }">
    <button
      type="button" class="task-check" :class="{ done: task.done }"
      role="checkbox" :aria-checked="task.done ? 'true' : 'false'" :aria-label="`Task done: ${task.name}`"
      @click="toggleDone"
    ><Icon v-if="task.done" name="check" /></button>
    <div class="task-name" :class="{ done: task.done }">{{ task.name }}</div>
    <div class="task-num">
      {{ fmtHours(task.spent_hours) }}<template v-if="hasBudget"><span class="dim"> / {{ fmtHours(task.budget_hours) }}</span></template>
    </div>
    <div class="task-num task-pct" :class="band">{{ hasBudget ? `${pct}%` : '—' }}</div>
    <div class="task-bar" :class="{ 'is-empty': !hasBudget }" aria-hidden="true">
      <div v-if="hasBudget" class="task-bar-fill" :class="band" :style="{ width: `${Math.min(100, pct)}%` }" />
    </div>
  </div>
</template>
