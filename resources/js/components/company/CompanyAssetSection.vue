<template>
  <div class="company-asset-section">
    <div class="company-asset-section__header">
      <h6 class="company-asset-section__title">{{ title }}</h6>
      <p v-if="hint" class="company-asset-section__hint">{{ hint }}</p>
    </div>

    <div v-if="!pendingFile && currentAssetUrl" class="company-asset-section__preview-block">
      <div class="company-asset-preview" :class="`company-asset-preview--${variant}`">
        <img
          :src="currentAssetUrl"
          :alt="title"
          class="company-asset-preview__img"
        >
      </div>
      <div v-if="canManage" class="company-asset-section__actions">
        <button
          type="button"
          class="btn btn-outline-primary btn-sm"
          :disabled="uploading || deleting"
          @click="openFilePicker"
        >
          <i class="bi bi-arrow-repeat me-1"></i>
          {{ replaceLabel }}
        </button>
        <button
          type="button"
          class="btn btn-outline-danger btn-sm"
          :disabled="uploading || deleting"
          @click="confirmDelete"
        >
          <i class="bi bi-trash me-1"></i>
          Supprimer
        </button>
      </div>
    </div>

    <div v-else-if="pendingFile && previewUrl" class="company-asset-section__preview-block">
      <p class="company-asset-section__pending-label">Aperçu sélectionné</p>
      <div class="company-asset-preview" :class="`company-asset-preview--${variant}`">
        <img
          :src="previewUrl"
          :alt="`Aperçu ${title}`"
          class="company-asset-preview__img"
        >
      </div>
      <div class="company-asset-section__meta small text-muted">
        <p class="mb-1"><strong>Nom :</strong> {{ pendingFile.name }}</p>
        <p class="mb-0"><strong>Taille :</strong> {{ formatFileSize(pendingFile.size) }}</p>
      </div>
      <div v-if="canManage" class="company-asset-section__actions">
        <button
          type="button"
          class="btn btn-primary btn-sm"
          :disabled="uploading"
          @click="uploadAsset"
        >
          <span v-if="uploading" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="bi bi-check-circle me-1"></i>
          {{ uploading ? savingLabel : saveLabel }}
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="uploading"
          @click="clearPending"
        >
          Annuler
        </button>
      </div>
    </div>

    <div v-else-if="canManage" class="company-asset-section__empty">
      <div
        class="company-asset-dropzone"
        :class="{ 'company-asset-dropzone--active': isDragging, 'company-asset-dropzone--disabled': uploading }"
        @click="openFilePicker"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="onDrop"
      >
        <i class="bi bi-cloud-arrow-up company-asset-dropzone__icon"></i>
        <p class="mb-1 fw-semibold">{{ importLabel }}</p>
        <p class="text-muted small mb-0">PNG, JPG, WEBP &bull; 2 Mo max</p>
      </div>
    </div>

    <div v-else class="company-asset-section__readonly text-muted small">
      Aucun fichier configuré
    </div>

    <div v-if="localError" class="alert alert-danger mt-2 mb-0 py-2 small">
      {{ localError }}
    </div>

    <input
      ref="fileInput"
      type="file"
      class="d-none"
      accept="image/jpeg,image/jpg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
      @change="onFileSelected"
    >
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface Props {
  title: string
  assetUrl?: string | null
  canManage?: boolean
  uploadRoute: string
  deleteRoute: string
  uploadFieldName: string
  importLabel?: string
  replaceLabel?: string
  saveLabel?: string
  savingLabel?: string
  deleteConfirmTitle?: string
  deleteConfirmMessage?: string
  hint?: string
  variant?: 'logo' | 'signature' | 'stamp'
}

const props = withDefaults(defineProps<Props>(), {
  assetUrl: null,
  canManage: false,
  importLabel: 'Importer',
  replaceLabel: 'Remplacer',
  saveLabel: 'Enregistrer',
  savingLabel: 'Enregistrement...',
  deleteConfirmTitle: 'Supprimer',
  deleteConfirmMessage: 'Voulez-vous vraiment supprimer ce fichier ?',
  variant: 'logo',
})

const { success, error, confirm } = useSweetAlert()

const fileInput = ref<HTMLInputElement | null>(null)
const pendingFile = ref<File | null>(null)
const previewUrl = ref<string | null>(null)
const currentAssetUrl = ref<string | null>(props.assetUrl ?? null)
const isDragging = ref(false)
const uploading = ref(false)
const deleting = ref(false)
const localError = ref<string | null>(null)

