<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password');
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot password" />

        <form class="auth-card" @submit.prevent="submit">
            <div class="auth-brand">ernte</div>

            <p class="auth-note">Enter your email address and we will send you a link to choose a new password.</p>

            <p v-if="status" class="auth-status" role="status">{{ status }}</p>

            <label class="field">
                <span>Email</span>
                <input
                    id="email"
                    type="email"
                    class="input"
                    v-model="form.email"
                    :aria-invalid="!!form.errors.email"
                    required
                    autofocus
                    autocomplete="username"
                />
                <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
            </label>

            <button type="submit" class="btn primary auth-submit" :disabled="form.processing">
                <span>Email reset link</span>
                <span aria-hidden="true">&rarr;</span>
            </button>

            <Link href="/login" class="auth-link">Back to sign in</Link>
        </form>
    </GuestLayout>
</template>
