<template>
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0">Logo de l'entreprise</h5>
    </div>
    <div class="card-body">
      <!-- Logo enregistré -->
      <div v-if="!pendingFile && currentLogoUrl" class="text-center mb-4">
        <div class="company-logo-preview mx-auto mb-3">
          <img
            :src="currentLogoUrl"
            alt="Logo de l'entreprise"
            class="company-logo-preview__img"
          >
        </div>
        <p v-if="canManage" class="text-muted small mb-3">
          PNG, JPG, WEBP &bull; 2 Mo maximum
        </p>
        <div v-if="canManage" class="d-flex flex-wrap justify-content-center gap-2">
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="uploading || deleting"
            @click="openFilePicker"
          >
            <i class="bi bi-arrow-repeat me-1"></i>
            Remplacer
          </button>
          <button
            type="button"
            class="btn btn-outline-danger"
            :disabled="uploading || deleting"
            @click="confirmDelete"
          >
            <i class="bi bi-trash me-1"></i>
            Supprimer
          </button>
        </div>
      </div>

      <!-- Aperçu avant enregistrement -->
      <div v-else-if="pendingFile && previewUrl" class="mb-4">
        <p class="fw-semibold mb-2">Logo sélectionné :</p>
        <div class="company-logo-preview mx-auto mb-3">
          <img
            :src="previewUrl"
            alt="Aperçu du logo"
            class="company-logo-preview__img"
          >
        </div>
        <div class="text-center small text-muted mb-3">
          <p class="mb-1"><strong>Nom :</strong> {{ pendingFile.name }}</p>
          <p class="mb-0"><strong>Taille :</strong> {{ formatFileSize(pendingFile.size) }}</p>
        </div>
        <div v-if="canManage" class="d-flex flex-wrap justify-content-center gap-2">
          <button
            type="button"
            class="btn btn-primary"
            :disabled="uploading"
            @click="uploadLogo"
          >
            <span v-if="uploading" class="spinner-border spinner-border-sm me-1"></span>
            <i v-else class="bi bi-check-circle me-1"></i>
            {{ uploading ? 'Importation du logo...' : 'Enregistrer le logo' }}
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="uploading"
            @click="clearPending"
          >
            Modifier
          </button>
          <button
            type="button"
            class="btn btn-outline-danger"
            :disabled="uploading"
            @click="clearPending"
          >
            Supprimer
          </button>
        </div>
      </div>

      <!-- Zone d'import (aucun logo) -->
      <div v-else-if="canManage">
        <div
          class="logo-dropzone"
          :class="{ 'logo-dropzone--active': isDragging, 'logo-dropzone--disabled': uploading }"
          @click="openFilePicker"
          @dragover.prevent="isDragging = true"
          @dragleave.prevent="isDragging = false"
          @drop.prevent="onDrop"
        >
          <div class="logo-dropzone__content">
            <i class="bi bi-image logo-dropzone__icon"></i>
            <p class="mb-2 fw-semibold">Importer un logo</p>
            <p class="text-muted small mb-0">
              Glissez-déposez une image ou cliquez pour parcourir
            </p>
          </div>
        </div>
        <p class="text-muted small text-center mt-3 mb-0">
          Formats acceptés : JPG, JPEG, PNG, WEBP &bull; Taille maximale : 2 Mo
        </p>
      </div>

      <!-- Lecture seule sans logo -->
      <div v-else class="text-center text-muted py-3">
        <i class="bi bi-image fs-3 d-block mb-2"></i>
        Aucun logo configuré
      </div>

      <div v-if="localError" class="alert alert-danger mt-3 mb-0 py-2 small">
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
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface Props {
  logoUrl?: string | null
  canManage?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  logoUrl: null,
  canManage: false,
})

const { success, error, confirm } = useSweetAlert()

const fileInput = ref<HTMLInputElement | null>(null)
const pendingFile = ref<File | null>(null)
const previewUrl = ref<string | null>(null)
const currentLogoUrl = ref<string | null>(props.logoUrl ?? null)

watch(() => props.logoUrl, (value) => {
  currentLogoUrl.value = value ?? null
})
const isDragging = ref(false)
const uploading = ref(false)
const deleting = ref(false)
const localError = ref<string | null>(null)

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

const uploadLogo = () => {
  if (!pendingFile.value || uploading.value) return

  uploading.value = true
  localError.value = null

  router.post(route('company.logo.upload'), {
    logo: pendingFile.value,
  }, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      clearPending()
      success('Logo enregistré avec succès !')
    },
    onError: (errors) => {
      localError.value = (errors.logo as string) || 'Impossible d\'importer le logo.'
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
    'Voulez-vous vraiment supprimer le logo de l\'entreprise ?',
    'Supprimer le logo'
  )

  if (!confirmed) return

  deleting.value = true
  localError.value = null

  router.delete(route('company.logo.delete'), {
    preserveScroll: true,
    onSuccess: () => {
      currentLogoUrl.value = null
      clearPending()
      success('Logo supprimé avec succès.')
    },
    onError: () => {
      error('Impossible de supprimer le logo.')
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
.company-logo-preview {
  width: 160px;
  height: 120px;
  border: 2px dashed var(--bs-border-color);
  border-radius: 0.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem;
  background: var(--bs-tertiary-bg, #f8f9fa);
}

.company-logo-preview__img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.logo-dropzone {
  border: 2px dashed var(--bs-border-color);
  border-radius: 0.5rem;
  padding: 2rem 1rem;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s, background-color 0.2s;
  background: var(--bs-tertiary-bg, #f8f9fa);
  min-height: 140px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.logo-dropzone:hover,
.logo-dropzone--active {
  border-color: var(--bs-primary);
  background: rgba(var(--bs-primary-rgb), 0.05);
}

.logo-dropzone--disabled {
  opacity: 0.6;
  pointer-events: none;
}

.logo-dropzone__icon {
  font-size: 2rem;
  color: var(--bs-secondary);
  display: block;
  margin-bottom: 0.5rem;
}

@media (max-width: 576px) {
  .company-logo-preview {
    width: 140px;
    height: 100px;
  }

  .logo-dropzone {
    padding: 1.5rem 1rem;
  }
}
</style>
