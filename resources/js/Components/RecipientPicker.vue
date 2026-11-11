<script setup>
const props = defineProps({
  contacts: { type: Array, required: true }, // [{id,name,email,role,is_default}]
  // False while the surrounding form has no client chosen yet.
  clientSelected: { type: Boolean, default: true },
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
    <p v-if="!clientSelected" class="hint">Select a client first.</p>
    <p v-else-if="!contacts.length" class="hint">This client has no contacts. Add contacts on the client page first.</p>
    <label v-for="c in contacts" :key="c.id" class="recipient-row">
      <input type="checkbox" :checked="isChecked(c)" @change="toggle(c)" />
      <span class="recipient-name">{{ c.name }}</span>
      <span class="recipient-email">{{ c.email }}</span>
      <span v-if="c.is_default" class="mono-tag">default</span>
    </label>
  </div>
</template>
