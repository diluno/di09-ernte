<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

defineProps({
    clients:  { type: Array, required: true },
    projects: { type: Array, required: true },
});
</script>

<template>
    <Head title="New Estimate" />
    <div><!-- Estimates/Create stub --></div>
</template>
