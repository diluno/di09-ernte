<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

defineProps({
    estimates: { type: Array, required: true },
    stats:     { type: Object, required: true },
    counts:    { type: Object, required: true },
    filters:   { type: Object, required: true },
});
</script>

<template>
    <Head title="Estimates" />
    <div><!-- Estimates/Index stub --></div>
</template>
