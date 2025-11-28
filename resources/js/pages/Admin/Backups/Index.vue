<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-database me-2"></i>
          Gestion des sauvegardes
        </h1>
        <p class="text-muted mb-0">Gérez les sauvegardes de votre application</p>
      </div>
      <div class="d-flex gap-2">
        <button
          v-if="canCreate('backups')"
          @click="createBackup"
          class="btn btn-primary"
          :disabled="processing"
        >
          <span v-if="processing" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
          <i v-else class="bi bi-plus-circle me-1"></i>
          {{ processing ? 'Création en cours...' : 'Créer une sauvegarde' }}
        </button>
        <button
          v-if="canCreate('backups')"
          @click="createBackupDbOnly"
          class="btn btn-outline-primary"
          :disabled="processing"
        >
          <i class="bi bi-database me-1"></i>
          Sauvegarde DB uniquement
        </button>
        <button
          v-if="canCreate('backups')"
          @click="showImportModal"
          class="btn btn-outline-success"
          :disabled="processing"
        >
          <i class="bi bi-upload me-1"></i>
          Importer une sauvegarde
        </button>
      </div>
    </div>

    <!-- Messages de succès/erreur -->
    <div v-if="$page.props.flash?.success" class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle me-2"></i>
      {{ $page.props.flash.success }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <div v-if="$page.props.flash?.error" class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i>
      {{ $page.props.flash.error }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <!-- Tableau des sauvegardes -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Nom du fichier</th>
              <th>Taille</th>
              <th>Date de création</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="backup in backups" :key="backup.name">
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-3">
                    <div class="bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                      <i class="bi bi-file-zip text-success"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ backup.name }}</div>
                    <div class="text-muted small">{{ backup.path }}</div>
                  </div>
                </div>
              </td>
              <td>
                <span class="badge bg-secondary">{{ backup.size }}</span>
              </td>
              <td>
                <div>{{ formatDate(backup.date) }}</div>
                <div class="text-muted small">{{ formatTime(backup.date) }}</div>
              </td>
              <td class="text-end">
                <div class="btn-group" role="group">
                  <button
                    v-if="canDownload('backups')"
                    @click="downloadBackup(backup.name)"
                    class="btn btn-sm btn-outline-primary"
                    title="Télécharger"
                  >
                    <i class="bi bi-download"></i>
                  </button>
                  <button
                    v-if="canRestore('backups')"
                    @click="confirmRestore(backup.name)"
                    class="btn btn-sm btn-outline-warning"
                    title="Restaurer"
                  >
                    <i class="bi bi-arrow-counterclockwise"></i>
                  </button>
                  <button
                    v-if="canDelete('backups')"
                    @click="confirmDelete(backup.name)"
                    class="btn btn-sm btn-outline-danger"
                    title="Supprimer"
                  >
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="backups.length === 0">
              <td colspan="4" class="text-center text-muted py-4">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                Aucune sauvegarde trouvée
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Informations -->
    <div class="card mt-4">
      <div class="card-header">
        <h5 class="card-title mb-0">
          <i class="bi bi-info-circle me-2"></i>
          Informations
        </h5>
      </div>
      <div class="card-body">
        <ul class="mb-0">
          <li>Les sauvegardes incluent la base de données et les fichiers de l'application.</li>
          <li>Les sauvegardes sont stockées sur le disque: <strong>{{ disk }}</strong></li>
          <li>Les anciennes sauvegardes sont automatiquement nettoyées selon la stratégie configurée.</li>
          <li class="text-danger"><strong>Attention:</strong> La restauration remplacera toutes les données actuelles. Assurez-vous d'avoir une sauvegarde récente avant de restaurer.</li>
        </ul>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { usePermissions } from '@/composables/usePermissions'
import Swal from 'sweetalert2'

const { canCreate, canDownload, canDelete, canRestore } = usePermissions()

interface Backup {
  name: string
  path: string
  size: string
  date: string
  timestamp: number
}

interface Props {
  backups: Backup[]
  disk: string
}

const props = defineProps<Props>()

const processing = ref(false)

const formatDate = (dateString: string): string => {
  const date = new Date(dateString)
  return date.toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

const formatTime = (dateString: string): string => {
  const date = new Date(dateString)
  return date.toLocaleTimeString('fr-FR', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const createBackup = () => {
  // Afficher un message d'information
  Swal.fire({
    title: 'Création de la sauvegarde',
    html: 'La sauvegarde est en cours de création. Cela peut prendre plusieurs minutes selon la taille de votre base de données et de vos fichiers.<br><br>Veuillez patienter...',
    icon: 'info',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading()
    }
  })
  
  processing.value = true
  router.post(route('admin.backups.store'), {
    _method: 'POST'
  }, {
    onSuccess: (page) => {
      processing.value = false
      
      // Vérifier s'il y a un message flash d'erreur
      if (page.props?.flash?.error) {
        Swal.close()
        Swal.fire({
          title: 'Erreur !',
          text: page.props.flash.error,
          icon: 'error'
        })
        return
      }
      
      // Vérifier s'il y a un message flash de succès
      if (page.props?.flash?.success) {
        Swal.fire({
          title: 'Succès !',
          text: page.props.flash.success,
          icon: 'success',
          timer: 2000,
          showConfirmButton: false
        }).then(() => {
          router.visit(route('admin.backups.index'), {
            preserveState: false,
            preserveScroll: false
          })
        })
      } else {
        Swal.close()
        router.visit(route('admin.backups.index'), {
          preserveState: false,
          preserveScroll: false
        })
      }
    },
    onFinish: () => {
      processing.value = false
      Swal.close()
    },
    onError: (errors) => {
      processing.value = false
      Swal.close()
      const errorMessage = errors?.message || errors?.error || 'Une erreur est survenue lors de la création de la sauvegarde.'
      Swal.fire({
        title: 'Erreur !',
        text: errorMessage,
        icon: 'error'
      })
    }
  })
}

const createBackupDbOnly = () => {
  // Afficher un message d'information
  Swal.fire({
    title: 'Création de la sauvegarde',
    html: 'La sauvegarde de la base de données est en cours de création. Cela peut prendre quelques minutes selon la taille de votre base de données.<br><br>Veuillez patienter...',
    icon: 'info',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading()
    }
  })
  
  processing.value = true
  router.post(route('admin.backups.store'), {
    only_db: true
  }, {
    onSuccess: (page) => {
      processing.value = false
      Swal.fire({
        title: 'Succès !',
        text: 'La sauvegarde de la base de données a été créée avec succès.',
        icon: 'success',
        timer: 2000,
        showConfirmButton: false
      }).then(() => {
        router.visit(route('admin.backups.index'), {
          preserveState: false,
          preserveScroll: false
        })
      })
    },
    onFinish: () => {
      processing.value = false
      Swal.close()
    },
    onError: (errors) => {
      processing.value = false
      Swal.close()
      const errorMessage = errors?.message || errors?.error || 'Une erreur est survenue lors de la création de la sauvegarde.'
      Swal.fire({
        title: 'Erreur !',
        text: errorMessage,
        icon: 'error'
      })
    }
  })
}

const downloadBackup = (backupName: string) => {
  window.location.href = route('admin.backups.download', backupName)
}

const confirmDelete = (backupName: string) => {
  Swal.fire({
    title: 'Êtes-vous sûr ?',
    text: `Voulez-vous vraiment supprimer la sauvegarde "${backupName}" ?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#3085d6',
    confirmButtonText: 'Oui, supprimer',
    cancelButtonText: 'Annuler'
  }).then((result) => {
    if (result.isConfirmed) {
      router.delete(route('admin.backups.destroy', backupName), {
        onSuccess: () => {
          Swal.fire({
            title: 'Supprimé !',
            text: 'La sauvegarde a été supprimée avec succès.',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
          })
        },
        onError: (errors) => {
          const errorMessage = errors?.message || errors?.error || 'Une erreur est survenue lors de la suppression.'
          Swal.fire({
            title: 'Erreur !',
            text: errorMessage,
            icon: 'error'
          })
        }
      })
    }
  })
}

const confirmRestore = (backupName: string) => {
  Swal.fire({
    title: 'Attention !',
    html: `
      <p class="text-start">Vous êtes sur le point de restaurer la sauvegarde <strong>${backupName}</strong>.</p>
      <p class="text-start text-danger"><strong>Cette action est irréversible et remplacera toutes les données actuelles.</strong></p>
      <p class="text-start">Assurez-vous d'avoir créé une sauvegarde récente avant de continuer.</p>
    `,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#3085d6',
    confirmButtonText: 'Oui, restaurer',
    cancelButtonText: 'Annuler',
    input: 'checkbox',
    inputPlaceholder: 'Je confirme vouloir restaurer cette sauvegarde',
    inputValidator: (value) => {
      if (!value) {
        return 'Vous devez confirmer la restauration'
      }
    }
  }).then((result) => {
    if (result.isConfirmed && result.value) {
      // Afficher un message de progression
      Swal.fire({
        title: 'Restauration en cours',
        html: `Restauration de la sauvegarde <strong>${backupName}</strong>...<br><br>Cette opération peut prendre plusieurs minutes. Veuillez patienter...`,
        icon: 'info',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
          Swal.showLoading()
        }
      })
      
      processing.value = true
      
      router.post(route('admin.backups.restore', backupName), {
        confirm: true
      }, {
        onSuccess: () => {
          processing.value = false
          Swal.close()
          Swal.fire({
            title: 'Succès !',
            text: 'La sauvegarde a été restaurée avec succès. L\'application a été restaurée à l\'état de la sauvegarde.',
            icon: 'success',
            confirmButtonText: 'OK'
          }).then(() => {
            router.visit(route('admin.backups.index'))
          })
        },
        onError: (errors) => {
          processing.value = false
          Swal.close()
          
          // Gérer différents types d'erreurs
          let errorMessage = 'Une erreur est survenue lors de la restauration.'
          
          try {
            if (typeof errors === 'string') {
              errorMessage = errors
            } else if (errors && typeof errors === 'object') {
              if (errors.message) {
                errorMessage = errors.message
              } else if (errors.error) {
                errorMessage = errors.error
              }
            }
          } catch (e) {
            console.error('Erreur lors de la gestion des erreurs:', e)
          }
          
          Swal.fire({
            title: 'Erreur !',
            text: errorMessage,
            icon: 'error',
            confirmButtonText: 'OK'
          })
        }
      })
    }
  })
}

const showImportModal = () => {
  Swal.fire({
    title: 'Importer une sauvegarde',
    html: `
      <p class="text-start mb-3">Sélectionnez un fichier zip de sauvegarde à importer.</p>
      <input type="file" id="backup-file-input" class="form-control" accept=".zip">
      <p class="text-muted small mt-2 text-start">
        <i class="bi bi-info-circle me-1"></i>
        Le fichier doit être un fichier zip valide contenant une sauvegarde de l'application.
      </p>
    `,
    icon: 'info',
    showCancelButton: true,
    confirmButtonText: 'Importer',
    cancelButtonText: 'Annuler',
    confirmButtonColor: '#198754',
    cancelButtonColor: '#6c757d',
    didOpen: () => {
      const input = document.getElementById('backup-file-input') as HTMLInputElement
      if (input) {
        input.focus()
      }
    },
    preConfirm: () => {
      const input = document.getElementById('backup-file-input') as HTMLInputElement
      if (!input || !input.files || input.files.length === 0) {
        Swal.showValidationMessage('Veuillez sélectionner un fichier zip.')
        return false
      }
      
      const file = input.files[0]
      if (!file.name.endsWith('.zip')) {
        Swal.showValidationMessage('Le fichier doit être un fichier zip (.zip).')
        return false
      }
      
      // Vérifier la taille (max 10GB)
      const maxSize = 10 * 1024 * 1024 * 1024 // 10 GB
      if (file.size > maxSize) {
        Swal.showValidationMessage('Le fichier est trop volumineux. Taille maximale : 10 GB.')
        return false
      }
      
      return file
    }
  }).then((result) => {
    if (result.isConfirmed && result.value) {
      const file = result.value as File
      
      if (!file || !(file instanceof File)) {
        Swal.fire({
          title: 'Erreur !',
          text: 'Le fichier sélectionné n\'est pas valide.',
          icon: 'error'
        })
        return
      }
      
      // Afficher un message de progression
      Swal.fire({
        title: 'Import en cours',
        html: `Import du fichier <strong>${file.name}</strong>...<br><br>Veuillez patienter...`,
        icon: 'info',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
          Swal.showLoading()
        }
      })
      
      processing.value = true
      
      // Créer un FormData pour envoyer le fichier
      const formData = new FormData()
      formData.append('backup_file', file)
      
      // Utiliser router.post avec FormData pour l'upload
      router.post(route('admin.backups.import'), formData, {
        forceFormData: true,
        preserveState: false,
        preserveScroll: false,
        onSuccess: (page) => {
          processing.value = false
          
          // Vérifier s'il y a un message flash d'erreur
          if (page.props?.flash?.error) {
            Swal.fire({
              title: 'Erreur !',
              text: page.props.flash.error,
              icon: 'error'
            })
            return
          }
          
          // Vérifier s'il y a un message flash de succès
          if (page.props?.flash?.success) {
            Swal.fire({
              title: 'Succès !',
              text: page.props.flash.success,
              icon: 'success',
              timer: 2000,
              showConfirmButton: false
            }).then(() => {
              router.visit(route('admin.backups.index'), {
                preserveState: false,
                preserveScroll: false
              })
            })
          } else {
            Swal.close()
            router.visit(route('admin.backups.index'), {
              preserveState: false,
              preserveScroll: false
            })
          }
        },
        onFinish: () => {
          processing.value = false
          Swal.close()
        },
        onError: (errors) => {
          processing.value = false
          Swal.close()
          
          // Gérer différents types d'erreurs
          let errorMessage = 'Une erreur est survenue lors de l\'import de la sauvegarde.'
          
          try {
            if (typeof errors === 'string') {
              errorMessage = errors
            } else if (errors && typeof errors === 'object') {
              // Erreur de validation Laravel
              if (errors.backup_file) {
                if (Array.isArray(errors.backup_file)) {
                  errorMessage = errors.backup_file[0]
                } else if (typeof errors.backup_file === 'string') {
                  errorMessage = errors.backup_file
                }
              } 
              // Erreur générale
              else if (errors.message) {
                errorMessage = errors.message
              } else if (errors.error) {
                errorMessage = errors.error
              } 
              // Essayer de trouver le premier message d'erreur
              else {
                const errorKeys = Object.keys(errors)
                if (errorKeys.length > 0) {
                  const firstKey = errorKeys[0]
                  const firstError = errors[firstKey]
                  if (Array.isArray(firstError) && firstError.length > 0) {
                    errorMessage = firstError[0]
                  } else if (typeof firstError === 'string') {
                    errorMessage = firstError
                  }
                }
              }
            }
          } catch (e) {
            console.error('Erreur lors de la gestion des erreurs:', e)
            errorMessage = 'Une erreur inattendue est survenue lors de l\'import de la sauvegarde.'
          }
          
          if (!errorMessage || errorMessage.trim() === '') {
            errorMessage = 'Une erreur est survenue lors de l\'import de la sauvegarde.'
          }
          
          Swal.fire({
            title: 'Erreur !',
            html: `<div class="text-start">${errorMessage}</div>`,
            icon: 'error',
            confirmButtonText: 'OK',
            width: '500px'
          })
        }
      })
    }
  })
}

</script>

