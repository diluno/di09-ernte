<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const NAV = [
  { id: 'projects', href: '/projects', label: 'Projects', glyph: '▤' },
  { id: 'timer',    href: '/timer',    label: 'Timer',    glyph: '◐' },
  { id: 'clients',  href: '/clients',  label: 'Clients',  glyph: '◇' },
  { id: 'invoices', href: '/invoices', label: 'Invoices', glyph: '≡' },
  { id: 'reports',  href: '/reports',  label: 'Reports',  glyph: '△' },
];

const page = usePage();
const current = computed(() => page.url);
const isActive = (href) => current.value.startsWith(href);
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
        <span class="glyph">{{ n.glyph }}</span>
        <span>{{ n.label }}</span>
      </Link>
    </nav>

    <div class="side-section">Pinned</div>
    <div class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">No pinned projects yet</div>

    <div class="side-section">This week</div>
    <div class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">Coming in Phase 2</div>
  </aside>
</template>
