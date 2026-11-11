<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AutoTextarea from '@/Components/AutoTextarea.vue';
import { fmtDateTime } from '@/formatters/date.js';

const props = defineProps({
  projectCode: { type: String, required: true },
  notes: { type: Array, required: true },
});

const addForm = useForm({ body: '' });
const editForm = useForm({ body: '' });
const editingId = ref(null);
const highlightId = ref(null);


function add() {
  if (!addForm.body.trim()) return;
  addForm.post(`/projects/${props.projectCode}/notes`, {
    preserveScroll: true,
    onSuccess: () => addForm.reset(),
  });
}

function startEdit(note) {
  editingId.value = note.id;
  editForm.clearErrors();
  editForm.body = note.body;
}

function cancelEdit() { editingId.value = null; }

function saveEdit(note) {
  editForm.patch(`/project-notes/${note.id}`, {
    preserveScroll: true,
    onSuccess: cancelEdit,
  });
}

function remove(note) {
  if (window.confirm('Delete this note?')) {
    router.delete(`/project-notes/${note.id}`, { preserveScroll: true });
  }
}

// ⌘K results link to #note-{id}: bring that note into view and flash it.
onMounted(async () => {
  const match = window.location.hash.match(/^#note-(\d+)$/);
  if (!match) return;
  highlightId.value = Number(match[1]);
  await nextTick();
  document.getElementById(`note-${match[1]}`)?.scrollIntoView({ block: 'start' });
});
</script>

<template>
  <div class="notes">
    <form class="note-compose" @submit.prevent="add">
      <AutoTextarea
        v-model="addForm.body"
        class="note-input"
        placeholder="Add a note… (markdown, ⌘↵ to save)"
        aria-label="New note"
        :aria-invalid="addForm.errors.body ? 'true' : undefined"
        @keydown.meta.enter.prevent="add"
        @keydown.ctrl.enter.prevent="add"
      />
      <button type="submit" class="btn sm" :disabled="addForm.processing || !addForm.body.trim()">Add note</button>
    </form>
    <small v-if="addForm.errors.body" class="error" role="alert">{{ addForm.errors.body }}</small>

    <article
      v-for="note in notes"
      :id="`note-${note.id}`"
      :key="note.id"
      class="note"
      :class="{ 'is-target': note.id === highlightId }"
    >
      <header class="note-meta">
        <time :datetime="note.created_at">{{ fmtDateTime(note.created_at) }}</time>
        <span v-if="note.edited">· edited</span>
        <span class="note-actions">
          <button type="button" class="note-action" @click="startEdit(note)">Edit</button>
          <button type="button" class="note-action is-danger" @click="remove(note)">Delete</button>
        </span>
      </header>

      <form v-if="editingId === note.id" class="note-compose" @submit.prevent="saveEdit(note)">
        <AutoTextarea
          v-model="editForm.body"
          class="note-input"
          aria-label="Edit note"
          @keydown.meta.enter.prevent="saveEdit(note)"
          @keydown.ctrl.enter.prevent="saveEdit(note)"
          @keydown.esc.prevent="cancelEdit"
        />
        <div class="note-compose__actions">
          <button type="submit" class="btn sm" :disabled="editForm.processing">Save</button>
          <button type="button" class="btn ghost sm" @click="cancelEdit">Cancel</button>
        </div>
        <small v-if="editForm.errors.body" class="error" role="alert">{{ editForm.errors.body }}</small>
      </form>
      <!-- body_html comes from App\Support\Markdown, which escapes raw HTML. -->
      <div v-else class="note-body" v-html="note.body_html" />
    </article>

    <div v-if="notes.length === 0" class="section-empty">No notes yet.</div>
  </div>
</template>
