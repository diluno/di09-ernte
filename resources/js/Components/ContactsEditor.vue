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
      <button type="button" class="contact-remove" @click="removeRow(i)" aria-label="Remove contact">✕</button>
    </div>
    <button type="button" class="btn ghost" @click="addRow">+ Add contact</button>
  </div>
</template>

<style scoped>
.contact-row { display: grid; grid-template-columns: 1fr 1fr 0.7fr auto auto; gap: 8px; align-items: center; margin-bottom: 8px; }
.contact-row input[type="text"],
.contact-row input[type="email"],
.contact-row input:not([type]) {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; font-size: var(--fs-sm); color: var(--ink);
  min-width: 0;
}
.contact-row input:focus { outline: none; border-color: var(--accent); }
.default-toggle { display: flex; align-items: center; gap: 4px; font-size: var(--fs-xs); color: var(--ink-3); white-space: nowrap; }
.contact-remove {
  border: none;
  background: none;
  cursor: pointer;
  color: var(--ink-3);
  font-size: 14px;
  line-height: 1;
  padding: 2px 6px;
}
.contact-remove:hover { color: var(--red); }
</style>
