<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import GenerativeMark from '@/Components/GenerativeMark.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post('/confirm-password', {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm password" />

        <form class="auth-card" @submit.prevent="submit">
            <div class="auth-brand"><GenerativeMark :seed="new Date().toISOString().slice(0, 10)" :width="520" :step="5" class="auth-mark" /><span>ernte</span></div>

            <p class="auth-note">This is a secure area. Please confirm your password before continuing.</p>

            <label class="field">
                <span>Password</span>
                <input
                    id="password"
                    type="password"
                    class="input"
                    v-model="form.password"
                    :aria-invalid="!!form.errors.password"
                    required
                    autofocus
                    autocomplete="current-password"
                />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </label>

            <button type="submit" class="btn primary auth-submit" :disabled="form.processing">
                <span>Confirm</span>
                <span aria-hidden="true">&rarr;</span>
            </button>
        </form>
    </GuestLayout>
</template>
