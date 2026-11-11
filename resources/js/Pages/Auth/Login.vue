<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Sign in" />

        <form class="auth-card" @submit.prevent="submit">
            <div class="auth-brand">ernte</div>

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

            <label class="field">
                <span>Password</span>
                <input
                    id="password"
                    type="password"
                    class="input"
                    v-model="form.password"
                    :aria-invalid="!!form.errors.password"
                    required
                    autocomplete="current-password"
                />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </label>

            <label class="field is-check">
                <input type="checkbox" v-model="form.remember" />
                <span>Remember me</span>
            </label>

            <button type="submit" class="btn primary auth-submit" :disabled="form.processing">
                <span>Sign in</span>
                <span aria-hidden="true">&rarr;</span>
            </button>
        </form>
    </GuestLayout>
</template>
