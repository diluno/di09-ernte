<script setup>
const model = defineModel({ type: Array, required: true });

function addRow() {
  model.value = [...model.value, { name: '', email: '', role: '', is_default: model.value.length === 0 }];
}
function removeRow(i) {
  model.value = model.value.filter((_, idx) => idx !== i);
}
</script>

<template>
  <div class="contacts-editor">
    <div v-for="(c, i) in model" :key="c.id ?? `new-${i}`" class="contact-row">
      <input v-model="c.name" placeholder="Name" aria-label="Contact name" />
      <input type="email" v-model="c.email" placeholder="Email" aria-label="Contact email" />
      <input v-model="c.role" placeholder="Role (optional)" aria-label="Contact role" />
      <label class="default-toggle" title="Default recipient">
        <input type="checkbox" v-model="c.is_default" /> default
      </label>
      <button type="button" class="row-action row-action--danger" @click="removeRow(i)" aria-label="Remove contact">✕</button>
    </div>
    <button type="button" class="btn btn--ghost" @click="addRow">+ Add contact</button>
  </div>
</template>

<style scoped>
.contact-row { display: grid; grid-template-columns: 1fr 1fr 0.7fr auto auto; gap: 8px; align-items: center; margin-bottom: 8px; }
.default-toggle { display: flex; align-items: center; gap: 4px; font-size: var(--fs-xs); color: var(--ink-3); white-space: nowrap; }
</style>
