<template>
  <div class="attachment-uploader">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <div>
        <label v-if="label" class="form-label fw-medium mb-0">{{ label }}</label>
        <p v-if="hint" class="text-muted small mb-0">{{ hint }}</p>
      </div>
      <button
        v-if="!disabled && canAddMore"
        type="button"
        class="btn btn-outline-primary btn-sm"
        @click="openFilePicker"
      >
        <i class="bi bi-paperclip me-1"></i>
        Ajouter des fichiers
      </button>
    </div>

    <div
      v-if="!disabled && canAddMore"
      class="attachment-dropzone"
      :class="{ 'attachment-dropzone--active': isDragging }"
      role="button"
      tabindex="0"
      @click="openFilePicker"
      @keydown.enter.prevent="openFilePicker"
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      @drop.prevent="onDrop"
    >
      <i class="bi bi-cloud-arrow-up attachment-dropzone__icon"></i>
      <p class="mb-1 fw-semibold">Glissez vos fichiers ici</p>
      <p class="text-muted small mb-0">
        PDF, JPG, PNG, WEBP &bull; {{ maxSizeLabel }} max &bull; {{ remainingSlots }} emplacement(s)
      </p>
    </div>

    <div v-if="pendingFiles.length" class="mt-3">
      <p class="small text-muted mb-2">
        {{ pendingFiles.length }} fichier(s) prêt(s) à être ajouté(s).
      </p>
      <ul class="list-group list-group-flush">
        <li
          v-for="(file, index) in pendingFiles"
          :key="`${file.name}-${file.size}-${index}`"
          class="list-group-item px-0 d-flex align-items-center justify-content-between gap-2"
        >
          <div class="d-flex align-items-center gap-2 min-w-0">
            <i :class="['bi', getPendingIcon(file), 'text-primary']"></i>
            <div class="min-w-0">
              <div class="text-truncate">{{ file.name }}</div>
              <small class="text-muted">{{ formatFileSize(file.size) }}</small>
            </div>
          </div>
          <button
            v-if="!disabled"
            type="button"
            class="btn btn-sm btn-outline-danger"
            title="Retirer"
            @click="removePending(index)"
          >
            <i class="bi bi-x-lg"></i>
          </button>
        </li>
      </ul>
    </div>

    <AttachmentList
      v-if="showExisting && existingAttachments.length"
      class="mt-3"
      :attachments="existingAttachments"
      :allow-delete="allowDelete && !disabled"
      :allow-preview="allowPreview"
      @preview="(attachment) => emit('preview', attachment)"
      @deleted="(attachment) => emit('removed', attachment)"
    />

    <div v-if="localError" class="alert alert-danger mt-2 mb-0 py-2 small">
      {{ localError }}
    </div>

    <input
      ref="fileInput"
      type="file"
      class="d-none"
      :accept="accept"
      :multiple="multiple"
      @change="onFileSelected"
    >
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AttachmentList from '@/components/attachments/AttachmentList.vue'
import type { AttachmentRecord } from '@/types/attachment'
import { DEFAULT_ATTACHMENT_CONFIG } from '@/types/attachment'

interface Props {
  modelValue?: File[]
  attachments?: AttachmentRecord[]
  label?: string
  hint?: string
  multiple?: boolean
  accept?: string
  maxFiles?: number
  maxSizeKb?: number
  disabled?: boolean
  showExisting?: boolean
  allowDelete?: boolean
  allowPreview?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => [],
  attachments: () => [],
  multiple: true,
  accept: DEFAULT_ATTACHMENT_CONFIG.accept,
  maxFiles: DEFAULT_ATTACHMENT_CONFIG.maxFiles,
  maxSizeKb: DEFAULT_ATTACHMENT_CONFIG.maxSizeKb,
  disabled: false,
  showExisting: true,
  allowDelete: true,
  allowPreview: true,
})

const emit = defineEmits<{
  'update:modelValue': [files: File[]]
  selected: [files: File[]]
  removed: [attachment: AttachmentRecord]
  preview: [attachment: AttachmentRecord]
  error: [message: string]
}>()

const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const localError = ref('')

const pendingFiles = computed({
  get: () => props.modelValue,
  set: (files: File[]) => emit('update:modelValue', files),
})

const existingAttachments = computed(() => props.attachments ?? [])

const totalCount = computed(() => existingAttachments.value.length + pendingFiles.value.length)

const remainingSlots = computed(() => Math.max(props.maxFiles - totalCount.value, 0))

const canAddMore = computed(() => remainingSlots.value > 0)

const maxSizeLabel = computed(() => {
  if (props.maxSizeKb >= 1024) {
    return `${Math.round(props.maxSizeKb / 1024)} Mo`
  }
  return `${props.maxSizeKb} Ko`
})

watch(
  () => props.modelValue,
  () => {
    localError.value = ''
  }
)

const openFilePicker = () => {
  if (props.disabled || !canAddMore.value) return
  fileInput.value?.click()
}

const onDrop = (event: DragEvent) => {
  isDragging.value = false
  if (props.disabled || !canAddMore.value) return
  handleFiles(event.dataTransfer?.files ?? null)
}

const onFileSelected = (event: Event) => {
  const target = event.target as HTMLInputElement
  handleFiles(target.files)
  target.value = ''
}

const handleFiles = (fileList: FileList | null) => {
  if (!fileList?.length) return

  const incoming = Array.from(fileList)
  const valid: File[] = []

  for (const file of incoming) {
    const error = validateFile(file)
    if (error) {
      localError.value = error
      emit('error', error)
      continue
    }
    valid.push(file)
  }

  if (!valid.length) return

  const limit = props.multiple ? remainingSlots.value : 1
  const next = props.multiple
    ? [...pendingFiles.value, ...valid].slice(0, limit)
    : valid.slice(0, 1)

  pendingFiles.value = next
  emit('selected', next)
}

const validateFile = (file: File): string | null => {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  const allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp']

  if (!allowed.includes(extension)) {
    return 'Ce type de fichier n\'est pas autorisé.'
  }

  const maxBytes = props.maxSizeKb * 1024
  if (file.size > maxBytes) {
    return `Le fichier dépasse la taille maximale de ${maxSizeLabel.value}.`
  }

  return null
}

const removePending = (index: number) => {
  const next = [...pendingFiles.value]
  next.splice(index, 1)
  pendingFiles.value = next
  emit('selected', next)
}

const formatFileSize = (bytes: number): string => {
  if (bytes >= 1048576) {
    return `${(bytes / 1048576).toFixed(1)} Mo`
  }
  if (bytes >= 1024) {
    return `${Math.round(bytes / 1024)} Ko`
  }
  return `${bytes} o`
}

const getPendingIcon = (file: File): string => {
  if (file.type === 'application/pdf') return 'bi-file-earmark-pdf'
  if (file.type.startsWith('image/')) return 'bi-file-earmark-image'
  return 'bi-file-earmark'
}
</script>

<style scoped>
.attachment-dropzone {
  border: 2px dashed var(--bs-border-color);
  border-radius: 0.75rem;
  padding: 1.25rem;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.attachment-dropzone--active,
.attachment-dropzone:hover {
  border-color: var(--bs-primary);
  background-color: rgba(var(--bs-primary-rgb), 0.04);
}

.attachment-dropzone__icon {
  font-size: 1.75rem;
  color: var(--bs-primary);
  display: block;
  margin-bottom: 0.5rem;
}
</style>
