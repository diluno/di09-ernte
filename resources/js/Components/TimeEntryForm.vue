<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { parseDate, toIsoDate } from '@/formatters/date.js';
import { parseDuration, minutesToInput } from '@/formatters/duration.js';

// Manual entry (POST /entries) or editing one (PATCH /entries/{id}) on the timer
// page. The page re-mounts it (keyed by the entry) for each entry it edits.
const props = defineProps({
  projects: { type: Array, default: () => [] },
  date: { type: String, required: true },   // the viewed day: default date for a new entry
  entry: { type: Object, default: null },   // the entry being edited, or null for a new one
});

const emit = defineEmits(['saved', 'cancel']);

const editingId = props.entry?.id ?? null;
const originalProjectId = props.entry?.project.id ?? null;
const originalStartedAt = props.entry?.started_at ?? null;

const form = useForm({
  project_id: props.entry?.project.id ?? '',
  description: props.entry?.description || '',
  date: props.entry ? toIsoDate(props.entry.started_at) : props.date,
  billable: props.entry ? props.entry.billable : true,
});
const durationText = ref(props.entry ? minutesToInput(Math.round(props.entry.duration_seconds / 60)) : '1:00');

const durationMinutes = computed(() => parseDuration(durationText.value));
const durationPreview = computed(() =>
  durationMinutes.value ? `= ${minutesToInput(durationMinutes.value)} h` : 'e.g. 1:30, 90m, 1.5');

// Errors shown under a field; anything else (e.g. the "billed on invoice" refusal) goes in the footer.
const FIELD_ERRORS = ['project_id', 'description', 'started_at', 'duration', 'ended_at'];
const otherErrors = computed(() => Object.entries(form.errors)
  .filter(([key]) => !FIELD_ERRORS.includes(key))
  .map(([, message]) => message));
const durationError = computed(() => form.errors.duration || form.errors.ended_at);

// The chosen calendar day (YYYY-MM-DD) at the time-of-day of `time`, as a local Date.
function onDay(day, time) {
  const d = parseDate(day);
  d.setHours(time.getHours(), time.getMinutes(), time.getSeconds(), 0);
  return d;
}

function submit() {
  const mins = durationMinutes.value;
  if (!mins) {
    form.setError('duration', 'Enter a valid duration, e.g. 1:30, 90m, or 1.5');
    return;
  }
  form.clearErrors();

  form.transform((data) => {
    // Create: anchor the start on the chosen date at the current time-of-day.
    // Edit: the chosen date at the entry's original time-of-day, so it keeps its
    // place within the day but follows a changed Date field.
    const start = onDay(data.date, (editingId && originalStartedAt) ? new Date(originalStartedAt) : new Date());
    const end = new Date(start.getTime() + mins * 60000);

    // UTC instants; StoreEntryRequest converts them to Zurich wall-clock.
    const payload = {
      project_id: data.project_id,
      description: data.description,
      billable: data.billable,
      started_at: start.toISOString(),
      ended_at: end.toISOString(),
    };
    if (editingId && data.project_id !== originalProjectId) {
      payload.task_id = null;
    }
    return payload;
  });

  const opts = {
    onSuccess: () => {
      form.reset();
      durationText.value = '1:00';
      emit('saved');
    },
    preserveScroll: true,
  };

  if (editingId) {
    form.patch(`/entries/${editingId}`, opts);
  } else {
    form.post('/entries', opts);
  }
}

function cancel() {
  form.clearErrors();
  form.reset();
  durationText.value = '1:00';
  emit('cancel');
}
</script>

<template>
  <form class="timer-form" aria-labelledby="timer-form-title" @submit.prevent="submit">
    <h2 id="timer-form-title" class="section-title">{{ editingId ? 'Edit entry' : 'Manual entry' }}</h2>

    <div class="form-grid">
      <label class="field">
        <span>Project</span>
        <select v-model="form.project_id" required class="select" :aria-invalid="!!form.errors.project_id">
          <option value="" disabled>Select a project…</option>
          <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }} ({{ p.code }})</option>
        </select>
        <small v-if="form.errors.project_id" class="error">{{ form.errors.project_id }}</small>
      </label>
      <label class="field">
        <span>Description</span>
        <input v-model="form.description" placeholder="What did you work on?" class="input" :aria-invalid="!!form.errors.description" />
        <small v-if="form.errors.description" class="error">{{ form.errors.description }}</small>
      </label>
      <label class="field">
        <span>Date</span>
        <input v-model="form.date" type="date" required class="input" :aria-invalid="!!form.errors.started_at" />
        <small v-if="form.errors.started_at" class="error">{{ form.errors.started_at }}</small>
      </label>
      <label class="field">
        <span>Duration</span>
        <input v-model="durationText" placeholder="1:30" class="input num" :aria-invalid="!!durationError" />
        <small v-if="durationError" class="error">{{ durationError }}</small>
        <small v-else class="hint">{{ durationPreview }}</small>
      </label>
      <label class="field is-check span-all">
        <input v-model="form.billable" type="checkbox" />
        <span>Billable</span>
      </label>
    </div>

    <div class="timer-form__foot">
      <button type="submit" class="btn primary" :disabled="form.processing">{{ editingId ? 'Save changes' : 'Save entry' }}</button>
      <button type="button" class="btn ghost" @click="cancel">Cancel</button>
      <p v-if="otherErrors.length" class="error" role="alert">{{ otherErrors.join(' · ') }}</p>
    </div>
  </form>
</template>
