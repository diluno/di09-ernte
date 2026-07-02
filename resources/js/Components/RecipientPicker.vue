<script setup>
const props = defineProps({
  contacts: { type: Array, required: true }, // [{id,name,email,role,is_default}]
});
const model = defineModel({ type: Array, required: true }); // [{name,email}]

function isChecked(contact) {
  return model.value.some((r) => r.email === contact.email);
}
function toggle(contact) {
  if (isChecked(contact)) {
    model.value = model.value.filter((r) => r.email !== contact.email);
  } else {
    model.value = [...model.value, { name: contact.name, email: contact.email }];
  }
}
</script>

<template>
  <div class="recipient-picker">
    <p v-if="!contacts.length" class="muted">This client has no contacts. Add contacts on the client page first.</p>
    <label v-for="c in contacts" :key="c.id" class="recipient-row">
      <input type="checkbox" :checked="isChecked(c)" @change="toggle(c)" />
      <span class="recipient-name">{{ c.name }}</span>
      <span class="muted">{{ c.email }}</span>
      <span v-if="c.is_default" class="badge dot sent">default</span>
    </label>
  </div>
</template>

<style scoped>
.recipient-row { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: var(--fs-sm); }
.recipient-name { color: var(--ink); }
</style>
