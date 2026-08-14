<template>
  <Teleport to="body">
    <div v-if="open" class="customer-quick-create-modal-root">
      <div class="modal-backdrop fade show"></div>
      <div
        ref="dialogRef"
        class="modal fade show d-block customer-quick-create-modal"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        @keydown.escape.prevent="handleCancel"
      >
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-centered">
          <div class="modal-content">
            <form class="customer-quick-create-modal__form" @submit.prevent="submit">
              <div class="modal-header">
                <h5 :id="titleId" class="modal-title">Nouveau client</h5>
                <button
                  type="button"
                  class="btn-close"
                  aria-label="Fermer"
                  :disabled="isSubmitting"
                  @click="handleCancel"
                ></button>
              </div>

              <div class="modal-body customer-quick-create-modal__body">
                <div v-if="serverMessage" class="alert alert-danger" role="alert">
                  {{ serverMessage }}
                </div>

                <CustomerFormFields
                  ref="fieldsRef"
                  :form="form"
                  :errors="serverErrors"
                  :client-errors="clientErrors"
                  id-prefix="customer-quick-create"
                  @validate-field="handleValidateField"
                />
              </div>

              <div class="modal-footer">
                <button
                  type="button"
                  class="btn btn-outline-secondary"
                  :disabled="isSubmitting"
                  @click="handleCancel"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  class="btn btn-primary"
                  :disabled="isSubmitting"
                >
                  <span v-if="isSubmitting" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                  {{ isSubmitting ? 'Enregistrement...' : 'Enregistrer' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, reactive, watch, nextTick, onUnmounted } from 'vue'
import CustomerFormFields from '@/components/customers/CustomerFormFields.vue'
import {
  createEmptyCustomerForm,
  validateCustomerField,
  validateCustomerForm,
  type CustomerFormData,
} from '@/composables/useCustomerFormValidation'
import { route } from '@/lib/routes'
import { getCsrfToken } from '@/lib/csrf'

export interface CreatedCustomer {
  id: number
  name: string
  email?: string | null
  phone?: string | null
}

const props = defineProps<{
  open: boolean
  initialName?: string
}>()

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void
  (e: 'created', customer: CreatedCustomer): void
  (e: 'cancel'): void
}>()

const titleId = 'customerQuickCreateModalTitle'
const dialogRef = ref<HTMLElement | null>(null)
const fieldsRef = ref<InstanceType<typeof CustomerFormFields> | null>(null)
const form = reactive<CustomerFormData>(createEmptyCustomerForm())
const clientErrors = ref<Record<string, string>>({})
const serverErrors = ref<Record<string, string>>({})
const serverMessage = ref('')
const isSubmitting = ref(false)

const resetForm = (initialName = '') => {
  Object.assign(form, createEmptyCustomerForm())
  form.name = initialName
  clientErrors.value = {}
  serverErrors.value = {}
  serverMessage.value = ''
}

const handleValidateField = (fieldName: string, value: unknown) => {
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }

  const errorMessage = validateCustomerField(fieldName, value)
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const flattenValidationErrors = (errors: Record<string, string[] | string>): Record<string, string> => {
  const flattened: Record<string, string> = {}

  for (const [key, value] of Object.entries(errors)) {
    flattened[key] = Array.isArray(value) ? value[0] : value
  }

  return flattened
}

const handleCancel = () => {
  if (isSubmitting.value) {
    return
  }

  emit('update:open', false)
  emit('cancel')
}

const submit = async () => {
  if (isSubmitting.value) {
    return
  }

  clientErrors.value = {}
  serverErrors.value = {}
  serverMessage.value = ''

  const validationErrors = validateCustomerForm(form)
  if (validationErrors) {
    clientErrors.value = validationErrors
    return
  }

  isSubmitting.value = true

  try {
    const response = await fetch(route('customers.store'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(form),
    })

    if (response.status === 422) {
      const payload = await response.json()
      serverErrors.value = flattenValidationErrors(payload.errors ?? {})
      serverMessage.value = payload.message || 'Veuillez corriger les erreurs du formulaire.'
      return
    }

    if (!response.ok) {
      serverMessage.value = 'Une erreur est survenue lors de la création du client. Veuillez réessayer.'
      return
    }

    const customer = await response.json() as CreatedCustomer
    emit('created', customer)
    emit('update:open', false)
  } catch {
    serverMessage.value = 'Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.'
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      return
    }

    resetForm(props.initialName?.trim() ?? '')

    nextTick(() => {
      fieldsRef.value?.focusName()
      dialogRef.value?.focus()
    })
  },
)

watch(
  () => props.initialName,
  (value) => {
    if (props.open && value !== undefined) {
      form.name = value.trim()
    }
  },
)

watch(
  () => props.open,
  (isOpen) => {
    document.body.classList.toggle('modal-open', isOpen)
    document.documentElement.classList.toggle('customer-quick-create-modal-open', isOpen)

    if (!isOpen) {
      document.body.style.removeProperty('overflow')
      document.body.style.removeProperty('padding-right')
    }
  },
)

onUnmounted(() => {
  document.body.classList.remove('modal-open')
  document.documentElement.classList.remove('customer-quick-create-modal-open')
  document.body.style.removeProperty('overflow')
  document.body.style.removeProperty('padding-right')
})
</script>
