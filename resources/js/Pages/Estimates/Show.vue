<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

defineProps({
    estimate:    { type: Object, required: true },
    events:      { type: Array, required: true },
    preview_url: { type: String, required: true },
    pdf_url:     { type: String, required: true },
});
</script>

<template>
    <Head title="Estimate" />
    <div><!-- Estimates/Show stub --></div>
</template>
