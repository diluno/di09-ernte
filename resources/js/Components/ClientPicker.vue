<script setup>
// Searchable single-select (ARIA 1.2 combobox with a listbox popup). Generic: the
// model is the chosen option's id (or null), `options` are [{ id, name }].
//
//   <div class="field">
//     <span id="client-label">Client</span>
//     <ClientPicker v-model="clientId" :options="clients" labelledby="client-label" />
//   </div>
//
// Type to filter (case- and accent-insensitive; names starting with the query come
// first). ↓/↑ open the list and move, Enter picks, Esc closes (a second Esc restores
// the chosen name), Tab leaves. Enter on a closed list is left alone, so inside a
// <form> it submits. Blurring restores the chosen name; clearing the text clears it.
import { computed, nextTick, ref, useId, watch } from 'vue';

const model = defineModel({ type: [Number, String], default: null });

const props = defineProps({
  options: { type: Array, required: true }, // [{ id, name }]
  placeholder: { type: String, default: 'Type to search…' },
  // Accessible name: the id of a visible label, or a plain label.
  labelledby: { type: String, default: null },
  label: { type: String, default: null },
  inputId: { type: String, default: null },
  invalid: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  emptyText: { type: String, default: 'No match' },
});

const emit = defineEmits(['select']);

const uid = useId();
const listId = `${uid}-list`;
const optionId = (index) => `${uid}-opt-${index}`;

const inputEl = ref(null);
const listEl = ref(null);
const open = ref(false);
const active = ref(-1);

const selected = computed(() => props.options.find((o) => o.id === model.value) ?? null);
const query = ref(selected.value?.name ?? '');

// Typing filters; a query equal to the chosen name shows the full list.
const typed = ref(false);
watch(selected, (s) => { if (!typed.value) query.value = s?.name ?? ''; });

const fold = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const filtered = computed(() => {
  const q = fold(query.value.trim());
  if (!typed.value || !q) return props.options;
  const starts = [];
  const contains = [];
  for (const o of props.options) {
    const name = fold(o.name);
    if (name.startsWith(q)) starts.push(o);
    else if (name.includes(q)) contains.push(o);
  }
  return [...starts, ...contains];
});

const activeDescendant = computed(() => (open.value && active.value >= 0 ? optionId(active.value) : undefined));

function scrollActiveIntoView() {
  nextTick(() => {
    listEl.value?.querySelector(`#${CSS.escape(optionId(active.value))}`)?.scrollIntoView({ block: 'nearest' });
  });
}

function show(index = null) {
  if (props.disabled) return;
  open.value = true;
  const current = filtered.value.findIndex((o) => o.id === model.value);
  active.value = index ?? (current >= 0 ? current : (filtered.value.length ? 0 : -1));
  scrollActiveIntoView();
}
function hide() {
  open.value = false;
  active.value = -1;
}
function restore() {
  typed.value = false;
  query.value = selected.value?.name ?? '';
}

function choose(option) {
  model.value = option.id;
  typed.value = false;
  query.value = option.name;
  hide();
  emit('select', option);
}

function move(delta) {
  if (!open.value) { show(delta > 0 ? null : filtered.value.length - 1); return; }
  const n = filtered.value.length;
  if (!n) return;
  active.value = active.value < 0 ? (delta > 0 ? 0 : n - 1) : (active.value + delta + n) % n;
  scrollActiveIntoView();
}

function onInput(event) {
  query.value = event.target.value;
  typed.value = true;
  if (query.value.trim() === '') model.value = null;
  show(filtered.value.length ? 0 : -1);
}

function onKeydown(event) {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      if (event.altKey) show(); else move(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      move(-1);
      break;
    case 'Enter':
      if (open.value && active.value >= 0 && filtered.value[active.value]) {
        event.preventDefault();
        choose(filtered.value[active.value]);
      } else if (open.value) {
        event.preventDefault();
      }
      break;
    case 'Escape':
      if (open.value) { event.preventDefault(); hide(); }
      else if (typed.value) { event.preventDefault(); restore(); }
      break;
    case 'Tab':
      hide();
      break;
  }
}

function onBlur() {
  hide();
  restore();
}

defineExpose({ focus: () => inputEl.value?.focus() });
</script>

<template>
  <div class="combo" :class="{ 'is-open': open }">
    <input
      :id="inputId || undefined"
      ref="inputEl"
      class="select combo__input"
      type="text"
      role="combobox"
      autocomplete="off"
      spellcheck="false"
      aria-autocomplete="list"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="listId"
      :aria-activedescendant="activeDescendant"
      :aria-labelledby="labelledby || undefined"
      :aria-label="labelledby ? undefined : (label || 'Client')"
      :aria-invalid="invalid ? 'true' : undefined"
      :placeholder="placeholder"
      :disabled="disabled"
      :value="query"
      @input="onInput"
      @keydown="onKeydown"
      @click="open ? null : show()"
      @blur="onBlur"
    />
    <ul v-show="open" :id="listId" ref="listEl" class="combo__list" role="listbox" @mousedown.prevent :aria-labelledby="labelledby || undefined" :aria-label="labelledby ? undefined : (label || 'Client')">
      <li
        v-for="(o, i) in filtered" :id="optionId(i)" :key="o.id"
        class="combo__option" :class="{ 'is-active': i === active }"
        role="option" :aria-selected="o.id === model ? 'true' : 'false'"
        @mousemove="active = i"
        @click="choose(o)"
      >{{ o.name }}</li>
      <li v-if="!filtered.length" class="combo__empty" role="presentation">{{ emptyText }}</li>
    </ul>
  </div>
</template>
