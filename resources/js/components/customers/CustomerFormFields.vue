<template>
  <div class="customer-form-fields">
    <div class="mb-4">
      <h6 class="text-uppercase text-muted small fw-semibold mb-3">Informations personnelles</h6>
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
            @blur="handleNameBlur"
            @input="handleNameInput"
          />
          <div v-if="errors.name" class="invalid-feedback">{{ errors.name }}</div>
          <div v-if="clientErrors.name" class="invalid-feedback">{{ clientErrors.name }}</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" :for="fieldId('birthday')">Date d'anniversaire</label>
          <input
            :id="fieldId('birthday')"
            v-model="form.birthday"
            type="date"
            class="form-control"
            :class="{ 'is-invalid': errors.birthday || clientErrors.birthday }"
            :max="today"
          />
          <div v-if="errors.birthday" class="invalid-feedback">{{ errors.birthday }}</div>
          <div v-if="clientErrors.birthday" class="invalid-feedback">{{ clientErrors.birthday }}</div>
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
            @blur="handlePhoneBlur"
            @input="handlePhoneInput"
          />
          <div v-if="errors.phone" class="invalid-feedback">{{ errors.phone }}</div>
          <div v-if="clientErrors.phone" class="invalid-feedback">{{ clientErrors.phone }}</div>
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
            @blur="handleEmailBlur"
            @input="handleEmailInput"
          />
          <div v-if="errors.email" class="invalid-feedback">{{ errors.email }}</div>
          <div v-if="clientErrors.email" class="invalid-feedback">{{ clientErrors.email }}</div>
        </div>
      </div>
    </div>

    <div class="mb-4">
      <h6 class="text-uppercase text-muted small fw-semibold mb-3">Identité</h6>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" :for="fieldId('identity_document_type')">Type de pièce</label>
          <select
            :id="fieldId('identity_document_type')"
            v-model="form.identity_document_type"
            class="form-select"
            :class="{ 'is-invalid': errors.identity_document_type || clientErrors.identity_document_type }"
            @change="handleIdentityChange"
          >
            <option value="">Sélectionner...</option>
            <option
              v-for="type in identityTypes"
              :key="type.value"
              :value="type.value"
            >
              {{ type.label }}
            </option>
          </select>
          <div v-if="errors.identity_document_type" class="invalid-feedback">{{ errors.identity_document_type }}</div>
          <div v-if="clientErrors.identity_document_type" class="invalid-feedback">{{ clientErrors.identity_document_type }}</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" :for="fieldId('identity_document_number')">Numéro de pièce</label>
          <input
            :id="fieldId('identity_document_number')"
            v-model="form.identity_document_number"
            type="text"
            class="form-control"
            :class="{
              'is-invalid': errors.identity_document_number || clientErrors.identity_document_number || Boolean(analysis.identity_conflict),
              'is-valid': identityFieldsComplete && analysis.identity_available && !analysis.identity_conflict,
            }"
            autocomplete="off"
            @blur="handleIdentityBlur"
            @input="handleIdentityInput"
          />
          <div v-if="errors.identity_document_number" class="invalid-feedback">{{ errors.identity_document_number }}</div>
          <div v-if="clientErrors.identity_document_number" class="invalid-feedback">{{ clientErrors.identity_document_number }}</div>
          <div class="form-text">
            Le numéro de pièce permet d'identifier le client et d'éviter les doublons.
          </div>
        </div>
      </div>

      <div v-if="isChecking" class="small text-muted mt-2">
        <span class="spinner-border spinner-border-sm me-1"></span>
        Vérification en cours...
      </div>

      <CustomerDuplicateAlerts
        class="mt-3"
        :analysis="analysis"
        :identity-fields-complete="identityFieldsComplete"
        :allow-select="allowSelectExisting"
        :can-view-customer="canViewCustomer"
        @select-existing="emit('select-existing', $event)"
      />
    </div>

    <div class="mb-4">
      <h6 class="text-uppercase text-muted small fw-semibold mb-3">Informations complémentaires</h6>
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

        <div class="col-12">
          <label class="form-label" :for="fieldId('notes')">Remarques</label>
          <textarea
            :id="fieldId('notes')"
            v-model="form.notes"
            rows="3"
            class="form-control"
            :class="{ 'is-invalid': errors.notes }"
            placeholder="Ex. : Préfère être contacté sur WhatsApp."
          ></textarea>
          <div v-if="errors.notes" class="invalid-feedback">{{ errors.notes }}</div>
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
import { computed, ref, watch } from 'vue'
import CustomerDuplicateAlerts from '@/components/customers/CustomerDuplicateAlerts.vue'
import { useCustomerDuplicateCheck } from '@/composables/useCustomerDuplicateCheck'
import type { CustomerFormData } from '@/composables/useCustomerFormValidation'
import { IDENTITY_DOCUMENT_TYPES } from '@/utils/customerIdentity'
import type { CustomerDuplicateMatch } from '@/utils/customerIdentity'

