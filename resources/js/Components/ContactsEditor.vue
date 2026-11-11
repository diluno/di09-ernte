<script setup>
import Icon from '@/Components/Icon.vue';

const model = defineModel({ type: Array, required: true });

const props = defineProps({
  // The surrounding form's errors; rows read `contacts.{i}.{name|email|role|is_default}`.
  errors: { type: Object, default: () => ({}) },
});

const FIELDS = ['name', 'email', 'role', 'is_default'];
function rowErrors(i) {
  return FIELDS.map((f) => props.errors[`contacts.${i}.${f}`]).filter(Boolean);
}
function invalid(i, field) {
  return props.errors[`contacts.${i}.${field}`] ? 'true' : undefined;
}

function addRow() {
  model.value = [...model.value, { name: '', email: '', role: '', is_default: model.value.length === 0 }];
}
function removeRow(i) {
  model.value = model.value.filter((_, idx) => idx !== i);
}
</script>

<template>
  <div class="contacts-editor">
    <div v-if="model.length" class="contact-head" aria-hidden="true">
      <span>Name</span><span>Email</span><span>Role</span><span>Default</span><span />
    </div>
    <div v-for="(c, i) in model" :key="c.id ?? `new-${i}`" class="contact-block" :class="{ 'is-invalid': rowErrors(i).length }">
      <div class="contact-row">
        <input v-model="c.name" class="input" placeholder="Name" :aria-label="`Contact ${i + 1} name`" :aria-invalid="invalid(i, 'name')" />
        <input v-model="c.email" type="email" class="input" placeholder="Email" :aria-label="`Contact ${i + 1} email`" :aria-invalid="invalid(i, 'email')" />
        <input v-model="c.role" class="input" placeholder="Role (optional)" :aria-label="`Contact ${i + 1} role`" :aria-invalid="invalid(i, 'role')" />
        <label class="contact-default" title="Default recipient of invoices and estimates">
          <input v-model="c.is_default" type="checkbox" :aria-label="`Contact ${i + 1} is a default recipient`" />
          <span>default</span>
        </label>
        <button type="button" class="btn icon sm ghost contact-remove" :aria-label="`Remove contact ${i + 1}`" title="Remove contact" @click="removeRow(i)">
          <Icon name="close" />
        </button>
      </div>
      <small v-for="message in rowErrors(i)" :key="message" class="error contact-err" role="alert">{{ message }}</small>
    </div>
    <p v-if="!model.length" class="hint">No contacts yet. Invoices and estimates are emailed to the default contacts.</p>
    <button type="button" class="btn sm contact-add" @click="addRow">+ Add contact</button>
  </div>
</template>
