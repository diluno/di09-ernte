<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
defineOptions({ layout: AppLayout });
</script>

<template>
  <Head title="Reports" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / reports</div>
      <h1 class="page-title">
        Reports
        <span class="meta">Last 90 days</span>
      </h1>
    </div>
  </div>
  <div style="padding: 28px; color: var(--ink-3)">
    <div style="border: 1px dashed var(--border-strong); padding: 48px; text-align: center">
      Reports view — utilization, revenue, project profitability.
    </div>
  </div>
</template>
