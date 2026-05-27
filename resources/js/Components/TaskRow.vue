<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
  task: { type: Object, required: true },
  projectId: { type: Number, required: true },
});

const pct = computed(() => props.task.budget_hours > 0
  ? Math.round((props.task.spent_hours / props.task.budget_hours) * 100)
  : 0);

function toggleDone() {
  router.patch(`/tasks/${props.task.id}`, { done: !props.task.done }, { preserveScroll: true });
}
</script>

<template>
  <div class="task-row">
    <button class="task-check" :class="{ done: task.done }" @click="toggleDone">{{ task.done ? '✓' : '' }}</button>
    <div class="task-name" :class="{ done: task.done }">{{ task.name }}</div>
    <div class="task-num">{{ task.spent_hours.toFixed(1) }}h / {{ task.budget_hours }}h</div>
    <div class="task-num dim">{{ pct }}%</div>
    <div class="task-bar">
      <div
        class="task-bar-fill"
        :style="{ width: `${Math.min(100, pct)}%`, background: pct > 100 ? 'var(--red)' : 'var(--forest)' }"
      />
    </div>
  </div>
</template>
