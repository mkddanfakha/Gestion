<template>
  <div
    v-if="show"
    ref="modalRootRef"
    class="modal fade show d-block"
    tabindex="-1"
    role="dialog"
    aria-modal="true"
  >
    <div class="modal-dialog">
      <div class="modal-content">
        <form @submit.prevent="submit">
          <div class="modal-header">
            <h5 class="modal-title">Nouvel inventaire</h5>
            <button type="button" class="btn-close" aria-label="Fermer" @click="requestClose"></button>
          </div>
          <div class="modal-body">
            <div v-if="formMessage" class="alert alert-danger" role="alert">
              {{ formMessage }}
            </div>

            <div class="mb-3">
              <label class="form-label" for="inventory-create-name">Nom</label>
              <input
                id="inventory-create-name"
                ref="nameInputRef"
                v-model="form.name"
                type="text"
                class="form-control"
                :class="{ 'is-invalid': fieldErrors.name }"
                required
                maxlength="255"
                placeholder="Inventaire mensuel magasin principal"
              >
              <div v-if="fieldErrors.name" class="invalid-feedback d-block">
                {{ fieldErrors.name }}
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="inventory-create-scope">Périmètre</label>
              <select id="inventory-create-scope" v-model="form.scope_type" class="form-select">
                <option value="complete">Complet</option>
                <option value="stock_positive">Stock positif uniquement</option>
                <option value="category">Catégorie</option>
              </select>
            </div>
            <div v-if="form.scope_type === 'category'" class="mb-3">
              <label class="form-label" for="inventory-create-category">Catégorie</label>
              <select
                id="inventory-create-category"
                v-model="form.category_id"
                class="form-select"
                :class="{ 'is-invalid': fieldErrors.category_id }"
                required
              >
                <option value="">Sélectionner…</option>
                <option v-for="category in categories" :key="category.id" :value="String(category.id)">
                  {{ category.name }}
                </option>
              </select>
              <div v-if="fieldErrors.category_id" class="invalid-feedback d-block">
                {{ fieldErrors.category_id }}
              </div>
            </div>
            <div class="mb-0">
              <label class="form-label" for="inventory-create-description">Description</label>
              <textarea
                id="inventory-create-description"
                v-model="form.description"
                class="form-control"
                rows="2"
                placeholder="Optionnel"
              ></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" :disabled="submitting" @click="requestClose">
              Annuler
            </button>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
              <span v-if="submitting" class="spinner-border spinner-border-sm me-2"></span>
              Créer
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div v-if="show" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import {
  buildInventoryCreatePayload,
  resolveInventoryCreateErrorMessage,
} from '@/utils/inventoryCreateModal'
import { releaseInventoryModalFocus } from '@/utils/inventoryModalFocus'
import { reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'

const props = defineProps<{
  show: boolean
  categories: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{ close: [] }>()

const submitting = ref(false)
const formMessage = ref('')
const fieldErrors = reactive<Record<string, string>>({})
const form = reactive({
  name: '',
  description: '',
  scope_type: 'complete',
  category_id: '',
})
const modalRootRef = ref<HTMLElement | null>(null)
const nameInputRef = ref<HTMLInputElement | null>(null)

watch(
  () => props.show,
  (visible) => {
    if (!visible) {
      releaseInventoryModalFocus(modalRootRef.value)
      return
    }

    resetForm()
    requestAnimationFrame(() => {
      nameInputRef.value?.focus()
    })
  },
  { flush: 'sync' },
)

watch(() => form.scope_type, (scopeType) => {
  if (scopeType !== 'category') {
    form.category_id = ''
    delete fieldErrors.category_id
  }
})

function resetForm(): void {
  form.name = ''
  form.description = ''
  form.scope_type = 'complete'
  form.category_id = ''
  formMessage.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
}

function requestClose(): void {
  if (submitting.value) {
    return
  }

  releaseInventoryModalFocus(modalRootRef.value)
  emit('close')
}

function submit(): void {
  const built = buildInventoryCreatePayload(form)

  formMessage.value = built.formMessage
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  Object.assign(fieldErrors, built.fieldErrors)

  if (!built.payload) {
    requestAnimationFrame(() => {
      nameInputRef.value?.focus()
    })
    return
  }

  submitting.value = true

  router.post(route('inventory.store'), built.payload, {
    onFinish: () => {
      submitting.value = false
    },
    onSuccess: () => {
      releaseInventoryModalFocus(modalRootRef.value)
      emit('close')
      resetForm()
    },
    onError: (errors) => {
      const resolved = resolveInventoryCreateErrorMessage(errors)
      formMessage.value = resolved.formMessage
      Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
      Object.assign(fieldErrors, resolved.fieldErrors)
      requestAnimationFrame(() => {
        nameInputRef.value?.focus()
      })
    },
  })
}
</script>
