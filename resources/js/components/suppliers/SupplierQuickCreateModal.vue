<template>
  <Teleport to="body">
    <div v-if="open" class="supplier-quick-create-modal-root">
      <div class="modal-backdrop fade show"></div>
      <div
        ref="dialogRef"
        class="modal fade show d-block supplier-quick-create-modal"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        @keydown.escape.prevent="handleCancel"
      >
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-centered">
          <div class="modal-content">
            <form class="supplier-quick-create-modal__form" @submit.prevent="submit">
              <div class="modal-header">
                <h5 :id="titleId" class="modal-title">Nouveau fournisseur</h5>
                <button
                  type="button"
                  class="btn-close"
                  aria-label="Fermer"
                  :disabled="isSubmitting"
                  @click="handleCancel"
                ></button>
              </div>

              <div class="modal-body supplier-quick-create-modal__body">
                <div v-if="serverMessage" class="alert alert-danger" role="alert">
                  {{ serverMessage }}
                </div>

                <div class="mb-3">
                  <label :for="`${idPrefix}-name`" class="form-label">
                    Nom / raison sociale <span class="text-danger">*</span>
                  </label>
                  <input
                    :id="`${idPrefix}-name`"
                    ref="nameInputRef"
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': clientErrors.name || serverErrors.name }"
                    autocomplete="organization"
                    @blur="validateField('name')"
                  />
                  <div v-if="clientErrors.name || serverErrors.name" class="invalid-feedback d-block">
                    {{ clientErrors.name || serverErrors.name }}
                  </div>
                </div>

                <div class="mb-3">
                  <label :for="`${idPrefix}-contact-person`" class="form-label">Personne de contact</label>
                  <input
                    :id="`${idPrefix}-contact-person`"
                    v-model="form.contact_person"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': clientErrors.contact_person || serverErrors.contact_person }"
                    autocomplete="name"
                  />
                  <div
                    v-if="clientErrors.contact_person || serverErrors.contact_person"
                    class="invalid-feedback d-block"
                  >
                    {{ clientErrors.contact_person || serverErrors.contact_person }}
                  </div>
                </div>

                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label :for="`${idPrefix}-phone`" class="form-label">Téléphone</label>
                    <input
                      :id="`${idPrefix}-phone`"
                      v-model="form.phone"
                      type="tel"
                      class="form-control"
                      :class="{ 'is-invalid': clientErrors.phone || serverErrors.phone }"
                      autocomplete="tel"
                    />
                    <div v-if="clientErrors.phone || serverErrors.phone" class="invalid-feedback d-block">
                      {{ clientErrors.phone || serverErrors.phone }}
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label :for="`${idPrefix}-email`" class="form-label">Email</label>
                    <input
                      :id="`${idPrefix}-email`"
                      v-model="form.email"
                      type="email"
                      class="form-control"
                      :class="{ 'is-invalid': clientErrors.email || serverErrors.email }"
                      autocomplete="email"
                    />
                    <div v-if="clientErrors.email || serverErrors.email" class="invalid-feedback d-block">
                      {{ clientErrors.email || serverErrors.email }}
                    </div>
                  </div>
                </div>

                <div class="mb-0">
                  <label :for="`${idPrefix}-address`" class="form-label">Adresse</label>
                  <textarea
                    :id="`${idPrefix}-address`"
                    v-model="form.address"
                    rows="2"
                    class="form-control"
                    :class="{ 'is-invalid': clientErrors.address || serverErrors.address }"
                    autocomplete="street-address"
                  ></textarea>
                  <div v-if="clientErrors.address || serverErrors.address" class="invalid-feedback d-block">
                    {{ clientErrors.address || serverErrors.address }}
                  </div>
                </div>
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
                <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
                  <span
                    v-if="isSubmitting"
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                  ></span>
                  {{ isSubmitting ? 'Création...' : 'Créer' }}
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
import { reactive, ref, watch, nextTick, onUnmounted } from 'vue'
import { route } from '@/lib/routes'
import { getCsrfToken } from '@/lib/csrf'
import type { SupplierOption } from '@/types/supplier'

export type CreatedSupplier = SupplierOption

interface QuickCreateForm {
  name: string
  contact_person: string
  email: string
  phone: string
  address: string
  status: 'active'
}

const props = defineProps<{
  open: boolean
  initialName?: string
}>()

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void
  (e: 'created', supplier: CreatedSupplier): void
  (e: 'cancel'): void
}>()

const titleId = 'supplierQuickCreateModalTitle'
const idPrefix = 'supplier-quick-create'
const dialogRef = ref<HTMLElement | null>(null)
const nameInputRef = ref<HTMLInputElement | null>(null)
const form = reactive<QuickCreateForm>({
  name: '',
  contact_person: '',
  email: '',
  phone: '',
  address: '',
  status: 'active',
})
const clientErrors = ref<Record<string, string>>({})
const serverErrors = ref<Record<string, string>>({})
const serverMessage = ref('')
const isSubmitting = ref(false)

const resetForm = (initialName = '') => {
  form.name = initialName
  form.contact_person = ''
  form.email = ''
  form.phone = ''
  form.address = ''
  form.status = 'active'
  clientErrors.value = {}
  serverErrors.value = {}
  serverMessage.value = ''
}

const validateField = (fieldName: keyof QuickCreateForm) => {
  if (fieldName === 'name' && !form.name.trim()) {
    clientErrors.value.name = 'Le nom du fournisseur est requis.'
    return
  }

  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
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

  validateField('name')
  if (clientErrors.value.name) {
    return
  }

  isSubmitting.value = true

  try {
    const response = await fetch(route('suppliers.store'), {
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
      serverMessage.value =
        payload.message && payload.message !== 'The given data was invalid.'
          ? payload.message
          : 'Impossible de créer le fournisseur. Veuillez vérifier les informations saisies.'
      return
    }

    if (!response.ok) {
      serverMessage.value = 'Impossible de créer le fournisseur. Veuillez vérifier les informations saisies.'
      return
    }

    const supplier = (await response.json()) as CreatedSupplier
    emit('created', supplier)
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
      nameInputRef.value?.focus()
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
    document.documentElement.classList.toggle('supplier-quick-create-modal-open', isOpen)

    if (!isOpen) {
      document.body.style.removeProperty('overflow')
      document.body.style.removeProperty('padding-right')
    }
  },
)

onUnmounted(() => {
  document.body.classList.remove('modal-open')
  document.documentElement.classList.remove('supplier-quick-create-modal-open')
  document.body.style.removeProperty('overflow')
  document.body.style.removeProperty('padding-right')
})
</script>
