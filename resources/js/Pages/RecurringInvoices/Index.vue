<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  schedules: { type: Array, default: () => [] },
});

const CADENCE_LABEL = {
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  'half-yearly': 'Half-yearly',
  yearly: 'Yearly',
};

function pause(id) { router.post(`/recurring-invoices/${id}/pause`); }
function resume(id) { router.post(`/recurring-invoices/${id}/resume`); }
function run(id) {
  if (confirm('Generate the next invoice for this schedule now?')) {
    router.post(`/recurring-invoices/${id}/run`);
  }
}
function destroy(id) {
  if (confirm('Delete this recurring schedule? Past invoices are kept.')) {
    router.delete(`/recurring-invoices/${id}`);
  }
}
</script>

<template>
  <Head title="Recurring invoices" />

  <div class="page-head">
    <div>
      <div class="crumb"><span>~ / recurring</span></div>
      <h1 class="page-title">Recurring invoices</h1>
    </div>
    <Link href="/recurring-invoices/new" class="btn primary">New schedule</Link>
  </div>

  <div style="padding: 20px 28px 28px">
    <table class="table">
      <thead>
        <tr>
          <th class="pad-l">Client</th>
          <th>Title</th>
          <th>Cadence</th>
          <th>Next run</th>
          <th>Send</th>
          <th class="num">Invoices</th>
          <th style="width: 220px"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="s in schedules" :key="s.id" :class="{ paused: s.paused }">
          <td class="pad-l">{{ s.client.name }}</td>
          <td>{{ s.title || '—' }}</td>
          <td>{{ CADENCE_LABEL[s.cadence] }}</td>
          <td>{{ s.next_run_on }}</td>
          <td>
            <span v-if="s.auto_send" class="badge">auto-send</span>
            <span v-else class="dim" style="font-size: var(--fs-xs)">draft</span>
            <span v-if="s.paused" class="badge warn" style="margin-left: 6px">paused</span>
          </td>
          <td class="num">{{ s.invoices_count }}</td>
          <td style="text-align: right">
            <Link :href="`/recurring-invoices/${s.id}/edit`" class="btn ghost xs">Edit</Link>
            <button v-if="s.paused" class="btn ghost xs" @click="resume(s.id)">Resume</button>
            <button v-else class="btn ghost xs" @click="pause(s.id)">Pause</button>
            <button v-if="!s.paused" class="btn ghost xs" @click="run(s.id)">Generate now</button>
            <button class="btn ghost xs danger" @click="destroy(s.id)">Delete</button>
          </td>
        </tr>
        <tr v-if="schedules.length === 0">
          <td colspan="7" class="pad-l muted" style="padding: 24px">
            No recurring schedules yet. Create one to bill retainers, hosting, or maintenance automatically.
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.paused { opacity: 0.55; }
.badge { font-size: var(--fs-xs); border: 1px solid var(--border-strong); padding: 1px 6px; border-radius: 3px; }
.badge.warn { color: var(--rust); border-color: var(--rust); }
.btn.xs { padding: 2px 8px; font-size: var(--fs-xs); margin-left: 4px; }
.btn.danger { color: var(--red); }
</style>
