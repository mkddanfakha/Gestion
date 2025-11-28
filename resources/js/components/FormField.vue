<script setup lang="ts">
import { Field, ErrorMessage } from 'vee-validate';

interface Props {
  name: string;
  label: string;
  type?: string;
  placeholder?: string;
  required?: boolean;
  autocomplete?: string;
  id?: string;
}

withDefaults(defineProps<Props>(), {
  type: 'text',
  required: false,
});
</script>

<template>
  <div class="mb-3">
    <label :for="id || name" class="form-label">
      {{ label }}
      <span v-if="required" class="text-danger">*</span>
    </label>
    <Field
      :id="id || name"
      :name="name"
      :type="type"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      class="form-control"
      :class="{ 'is-invalid': false }"
      v-slot="{ field, meta }"
    >
      <input
        v-bind="field"
        :id="id || name"
        :type="type"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        class="form-control"
        :class="{ 'is-invalid': meta.touched && !meta.valid }"
      />
    </Field>
    <ErrorMessage :name="name" class="invalid-feedback d-block" />
  </div>
</template>

