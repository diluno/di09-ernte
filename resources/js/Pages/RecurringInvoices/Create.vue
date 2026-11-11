<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import RecurringInvoiceForm from '@/Components/RecurringInvoiceForm.vue';

defineOptions({ layout: AppLayout });

defineProps({
  clients: { type: Array, default: () => [] }, // [{id,name,contacts}]
  projects: { type: Array, default: () => [] },
  vat_rates: { type: Array, default: () => [] },
});
</script>

<template>
  <Head title="New recurring schedule" />
  <RecurringInvoiceForm :clients="clients" :projects="projects" :vat_rates="vat_rates" />
</template>
