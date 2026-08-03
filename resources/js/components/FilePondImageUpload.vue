<template>
  <div class="filepond-wrapper">
    <input
      ref="fileInput"
      type="file"
      class="filepond"
      :name="name"
      :accept="accept"
      :multiple="multiple"
    />
    <button
      v-if="showReplaceButton && hasFiles"
      type="button"
      class="btn btn-outline-primary btn-sm mt-2"
      @click="openFileBrowse"
    >
      <i class="bi bi-arrow-repeat me-1"></i>
      {{ replaceButtonLabel }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import * as FilePond from 'filepond'
import FilePondPluginImagePreview from 'filepond-plugin-image-preview'
import FilePondPluginImageResize from 'filepond-plugin-image-resize'
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type'
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size'
import 'filepond/dist/filepond.min.css'
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css'
import { route } from '@/lib/routes'
import { useCsrfToken } from '@/lib/csrf'

FilePond.registerPlugin(
  FilePondPluginImagePreview,
  FilePondPluginImageResize,
  FilePondPluginFileValidateType,
  FilePondPluginFileValidateSize
)

interface FileEntry {
  source: string
  options?: {
    type?: 'local' | 'limbo'
    metadata?: Record<string, unknown>
  }
}

interface Props {
  name?: string
  server?: string | object
  acceptedFileTypes?: string[]
  maxFileSize?: string
  maxFiles?: number
  allowMultiple?: boolean
  allowReorder?: boolean
  imageResizeTargetWidth?: number
  imageResizeTargetHeight?: number
  imageResizeMode?: 'none' | 'cover' | 'contain' | 'force' | 'scale'
  imageResizeUpscale?: boolean
  files?: FileEntry[]
  labelIdle?: string
  credits?: boolean
  serverUpload?: boolean
  showReplaceButton?: boolean
  replaceButtonLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  name: 'images',
  acceptedFileTypes: () => ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'],
  maxFileSize: '5MB',
  maxFiles: 1,
  allowMultiple: false,
  allowReorder: false,
  imageResizeTargetWidth: 1200,
  imageResizeTargetHeight: 1200,
  imageResizeMode: 'contain',
  imageResizeUpscale: false,
  files: () => [],
  labelIdle: 'Glissez-déposez vos images ou <span class="filepond--label-action">Parcourir</span>',
  credits: false,
  serverUpload: false,
  showReplaceButton: true,
  replaceButtonLabel: 'Changer l\'image',
})

const emit = defineEmits<{
  (e: 'update:files', files: FilePond.FilePondFile[]): void
  (e: 'processfile', file: FilePond.FilePondFile): void
  (e: 'removefile', file: FilePond.FilePondFile): void
  (e: 'addfile', file: FilePond.FilePondFile): void
}>()

const fileInput = ref<HTMLInputElement | null>(null)
const hasFiles = ref(false)
let pond: FilePond.FilePond | null = null
let initialFilesLoaded = false

const { getCsrfToken } = useCsrfToken()

const accept = computed(() => props.acceptedFileTypes.join(','))
const multiple = computed(() => props.allowMultiple)

const syncHasFiles = () => {
  hasFiles.value = (pond?.getFiles().length ?? 0) > 0
}

const openFileBrowse = () => {
  pond?.browse()
}

