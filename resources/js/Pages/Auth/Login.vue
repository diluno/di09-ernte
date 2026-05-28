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

            <p v-if="status" class="auth-status">{{ status }}</p>

            <label class="field">
                <span>Email</span>
                <input
                    id="email"
                    type="email"
                    class="input"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />
                <span v-if="form.errors.email" class="error">{{ form.errors.email }}</span>
            </label>

            <label class="field">
                <span>Password</span>
                <input
                    id="password"
                    type="password"
                    class="input"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />
                <span v-if="form.errors.password" class="error">{{ form.errors.password }}</span>
            </label>

            <label class="auth-remember">
                <input type="checkbox" v-model="form.remember" />
                <span>remember me</span>
            </label>

            <button type="submit" class="btn primary auth-submit" :disabled="form.processing">
                <span>sign in</span>
                <span aria-hidden="true">&rarr;</span>
            </button>
        </form>
    </GuestLayout>
</template>

<style scoped>
.auth-card {
    display: flex;
    flex-direction: column;
    gap: 16px;
    width: 100%;
    max-width: 360px;
    padding: 28px;
    background: var(--paper);
    border: 1px solid var(--border);
    border-radius: 4px;
    box-shadow: 0 12px 32px rgba(26, 26, 26, 0.08);
}

.auth-brand {
    font-size: var(--fs-lg);
    color: var(--ink);
    letter-spacing: -0.5px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border);
}

.auth-status {
    margin: 0;
    font-size: var(--fs-sm);
    color: var(--forest);
}

.auth-remember {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--fs-sm);
    color: var(--ink-2);
    cursor: pointer;
}

.auth-remember input {
    accent-color: var(--accent);
    cursor: pointer;
}

.auth-submit {
    justify-content: center;
    width: 100%;
    min-height: var(--row-h);
    font-size: var(--fs-sm);
}

.auth-submit:disabled {
    opacity: 0.5;
    cursor: default;
}
</style>
