<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import WeekBars from '@/Components/WeekBars.vue';
import Icon from '@/Components/Icon.vue';

const page = usePage();
const sidebar = computed(() => page.props.sidebar ?? { nav_counts: {}, pinned: [], week_hours: [0,0,0,0,0,0,0], today_hours: 0 });

const NAV = computed(() => [
  { id: 'projects', href: '/projects', label: 'Projects', icon: 'briefcase', count: sidebar.value.nav_counts.projects },
  { id: 'timer',    href: '/timer',    label: 'Timer',    icon: 'clock', count: sidebar.value.today_hours ? `${sidebar.value.today_hours.toFixed(1)}h` : null },
  { id: 'clients',  href: '/clients',  label: 'Clients',  icon: 'users', count: sidebar.value.nav_counts.clients },
  { id: 'invoices', href: '/invoices', label: 'Invoices', icon: 'receipt', count: null },
  { id: 'estimates', href: '/estimates', label: 'Estimates', icon: 'edit', count: null },
  { id: 'recurring', href: '/recurring-invoices', label: 'Recurring', icon: 'repeat', count: null },
]);

const current = computed(() => page.url);
const isActive = (href) => current.value.startsWith(href);

// Recent: last 5 visited entities, kept in localStorage. Tracked by visiting a project or client page (see Show pages).
const recent = ref([]);
onMounted(() => {
  try { recent.value = JSON.parse(localStorage.getItem('ernte.recent') ?? '[]'); }
  catch { recent.value = []; }
});
// Re-read when Inertia navigates (because pages push entries on visit).
watch(current, () => {
  try { recent.value = JSON.parse(localStorage.getItem('ernte.recent') ?? '[]'); }
  catch {}
});

const weekTotal = computed(() => sidebar.value.week_hours.reduce((a, h) => a + h, 0).toFixed(1));
</script>

<template>
  <aside class="sidebar">
    <nav>
      <Link
        v-for="n in NAV" :key="n.id"
        :href="n.href"
        class="nav-item"
        :aria-current="isActive(n.href) ? 'page' : undefined"
      >
        <Icon :name="n.icon" class="glyph" />
        <span>{{ n.label }}</span>
        <span v-if="n.count !== null && n.count !== undefined" class="count">{{ n.count }}</span>
      </Link>
    </nav>

    <div class="side-section">Pinned</div>
    <div v-if="sidebar.pinned.length === 0" class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">No pinned projects</div>
    <Link
      v-for="(p, i) in sidebar.pinned" :key="p.id"
      :href="`/projects/${p.code}`"
      class="pin-row"
    >
      <span class="pin-dot" :class="{ solid: i < 2 }" :style="{ color: ['var(--forest)', 'var(--rust)', 'var(--ink)', 'var(--gold)'][i % 4] }" />
      <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ p.name }}</span>
    </Link>

    <div class="side-section">Recent</div>
    <div v-if="recent.length === 0" class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">—</div>
    <Link
      v-for="r in recent" :key="r.url"
      :href="r.url"
      class="pin-row muted"
      style="font-size: var(--fs-xs)"
    >{{ r.label }}</Link>

    <div style="flex: 1" />
    <div style="padding: 12px 14px; border-top: 1px solid var(--border); margin-top: 8px">
      <div style="font-size: var(--fs-xs); color: var(--ink-4); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 8px">This week</div>
      <div style="font-size: var(--fs-lg); font-weight: 700; color: var(--ink); letter-spacing: -0.02em">
        {{ weekTotal }}<span style="font-size: var(--fs-sm); color: var(--ink-3); font-weight: 400; margin-left: 2px">h</span>
        <span style="color: var(--ink-4); font-weight: 400; font-size: var(--fs-sm); margin-left: 6px">/ 40h</span>
      </div>
      <WeekBars :hours="sidebar.week_hours" />
    </div>
  </aside>
</template>
