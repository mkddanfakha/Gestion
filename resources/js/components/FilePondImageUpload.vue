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
      // Cette fonction est conservée pour compatibilité mais n'est plus utilisée
      // Les images sont chargées manuellement dans onMounted
      error('Chargement non supporté via cette méthode')
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
  // Charger manuellement les images et les passer comme File
  if (props.files && props.files.length > 0) {
    props.files.forEach(async (file) => {
      if (file.source && (file.source.startsWith('http') || file.source.startsWith('/'))) {
        try {
          // Convertir l'URL relative en absolue
          let fileUrl = file.source
          if (file.source.startsWith('/') && !file.source.startsWith('http')) {
            fileUrl = window.location.origin + file.source
          }
          
          // Charger l'image manuellement
          const response = await fetch(fileUrl)
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`)
          }
          
          const blob = await response.blob()
          const urlParts = fileUrl.split('/')
          const originalFileName = urlParts[urlParts.length - 1] || 'image.jpg'
          const contentType = response.headers.get('content-type') || 'image/jpeg'
          
          // Inclure l'ID du média dans le nom du fichier pour faciliter la récupération
          // Format: mediaId_originalFileName
          let fileName = originalFileName
          if (file.options?.metadata?.mediaId) {
            fileName = `${file.options.metadata.mediaId}_${originalFileName}`
          }
          
          // Créer un File à partir du blob
          const fileObj = new File([blob], fileName, { type: contentType })
          
          // Préparer les options avec les métadonnées
          const fileOptions: any = {
            type: file.options?.type || 'limbo',
          }
          
          if (file.options?.metadata) {
            fileOptions.metadata = file.options.metadata
          }
          
          // Ajouter le fichier à FilePond
          const addedFile = await pond.addFile(fileObj, fileOptions)
          
          // S'assurer que les métadonnées sont bien attachées
          if (addedFile && file.options?.metadata) {
            // FilePond peut stocker les métadonnées différemment selon la version
            // Essayer plusieurs méthodes pour s'assurer qu'elles sont bien stockées
            if (addedFile.setMetadata) {
              addedFile.setMetadata(file.options.metadata)
            }
            if (addedFile.metadata) {
              Object.assign(addedFile.metadata, file.options.metadata)
            }
            // Stocker aussi dans une propriété personnalisée
            (addedFile as any).__mediaId = file.options.metadata.mediaId
          }
        } catch (err) {
          console.error('Erreur lors du chargement manuel de l\'image:', err, file.source)
        }
      }
    })
  }

  // Écouter les événements
  pond.on('addfile', (error, file) => {
    if (error) {
      console.error('Erreur lors de l\'ajout du fichier:', error)
      return
    }
    
    // S'assurer que les métadonnées sont bien attachées après l'ajout
    // Les métadonnées peuvent être passées via les options lors de addFile
    // mais il faut parfois les réattacher après l'ajout
    if (file && !file.metadata) {
      // Essayer de récupérer les métadonnées depuis les options si disponibles
      const fileOptions = (file as any).options
      if (fileOptions && fileOptions.metadata) {
        if (file.setMetadata) {
          file.setMetadata(fileOptions.metadata)
        } else {
          (file as any).metadata = fileOptions.metadata
        }
      }
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
      newFiles.forEach(async (file) => {
        if (!currentSources.includes(file.source) && file.source) {
          // Si c'est une URL, charger manuellement comme dans onMounted
          if (file.source.startsWith('http') || file.source.startsWith('/')) {
            try {
              // Convertir l'URL relative en absolue
              let fileUrl = file.source
              if (file.source.startsWith('/') && !file.source.startsWith('http')) {
                fileUrl = window.location.origin + file.source
              }
              
              // Charger l'image manuellement
              const response = await fetch(fileUrl)
              if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`)
              }
              
              const blob = await response.blob()
              const urlParts = fileUrl.split('/')
              const originalFileName = urlParts[urlParts.length - 1] || 'image.jpg'
              const contentType = response.headers.get('content-type') || 'image/jpeg'
              
              // Inclure l'ID du média dans le nom du fichier pour faciliter la récupération
              let fileName = originalFileName
              if (file.options?.metadata?.mediaId) {
                fileName = `${file.options.metadata.mediaId}_${originalFileName}`
              }
              
              // Créer un File à partir du blob
              const fileObj = new File([blob], fileName, { type: contentType })
              
              // Préparer les options avec les métadonnées
              const fileOptions: any = {
                type: file.options?.type || 'limbo',
              }
              
              if (file.options?.metadata) {
                fileOptions.metadata = file.options.metadata
              }
              
              // Ajouter le fichier à FilePond
              const addedFile = await pond?.addFile(fileObj, fileOptions)
              
              // S'assurer que les métadonnées sont bien attachées
              if (addedFile && file.options?.metadata) {
                if (addedFile.setMetadata) {
                  addedFile.setMetadata(file.options.metadata)
                }
                if (addedFile.metadata) {
                  Object.assign(addedFile.metadata, file.options.metadata)
                }
                // Stocker aussi dans une propriété personnalisée
                (addedFile as any).__mediaId = file.options.metadata.mediaId
              }
            } catch (err) {
              console.error('Erreur lors du chargement du fichier:', err, file.source)
            }
          } else {
            // Pour les fichiers locaux, utiliser addFile directement
            const fileOptions: any = {
              type: file.options?.type || 'limbo',
            }
            
            if (file.options?.metadata) {
              fileOptions.metadata = file.options.metadata
            }
            
            pond?.addFile(file.source, fileOptions).catch((error) => {
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