const props = withDefaults(defineProps<{
  form: CustomerFormData
  errors?: Record<string, string>
  clientErrors?: Record<string, string>
  idPrefix?: string
  excludeId?: number | null
  allowSelectExisting?: boolean
  canViewCustomer?: boolean
  enableDuplicateCheck?: boolean
}>(), {
  errors: () => ({}),
  clientErrors: () => ({}),
  idPrefix: 'customer',
  excludeId: null,
  allowSelectExisting: false,
  canViewCustomer: true,
  enableDuplicateCheck: true,
})

const emit = defineEmits<{
  (e: 'validate-field', fieldName: string, value: unknown): void
  (e: 'select-existing', match: CustomerDuplicateMatch): void
  (e: 'duplicate-analysis-changed', analysis: ReturnType<typeof useCustomerDuplicateCheck>['analysis']['value']): void
}>()

const nameInputRef = ref<HTMLInputElement | null>(null)
const identityTypes = IDENTITY_DOCUMENT_TYPES
const { analysis, isChecking, debouncedCheck, runCheck } = useCustomerDuplicateCheck(props.excludeId)

const today = computed(() => new Date().toISOString().split('T')[0])

const identityFieldsComplete = computed(() =>
  Boolean(props.form.identity_document_type && props.form.identity_document_number?.trim()),
)

const fieldId = (field: string) => `${props.idPrefix}-${field}`

const buildCheckPayload = () => ({
  name: props.form.name,
  email: props.form.email,
  phone: props.form.phone,
  identity_document_type: props.form.identity_document_type || null,
  identity_document_number: props.form.identity_document_number || null,
  exclude_id: props.excludeId,
  customer_id: props.excludeId,
})

const triggerDuplicateCheck = () => {
  if (!props.enableDuplicateCheck) {
    return
  }

  debouncedCheck(buildCheckPayload())
}

const runImmediateDuplicateCheck = () => {
  if (!props.enableDuplicateCheck) {
    return
  }

  runCheck(buildCheckPayload())
}

const handleNameInput = () => {
  emitValidate('name', props.form.name)
  triggerDuplicateCheck()
}

const handleNameBlur = () => {
  emitValidate('name', props.form.name)
  runImmediateDuplicateCheck()
}

const handlePhoneInput = () => {
  emitValidate('phone', props.form.phone)
  triggerDuplicateCheck()
}

const handlePhoneBlur = () => {
  emitValidate('phone', props.form.phone)
  runImmediateDuplicateCheck()
}

const handleEmailInput = () => {
  emitValidate('email', props.form.email)
}

const handleEmailBlur = () => {
  emitValidate('email', props.form.email)
  runImmediateDuplicateCheck()
}

const handleIdentityInput = () => {
  emitValidate('identity_document_type', props.form.identity_document_type)
  emitValidate('identity_document_number', props.form.identity_document_number)
  triggerDuplicateCheck()
}

const handleIdentityBlur = () => {
  emitValidate('identity_document_type', props.form.identity_document_type)
  emitValidate('identity_document_number', props.form.identity_document_number)
  runImmediateDuplicateCheck()
}

const handleIdentityChange = () => {
  emitValidate('identity_document_type', props.form.identity_document_type)
  if (props.form.identity_document_number?.trim()) {
    runImmediateDuplicateCheck()
  }
}

const emitValidate = (fieldName: string, value: unknown) => {
  emit('validate-field', fieldName, value)
}

watch(analysis, (value) => {
  emit('duplicate-analysis-changed', value)
}, { deep: true })

defineExpose({
  focusName: () => nameInputRef.value?.focus(),
  hasBlockingDuplicate: () => Boolean(analysis.value.identity_conflict),
  runDuplicateCheck: () => runCheck(buildCheckPayload()),
})
</script>
