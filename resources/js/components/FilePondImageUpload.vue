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
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, computed } from 'vue'
import * as FilePond from 'filepond'
import FilePondPluginImagePreview from 'filepond-plugin-image-preview'
import FilePondPluginImageResize from 'filepond-plugin-image-resize'
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type'
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size'
import 'filepond/dist/filepond.min.css'
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css'
import { route } from '@/lib/routes'

// Enregistrer les plugins
FilePond.registerPlugin(
  FilePondPluginImagePreview,
  FilePondPluginImageResize,
  FilePondPluginFileValidateType,
  FilePondPluginFileValidateSize
)

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
  files?: Array<{ source: string; options?: { type: 'local' | 'limbo' | 'local' } }>
  labelIdle?: string
  credits?: boolean
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
})

const emit = defineEmits<{
  (e: 'update:files', files: FilePond.FilePondFile[]): void
  (e: 'processfile', file: FilePond.FilePondFile): void
  (e: 'removefile', file: FilePond.FilePondFile): void
  (e: 'addfile', file: FilePond.FilePondFile): void
}>()

const fileInput = ref<HTMLInputElement | null>(null)
let pond: FilePond.FilePond | null = null

const accept = computed(() => props.acceptedFileTypes.join(','))

const multiple = computed(() => props.allowMultiple)

onMounted(() => {
  if (!fileInput.value) return

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

  const uploadUrl = props.server || route('products.upload-image')
  
  // Configuration du serveur
  const serverConfig = typeof uploadUrl === 'string' ? {
    process: {
      url: uploadUrl,
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken || '',
      },
      onload: (response) => {
        try {
          const data = JSON.parse(response)
          return data.path
        } catch {
          return response
        }
      },
      onerror: (response) => {
        return response
      },
    },
    revert: null,
    restore: null,
    load: (source, load, error, progress, abort, headers) => {
      // Charger les images depuis une URL avec XMLHttpRequest
      const xhr = new XMLHttpRequest()
      xhr.open('GET', source)
      xhr.responseType = 'blob'
      
      xhr.onload = function() {
        if (xhr.status === 200) {
          load(xhr.response)
        } else {
          error('Erreur lors du chargement de l\'image (status: ' + xhr.status + ')')
        }
      }
      
      xhr.onerror = function() {
        error('Erreur réseau lors du chargement de l\'image')
      }
      
      xhr.onabort = function() {
        abort()
      }
      
      xhr.onprogress = function(e) {
        if (e.lengthComputable) {
          progress(e.lengthComputable, e.loaded, e.total)
        }
      }
      
      xhr.send()
      
      // Retourner une fonction d'annulation
      return {
        abort: () => {
          xhr.abort()
        }
      }
    },
    fetch: null,
  } : uploadUrl
  
  pond = FilePond.create(fileInput.value, {
    server: serverConfig,
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
    storeAsFile: true,
  })

  // Charger les fichiers existants après que FilePond soit prêt
  setTimeout(() => {
    if (props.files && props.files.length > 0) {
      props.files.forEach((file) => {
        if (file.source) {
          // Si c'est une URL (http/https ou chemin relatif), utiliser load
          if (file.source.startsWith('http') || file.source.startsWith('/')) {
            // Utiliser addFile avec l'URL directement - FilePond utilisera la fonction load
            pond.addFile(file.source).catch((error) => {
              console.error('Erreur lors du chargement du fichier:', error, file.source)
            })
          } else {
            // Sinon, traiter comme un fichier local
            pond.addFile(file.source, {
              type: file.options?.type || 'local',
            }).catch((error) => {
              console.error('Erreur lors du chargement du fichier local:', error)
            })
          }
        }
      })
    }
  }, 200)

  // Écouter les événements
  pond.on('addfile', (error, file) => {
    if (error) {
      console.error('Erreur lors de l\'ajout du fichier:', error)
      return
    }
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
    emit('removefile', file)
    emit('update:files', pond?.getFiles() || [])
  })

  pond.on('reorderfiles', () => {
    emit('update:files', pond?.getFiles() || [])
  })

  pond.on('loadfile', (error, file) => {
    if (!error) {
      emit('update:files', pond?.getFiles() || [])
    }
  })
})

onUnmounted(() => {
  if (pond) {
    pond.destroy()
  }
})

watch(
  () => props.files,
  (newFiles) => {
    if (pond && newFiles) {
      const currentFiles = pond.getFiles()
      const currentSources = currentFiles.map((f) => f.source)
      const newSources = newFiles.map((f) => f.source)

      // Supprimer les fichiers qui ne sont plus dans la liste
      currentFiles.forEach((file) => {
        if (!newSources.includes(file.source)) {
          pond?.removeFile(file)
        }
      })

      // Ajouter les nouveaux fichiers
      newFiles.forEach((file) => {
        if (!currentSources.includes(file.source) && file.source) {
          if (file.source.startsWith('http') || file.source.startsWith('/')) {
            pond?.addFile(file.source).catch((error) => {
              console.error('Erreur lors du chargement du fichier:', error)
            })
          } else {
            pond?.addFile(file.source, {
              type: file.options?.type || 'local',
            }).catch((error) => {
              console.error('Erreur lors du chargement du fichier local:', error)
            })
          }
        }
      })
    }
  },
  { deep: true }
)

defineExpose({
  getFiles: () => pond?.getFiles() || [],
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
</style>