const loadInitialFile = async (file: FileEntry) => {
  if (!pond || !file.source) {
    return
  }

  if (!file.source.startsWith('http') && !file.source.startsWith('/')) {
    return
  }

  try {
    let fileUrl = file.source
    if (file.source.startsWith('/') && !file.source.startsWith('http')) {
      fileUrl = window.location.origin + file.source
    }

    const response = await fetch(fileUrl)
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`)
    }

    const blob = await response.blob()
    const urlParts = fileUrl.split('/')
    const originalFileName = urlParts[urlParts.length - 1] || 'image.jpg'
    const contentType = response.headers.get('content-type') || 'image/jpeg'

    let fileName = originalFileName
    const mediaId = file.options?.metadata?.mediaId
    if (mediaId) {
      fileName = `${mediaId}_${originalFileName}`
    }

    const fileObj = new File([blob], fileName, { type: contentType })
    const fileOptions: Record<string, unknown> = {
      type: 'local',
    }

    if (file.options?.metadata) {
      fileOptions.metadata = file.options.metadata
    }

    const addedFile = await pond.addFile(fileObj, fileOptions)

    if (addedFile && file.options?.metadata) {
      if (addedFile.setMetadata) {
        addedFile.setMetadata(file.options.metadata)
      }
      if (addedFile.metadata) {
        Object.assign(addedFile.metadata, file.options.metadata)
      }
      ;(addedFile as any).__mediaId = file.options.metadata.mediaId
    }
  } catch (err) {
    console.error('Erreur lors du chargement de l\'image:', err, file.source)
  }
}

const loadInitialFiles = async () => {
  if (initialFilesLoaded || !pond || !props.files.length) {
    return
  }

  initialFilesLoaded = true

  for (const file of props.files) {
    await loadInitialFile(file)
  }

  syncHasFiles()
}

onMounted(() => {
  if (!fileInput.value) {
    return
  }

  const uploadUrl = props.server || route('products.upload-image')
  const useServerUpload = props.serverUpload || props.server !== undefined

  let serverConfig: object | null = null

  if (useServerUpload) {
    const baseProcess = typeof uploadUrl === 'string' ? {
      url: uploadUrl,
      method: 'POST' as const,
      withCredentials: true,
      headers: {
        'X-CSRF-TOKEN': getCsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      },
      ondata: (formData: FormData) => {
        formData.append('_token', getCsrfToken())
        return formData
      },
      onload: (response: string) => {
        try {
          const data = JSON.parse(response)
          return data.path
        } catch {
          return response
        }
      },
      onerror: (response: string) => response,
    } : (uploadUrl as object)

    serverConfig = typeof uploadUrl === 'string' ? {
      process: baseProcess,
      revert: null,
      restore: null,
      load: (_source: string, _load: unknown, error: (msg: string) => void) => {
        error('Chargement non supporté via cette méthode')
      },
      fetch: null,
    } : uploadUrl
  }

  pond = FilePond.create(fileInput.value, {
    ...(serverConfig ? { server: serverConfig } : {}),
    instantUpload: useServerUpload,
    allowMultiple: props.allowMultiple,
    maxFiles: props.maxFiles,
    acceptedFileTypes: props.acceptedFileTypes,
    maxFileSize: props.maxFileSize,
    imageResizeTargetWidth: props.imageResizeTargetWidth,
    imageResizeTargetHeight: props.imageResizeTargetHeight,
    imageResizeMode: props.imageResizeMode,
    imageResizeUpscale: props.imageResizeUpscale,
    labelIdle: props.labelIdle,
    credits: props.credits,
    allowReorder: props.allowReorder,
    allowReplace: true,
    allowRevert: false,
    storeAsFile: true,
  })

  pond.on('addfile', (error, file) => {
    if (error) {
      console.error('Erreur lors de l\'ajout du fichier:', error)
      return
    }

    syncHasFiles()
    emit('addfile', file)
    emit('update:files', pond?.getFiles() || [])
  })

  pond.on('processfile', (error, file) => {
    if (error) {
      console.error('Erreur lors du traitement du fichier:', error)
      return
    }
    emit('processfile', file)
    emit('update:files', pond?.getFiles() || [])
  })

  pond.on('removefile', (error, file) => {
    if (error) {
      console.error('Erreur lors de la suppression du fichier:', error)
      return
    }

    syncHasFiles()
    emit('removefile', file)
    emit('update:files', pond?.getFiles() || [])
  })

  pond.on('reorderfiles', () => {
    emit('update:files', pond?.getFiles() || [])
  })

  loadInitialFiles()
})

onUnmounted(() => {
  if (pond) {
    pond.destroy()
    pond = null
  }
})

defineExpose({
  getFiles: () => pond?.getFiles() || [],
  browse: () => pond?.browse(),
  addFile: (source: string, options?: { type: 'local' | 'limbo' }) => {
    pond?.addFile(source, options)
  },
  removeFile: (file: FilePond.FilePondFile) => {
    pond?.removeFile(file)
  },
  removeFiles: () => {
    pond?.removeFiles()
  },
})
</script>

<style scoped>
.filepond-wrapper {
  width: 100%;
}

:deep(.filepond--root) {
  margin-bottom: 0;
}

:deep(.filepond--drop-label) {
  cursor: pointer;
}
</style>
