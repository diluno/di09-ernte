<script setup></script>

<template>
    <div class="guest-shell">
        <slot />
    </div>
</template>

<style scoped>
.guest-shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: var(--bg);
    color: var(--ink);
    font-family: var(--font-mono);
}
</style>
