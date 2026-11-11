<script setup>
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
  show: { type: Boolean, default: false },
  mode: { type: String, default: 'all' },
});

const emit = defineEmits(['close']);

const query = ref('');
const results = ref([]);
const active = ref(0);
const loading = ref(false);
const failed = ref(false);
const input = ref(null);
const dialog = ref(null);
let timer = null;
let requestId = 0;      // only the latest request may write results
let controller = null;  // aborts the previous in-flight request
let returnFocus = null; // element focused before the palette opened

const title = computed(() => props.mode === 'project' ? 'Switch project' : 'Jump to…');
const optionId = (i) => `palette-option-${i}`;
const activeId = computed(() => results.value.length ? optionId(active.value) : undefined);

function close() {
  emit('close');
}

async function fetchResults() {
  const id = ++requestId;
  controller?.abort();
  controller = new AbortController();
  loading.value = true;
  failed.value = false;

  try {
    const params = { q: query.value };
    if (props.mode === 'project') params.type = 'project';
    const { data } = await axios.get('/api/search', { params, signal: controller.signal });
    if (id !== requestId) return; // a newer search superseded this one
    results.value = Array.isArray(data) ? data : [];
    active.value = 0;
  } catch (error) {
    if (axios.isCancel(error) || id !== requestId) return;
    results.value = [];
    failed.value = true;
  } finally {
    if (id === requestId) loading.value = false;
  }
}

function scheduleFetch() {
  clearTimeout(timer);
  timer = setTimeout(fetchResults, 160);
}

function cancelPending() {
  clearTimeout(timer);
  requestId++;
  controller?.abort();
  controller = null;
  loading.value = false;
}

function choose(result = results.value[active.value]) {
  if (!result) return;

  if (props.mode === 'project') {
    router.post('/timer/start', { project_id: result.id }, {
      preserveScroll: true,
      onFinish: close,
    });
    return;
  }

  // Navigating away: don't hand focus back to the old page's element.
  returnFocus = null;
  router.visit(result.url);
  close();
}

function setActive(i) {
  active.value = i;
  nextTick(() => document.getElementById(optionId(i))?.scrollIntoView({ block: 'nearest' }));
}

function onInputKeydown(event) {
  const count = results.value.length;
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    if (count) setActive((active.value + 1) % count);
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    if (count) setActive((active.value - 1 + count) % count);
  } else if (event.key === 'Home' && count) {
    event.preventDefault();
    setActive(0);
  } else if (event.key === 'End' && count) {
    event.preventDefault();
    setActive(count - 1);
  } else if (event.key === 'Enter') {
    event.preventDefault();
    choose();
  }
}

// Dialog-level keys: Escape closes; Tab is trapped. The input is the only tab
// stop (options are reached with the arrow keys), so Tab keeps focus on it.
function onDialogKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    close();
  } else if (event.key === 'Tab') {
    event.preventDefault();
    input.value?.focus();
  }
}

// Focus that escapes the dialog (e.g. a click on the page chrome) is pulled back.
function onFocusIn(event) {
  if (props.show && dialog.value && !dialog.value.contains(event.target)) input.value?.focus();
}

watch(() => props.show, async (show) => {
  if (!show) {
    cancelPending();
    document.removeEventListener('focusin', onFocusIn);
    const target = returnFocus;
    returnFocus = null;
    if (target?.isConnected) nextTick(() => target.focus?.());
    return;
  }
  returnFocus = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
    ? document.activeElement
    : null;
  query.value = '';
  results.value = [];
  active.value = 0;
  failed.value = false;
  await nextTick();
  input.value?.focus();
  document.addEventListener('focusin', onFocusIn);
  fetchResults();
});

watch(query, () => { if (props.show) scheduleFetch(); });

onBeforeUnmount(() => {
  cancelPending();
  document.removeEventListener('focusin', onFocusIn);
});
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="palette-backdrop" @mousedown.self="close">
      <section ref="dialog" class="palette" role="dialog" aria-modal="true" :aria-label="title" @keydown="onDialogKeydown">
        <div class="palette-search">
          <span style="color: var(--ink-3)" aria-hidden="true">›</span>
          <input
            ref="input"
            v-model="query"
            type="text"
            role="combobox"
            aria-autocomplete="list"
            aria-haspopup="listbox"
            aria-expanded="true"
            aria-controls="palette-listbox"
            :aria-activedescendant="activeId"
            :aria-label="title"
            autocomplete="off"
            spellcheck="false"
            :placeholder="mode === 'project' ? 'project…' : 'project, client, invoice, note…'"
            @keydown="onInputKeydown"
          />
          <span class="kbd" aria-hidden="true">esc</span>
        </div>

        <div id="palette-listbox" class="palette-list" role="listbox" :aria-label="`${title} results`" :aria-busy="loading">
          <div
            v-for="(result, i) in results"
            :id="optionId(i)"
            :key="`${result.type}-${result.id}`"
            class="palette-row"
            role="option"
            :aria-selected="i === active"
            @mouseenter="active = i"
            @mousedown.prevent
            @click="choose(result)"
          >
            <span class="palette-type">{{ result.type }}</span>
            <span class="palette-main">
              <strong>{{ result.label }}</strong>
              <small>{{ result.sublabel }}</small>
            </span>
          </div>
        </div>
        <div class="palette-empty" role="status" aria-live="polite">
          <template v-if="failed">Search failed. Keep typing to try again.</template>
          <template v-else-if="loading && results.length === 0">Searching…</template>
          <template v-else-if="results.length === 0">No results</template>
        </div>
      </section>
    </div>
  </Teleport>
</template>
