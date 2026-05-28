<script setup>
import axios from 'axios';
import { nextTick, ref, watch } from 'vue';
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
const input = ref(null);
let timer = null;

const title = () => props.mode === 'project' ? 'Switch project' : 'Jump to…';

function close() {
  emit('close');
}

async function fetchResults() {
  loading.value = true;
  try {
    const params = { q: query.value };
    if (props.mode === 'project') params.type = 'project';
    const { data } = await axios.get('/api/search', { params });
    results.value = data;
    active.value = 0;
  } finally {
    loading.value = false;
  }
}

function scheduleFetch() {
  clearTimeout(timer);
  timer = setTimeout(fetchResults, 160);
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

  router.visit(result.url);
  close();
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault();
    close();
  } else if (event.key === 'ArrowDown') {
    event.preventDefault();
    active.value = results.value.length ? (active.value + 1) % results.value.length : 0;
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    active.value = results.value.length ? (active.value - 1 + results.value.length) % results.value.length : 0;
  } else if (event.key === 'Enter') {
    event.preventDefault();
    choose();
  }
}

watch(() => props.show, async (show) => {
  if (!show) return;
  query.value = '';
  results.value = [];
  active.value = 0;
  await nextTick();
  input.value?.focus();
  fetchResults();
});

watch(query, scheduleFetch);
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="palette-backdrop" @click.self="close">
      <section class="palette" role="dialog" aria-modal="true" :aria-label="title()">
        <div class="palette-search">
          <span style="color: var(--ink-4)">›</span>
          <input
            ref="input"
            v-model="query"
            :placeholder="mode === 'project' ? 'project…' : 'project, client, invoice…'"
            @keydown="onKeydown"
          />
          <span class="kbd">esc</span>
        </div>

        <div class="palette-list">
          <button
            v-for="(result, i) in results"
            :key="`${result.type}-${result.id}`"
            class="palette-row"
            :aria-selected="i === active"
            @mouseenter="active = i"
            @click="choose(result)"
          >
            <span class="palette-type">{{ result.type }}</span>
            <span class="palette-main">
              <strong>{{ result.label }}</strong>
              <small>{{ result.sublabel }}</small>
            </span>
          </button>
          <div v-if="loading" class="palette-empty">Searching…</div>
          <div v-else-if="results.length === 0" class="palette-empty">No results</div>
        </div>
      </section>
    </div>
  </Teleport>
</template>