watch(() => props.assetUrl, (value) => {
  currentAssetUrl.value = value ?? null
})

const canManage = computed(() => props.canManage)

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const MAX_SIZE = 2 * 1024 * 1024

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return `${bytes} o`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`
  return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
}

const validateFile = (file: File): string | null => {
  if (!ACCEPTED_TYPES.includes(file.type)) {
    return 'Format non pris en charge. Formats acceptés : JPG, JPEG, PNG, WEBP.'
  }
  if (file.size > MAX_SIZE) {
    return 'Le fichier est trop volumineux. Taille maximale : 2 Mo.'
  }
  return null
}

const setPendingFile = (file: File) => {
  localError.value = null
  const validationError = validateFile(file)
  if (validationError) {
    localError.value = validationError
    return
  }

  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value)
  }

  pendingFile.value = file
  previewUrl.value = URL.createObjectURL(file)
}

const openFilePicker = () => {
  if (uploading.value || deleting.value) return
  fileInput.value?.click()
}

const onFileSelected = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) {
    setPendingFile(file)
  }
  input.value = ''
}

const onDrop = (event: DragEvent) => {
  isDragging.value = false
  if (uploading.value || deleting.value) return

  const file = event.dataTransfer?.files?.[0]
  if (file) {
    setPendingFile(file)
  }
}

const clearPending = () => {
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value)
  }
  pendingFile.value = null
  previewUrl.value = null
  localError.value = null
}

const uploadAsset = () => {
  if (!pendingFile.value || uploading.value) return

  uploading.value = true
  localError.value = null

  router.post(props.uploadRoute, {
    [props.uploadFieldName]: pendingFile.value,
  }, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      clearPending()
      success(`${props.title} enregistré(e) avec succès !`)
    },
    onError: (errors) => {
      localError.value = (errors[props.uploadFieldName] as string) || `Impossible d'importer ${props.title.toLowerCase()}.`
      error(localError.value)
    },
    onFinish: () => {
      uploading.value = false
    },
  })
}

const confirmDelete = async () => {
  if (deleting.value) return

  const confirmed = await confirm(
    props.deleteConfirmMessage,
    props.deleteConfirmTitle,
  )

  if (!confirmed) return

  deleting.value = true
  localError.value = null

  router.delete(props.deleteRoute, {
    preserveScroll: true,
    onSuccess: () => {
      currentAssetUrl.value = null
      clearPending()
      success(`${props.title} supprimé(e) avec succès.`)
    },
    onError: () => {
      error(`Impossible de supprimer ${props.title.toLowerCase()}.`)
    },
    onFinish: () => {
      deleting.value = false
    },
  })
}

onUnmounted(() => {
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value)
  }
})
</script>

<style scoped>
.company-asset-section {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface-elevated);
}

.company-asset-section__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-text-primary);
}

.company-asset-section__hint {
  margin: 0.25rem 0 0;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.company-asset-section__preview-block {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  flex: 1;
}

.company-asset-section__pending-label {
  margin: 0;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-text-secondary);
}

.company-asset-section__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.company-asset-section__meta {
  text-align: center;
}

.company-asset-preview {
  border: 1px dashed var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface-hover);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem;
  min-height: 120px;
}

.company-asset-preview--logo {
  min-height: 120px;
}

.company-asset-preview--signature {
  min-height: 90px;
}

.company-asset-preview--stamp {
  min-height: 110px;
}

.company-asset-preview__img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.company-asset-preview--logo .company-asset-preview__img {
  max-height: 80px;
}

.company-asset-preview--signature .company-asset-preview__img {
  max-height: 70px;
}

.company-asset-preview--stamp .company-asset-preview__img {
  max-height: 100px;
}

.company-asset-dropzone {
  border: 2px dashed var(--color-border);
  border-radius: var(--radius-sm);
  padding: 1.25rem 0.75rem;
  text-align: center;
  cursor: pointer;
  transition: border-color var(--transition-fast), background-color var(--transition-fast);
  background: var(--color-surface-hover);
  min-height: 120px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.company-asset-dropzone:hover,
.company-asset-dropzone--active {
  border-color: var(--color-accent);
  background: var(--color-accent-soft);
}

.company-asset-dropzone--disabled {
  opacity: 0.6;
  pointer-events: none;
}

.company-asset-dropzone__icon {
  font-size: 1.5rem;
  color: var(--color-text-muted);
  margin-bottom: 0.35rem;
}

.company-asset-section__readonly {
  text-align: center;
  padding: 1.5rem 0.5rem;
}
</style>
