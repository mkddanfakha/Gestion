<template>
  <div class="customer-form-fields">
    <div class="mb-4">
      <h6 class="text-uppercase text-muted small fw-semibold mb-3">Informations générales</h6>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" :for="fieldId('name')">
            Nom complet <span class="text-danger">*</span>
          </label>
          <input
            :id="fieldId('name')"
            ref="nameInputRef"
            v-model="form.name"
            type="text"
            required
            class="form-control"
            :class="{ 'is-invalid': errors.name || clientErrors.name }"
            autocomplete="name"
            @blur="emitValidate('name', form.name)"
            @input="emitValidate('name', form.name)"
          />
          <div v-if="errors.name" class="invalid-feedback">{{ errors.name }}</div>
          <div v-if="clientErrors.name" class="invalid-feedback">{{ clientErrors.name }}</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" :for="fieldId('email')">Email</label>
          <input
            :id="fieldId('email')"
            v-model="form.email"
            type="email"
            class="form-control"
            :class="{ 'is-invalid': errors.email || clientErrors.email }"
            autocomplete="email"
            @blur="emitValidate('email', form.email)"
            @input="emitValidate('email', form.email)"
          />
          <div v-if="errors.email" class="invalid-feedback">{{ errors.email }}</div>
          <div v-if="clientErrors.email" class="invalid-feedback">{{ clientErrors.email }}</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" :for="fieldId('phone')">Téléphone</label>
          <input
            :id="fieldId('phone')"
            v-model="form.phone"
            type="tel"
            class="form-control"
            :class="{ 'is-invalid': errors.phone || clientErrors.phone }"
            autocomplete="tel"
            inputmode="tel"
            @blur="emitValidate('phone', form.phone)"
            @input="emitValidate('phone', form.phone)"
          />
          <div v-if="errors.phone" class="invalid-feedback">{{ errors.phone }}</div>
          <div v-if="clientErrors.phone" class="invalid-feedback">{{ clientErrors.phone }}</div>
        </div>
      </div>
    </div>

    <div class="mb-4">
      <h6 class="text-uppercase text-muted small fw-semibold mb-3">Adresse</h6>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label" :for="fieldId('address')">Adresse</label>
          <textarea
            :id="fieldId('address')"
            v-model="form.address"
            rows="3"
            class="form-control"
            :class="{ 'is-invalid': errors.address }"
            autocomplete="street-address"
          ></textarea>
          <div v-if="errors.address" class="invalid-feedback">{{ errors.address }}</div>
        </div>

        <div class="col-md-4">
          <label class="form-label" :for="fieldId('city')">Ville</label>
          <input
            :id="fieldId('city')"
            v-model="form.city"
            type="text"
            class="form-control"
            :class="{ 'is-invalid': errors.city }"
            autocomplete="address-level2"
          />
          <div v-if="errors.city" class="invalid-feedback">{{ errors.city }}</div>
        </div>

        <div class="col-md-4">
          <label class="form-label" :for="fieldId('postal_code')">Code postal</label>
          <input
            :id="fieldId('postal_code')"
            v-model="form.postal_code"
            type="text"
            class="form-control"
            :class="{ 'is-invalid': errors.postal_code }"
            autocomplete="postal-code"
          />
          <div v-if="errors.postal_code" class="invalid-feedback">{{ errors.postal_code }}</div>
        </div>

        <div class="col-md-4">
          <label class="form-label" :for="fieldId('country')">Pays</label>
          <input
            :id="fieldId('country')"
            v-model="form.country"
            type="text"
            class="form-control"
            :class="{ 'is-invalid': errors.country }"
            autocomplete="country-name"
          />
          <div v-if="errors.country" class="invalid-feedback">{{ errors.country }}</div>
        </div>
      </div>
    </div>

    <div>
      <h6 class="text-uppercase text-muted small fw-semibold mb-3">Statut</h6>
      <div class="form-check form-switch">
        <input
          :id="fieldId('is_active')"
          v-model="form.is_active"
          class="form-check-input"
          type="checkbox"
        />
        <label class="form-check-label" :for="fieldId('is_active')">
          Client actif
        </label>
        <div class="form-text">
          Un client inactif ne sera pas visible dans les ventes
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import type { CustomerFormData } from '@/composables/useCustomerFormValidation'

const props = withDefaults(defineProps<{
  form: CustomerFormData
  errors?: Record<string, string>
  clientErrors?: Record<string, string>
  idPrefix?: string
}>(), {
  errors: () => ({}),
  clientErrors: () => ({}),
  idPrefix: 'customer',
})

const emit = defineEmits<{
  (e: 'validate-field', fieldName: string, value: unknown): void
}>()

const nameInputRef = ref<HTMLInputElement | null>(null)

const fieldId = (field: string) => `${props.idPrefix}-${field}`

const emitValidate = (fieldName: string, value: unknown) => {
  emit('validate-field', fieldName, value)
}

defineExpose({
  focusName: () => nameInputRef.value?.focus(),
})
</script>
