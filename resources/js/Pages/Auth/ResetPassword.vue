<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Reset password" />

        <form class="auth-card" @submit.prevent="submit">
            <div class="auth-brand">ernte</div>

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
                <span>New password</span>
                <input
                    id="password"
                    type="password"
                    class="input"
                    v-model="form.password"
                    :aria-invalid="!!form.errors.password"
                    required
                    autocomplete="new-password"
                />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </label>

            <label class="field">
                <span>Confirm new password</span>
                <input
                    id="password_confirmation"
                    type="password"
                    class="input"
                    v-model="form.password_confirmation"
                    :aria-invalid="!!form.errors.password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <small v-if="form.errors.password_confirmation" class="error">{{ form.errors.password_confirmation }}</small>
            </label>

            <button type="submit" class="btn primary auth-submit" :disabled="form.processing">
                <span>Reset password</span>
                <span aria-hidden="true">&rarr;</span>
            </button>
        </form>
    </GuestLayout>
</template>
