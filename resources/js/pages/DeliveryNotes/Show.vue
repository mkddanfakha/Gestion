<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Bon de livraison {{ deliveryNote.delivery_number }}</h1>
        <p class="text-muted mb-0">Détails du bon de livraison</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('delivery-notes.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <div class="row g-4">
      <!-- Informations du bon de livraison -->
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations générales</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Numéro de BL</label>
                <p class="mb-0 font-monospace">{{ deliveryNote.delivery_number }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Statut</label>
                <p class="mb-0">
                  <span class="badge" :class="getStatusBadgeClass(deliveryNote.status)">
                    {{ deliveryNote.status_label }}
                  </span>
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Fournisseur</label>
                <p class="mb-0">{{ deliveryNote.supplier?.name || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Date de livraison</label>
                <p class="mb-0">{{ formatDate(deliveryNote.delivery_date) }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Numéro de BC</label>
                <p class="mb-0">
                  <code v-if="deliveryNote.purchase_order?.po_number">
                    {{ deliveryNote.purchase_order.po_number }}
                  </code>
                  <span v-else class="text-muted">Non renseigné</span>
                </p>
              </div>
              
              <div class="col-md-6" v-if="deliveryNote.invoice_number">
                <label class="form-label text-muted">Numéro de facture</label>
                <p class="mb-0">
                  <code>{{ deliveryNote.invoice_number }}</code>
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Créé par</label>
                <p class="mb-0">{{ deliveryNote.user?.name || 'Non renseigné' }}</p>
              </div>
            </div>

            <!-- Notes -->
            <div v-if="deliveryNote.notes" class="mt-4 pt-3 border-top">
              <label class="form-label text-muted">Notes</label>
              <div class="bg-light p-3 rounded">
                <p class="mb-0">{{ deliveryNote.notes }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Articles du bon de livraison -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Articles livrés</h5>
          </div>
          <div class="card-body">
            <div v-if="!deliveryNote.items || deliveryNote.items.length === 0" class="text-center py-4 text-muted">
              <i class="bi bi-cart-x fs-1 mb-3"></i>
              <p>Aucun article trouvé pour ce bon de livraison.</p>
            </div>
            
            <div v-else class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Produit</th>
                    <th>Quantité</th>
                    <th>Prix unitaire d'achat</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in deliveryNote.items" :key="item.id">
                    <td class="align-middle">
                      <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                          <div 
                            v-if="item.product?.image_url"
                            class="bg-light rounded overflow-hidden d-flex align-items-center justify-content-center"
                            style="width: 60px; height: 60px;"
                          >
                            <img
                              :src="item.product.image_url"
                              :alt="item.product?.name || 'Produit'"
                              class="img-fluid"
                              style="width: 100%; height: 100%; object-fit: cover;"
                            />
                          </div>
                          <div
                            v-else
                            class="bg-light rounded d-flex align-items-center justify-content-center"
                            style="width: 60px; height: 60px;"
                          >
                            <i class="bi bi-box text-muted"></i>
                          </div>
                        </div>
                        <div>
                          <div class="fw-medium">{{ item.product?.name || 'Produit supprimé' }}</div>
                          <div class="text-muted small" v-if="item.product">
                            {{ item.product.category?.name || 'Sans catégorie' }}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td class="align-middle">
                      <span class="badge bg-secondary">{{ item.quantity }}</span>
                    </td>
                    <td class="align-middle">{{ formatCurrency(item.unit_price) }}</td>
                    <td class="align-middle fw-medium text-success">{{ formatCurrency(item.total_price) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="col-lg-4">
        <!-- Résumé financier -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Résumé financier</h5>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">Sous-total</span>
                <span class="fw-medium">{{ formatCurrency(deliveryNote.subtotal) }}</span>
              </div>
              <div class="d-flex justify-content-between mb-2" v-if="deliveryNote.tax_amount > 0">
                <span class="text-muted">Taxes</span>
                <span class="fw-medium">{{ formatCurrency(deliveryNote.tax_amount) }}</span>
              </div>
              <div class="d-flex justify-content-between mb-2" v-if="deliveryNote.discount_amount > 0">
                <span class="text-muted">Remise</span>
                <span class="fw-medium text-danger">-{{ formatCurrency(deliveryNote.discount_amount) }}</span>
              </div>
              <hr>
              <div class="d-flex justify-content-between">
                <span class="fw-bold">Total</span>
                <span class="fw-bold text-success fs-5">{{ formatCurrency(deliveryNote.total_amount) }}</span>
              </div>
            </div>
            
            <!-- Avertissement si non validé -->
            <div v-if="deliveryNote.status === 'pending'" class="alert alert-warning">
              <i class="bi bi-exclamation-triangle me-1"></i>
              Ce bon de livraison n'est pas encore validé. Le stock ne sera ajusté qu'après validation.
            </div>
            
            <!-- Succès si validé -->
            <div v-if="deliveryNote.status === 'validated'" class="alert alert-success">
              <i class="bi bi-check-circle me-1"></i>
              Stock ajusté avec succès
            </div>
          </div>
        </div>

        <!-- Actions rapides -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Actions rapides</h5>
          </div>
          <div class="card-body">
            <div class="d-grid gap-2">
              <button
                @click="downloadDeliveryNote"
                class="btn btn-primary"
                :disabled="isDownloading || isPrinting"
              >
                <span v-if="isDownloading" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-download me-1"></i>
                {{ isDownloading ? 'Téléchargement...' : 'Télécharger le BL' }}
              </button>
              <button
                @click="printDeliveryNote"
                class="btn btn-info"
                :disabled="isPrinting || isDownloading"
              >
                <span v-if="isPrinting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-printer me-1"></i>
                {{ isPrinting ? 'Ouverture...' : 'Imprimer le BL' }}
              </button>
              <Link
                v-if="deliveryNote.status === 'pending'"
                :href="route('delivery-notes.edit', { id: deliveryNote.id })"
                class="btn btn-outline-primary"
                :class="{ 'disabled': isDownloading || isPrinting }"
                :tabindex="(isDownloading || isPrinting) ? -1 : 0"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier le BL
              </Link>
              <button
                v-if="deliveryNote.status === 'pending' && isAdmin"
                @click="validateDeliveryNote"
                class="btn btn-success"
                :disabled="isDownloading || isPrinting"
              >
                <i class="bi bi-check-circle me-1"></i>
                Valider le BL
              </button>
              <button
                v-if="deliveryNote.status === 'pending'"
                @click="deleteDeliveryNote"
                class="btn btn-outline-danger"
                :disabled="isDownloading || isPrinting"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer le bon de livraison
              </button>
            </div>
          </div>
        </div>

        <!-- Facture/BL fournisseur -->
        <div class="card mt-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Facture/BL fournisseur</h5>
            <div v-if="hasInvoiceFile" class="d-flex align-items-center gap-2">
              <a :href="invoicePreviewUrl" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-eye me-1"></i>
                Ouvrir
              </a>
              <button @click="onDeleteInvoice" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash me-1"></i>
                Supprimer
              </button>
            </div>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label">Choisir un fichier (PDF ou image)</label>
              <input class="form-control bg-white" type="file" accept=".pdf,image/*" @change="onFileChange" />
              <div v-if="selectedFile" class="mt-2">
                <small class="text-success">
                  <i class="bi bi-check-circle me-1"></i>
                  Fichier sélectionné: <strong>{{ selectedFile.name }}</strong>
                </small>
              </div>
            </div>

            <div class="mb-3">
              <div class="d-grid">
                <button class="btn btn-primary" :disabled="!selectedFile" @click="onUploadInvoice">
                  <i class="bi bi-upload me-1"></i>
                  {{ hasInvoiceFile ? 'Remplacer le fichier' : 'Uploader le fichier' }}
                </button>
              </div>
              <div class="mt-2">
                <small class="text-muted">
                  <i class="bi bi-info-circle me-1"></i>
                  Formats acceptés: PDF, JPG, PNG, WEBP. Taille max 2 Mo.
                </small>
              </div>
            </div>

            <div v-if="hasInvoiceFile" class="mt-3">
              <label class="form-label text-muted">Aperçu</label>
              <div class="border rounded p-2 bg-light">
                <img v-if="isImage" :src="invoicePreviewUrl" alt="Aperçu" class="img-fluid rounded" />
                <iframe v-else-if="isPdf" :src="invoicePreviewUrl" style="width: 100%; height: 360px;" />
                <div v-else class="text-muted small">Type de fichier non prévisualisable. Utilisez le bouton "Ouvrir".</div>
                <div class="mt-2 small text-muted">
                  <div v-if="deliveryNote.invoice_file_name">Nom: {{ deliveryNote.invoice_file_name }}</div>
                  <div v-if="deliveryNote.invoice_file_size">Taille: {{ formatSize(deliveryNote.invoice_file_size) }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

interface Category {
  id: number
  name: string
  color: string
}

interface Product {
  id: number
  name: string
  sku?: string
  category?: Category
  image_url?: string | null
}

interface DeliveryNoteItem {
  id: number
  product?: Product
  quantity: number
  unit_price: number
  total_price: number
}

interface DeliveryNote {
  id: number
  delivery_number: string
  supplier?: any
  purchase_order?: any
  user?: any
  delivery_date: string
  status: string
  status_label?: string
  notes?: string
  invoice_number?: string
  subtotal: number
  tax_amount: number
  discount_amount: number
  total_amount: number
  items?: DeliveryNoteItem[]
  invoice_file_path?: string
  invoice_file_name?: string
  invoice_file_mime?: string
  invoice_file_size?: number
}

interface Props {
  deliveryNote: DeliveryNote
}

const props = defineProps<Props>()

const page = usePage()
const isAdmin = computed(() => {
  const user = (page.props.auth as any)?.user
  return user?.role === 'admin'
})

const { success, error, confirm } = useSweetAlert()

const isDownloading = ref(false)
const isPrinting = ref(false)

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const getStatusBadgeClass = (status: string) => {
  const classes: Record<string, string> = {
    pending: 'bg-warning',
    validated: 'bg-success',
    cancelled: 'bg-danger'
  }
  return classes[status] || 'bg-secondary'
}

// Gestion fichier facture/BL
const selectedFile = ref<File | null>(null)
const invoicePreviewUrl = computed(() => route('delivery-notes.invoice.show', { deliveryNote: props.deliveryNote.id }))
const hasInvoiceFile = computed(() => !!props.deliveryNote.invoice_file_path)
const isImage = computed(() => (props.deliveryNote.invoice_file_mime || '').startsWith('image'))
const isPdf = computed(() => (props.deliveryNote.invoice_file_mime || '').includes('pdf'))

const onFileChange = (e: Event) => {
  const input = e.target as HTMLInputElement
  const file = input.files && input.files[0] ? input.files[0] : null
  
  if (file) {
    // Vérifier la taille du fichier (2 Mo = 2 * 1024 * 1024 bytes)
    const maxSize = 2 * 1024 * 1024 // 2 Mo en bytes
    if (file.size > maxSize) {
      error('Le fichier est trop volumineux. La taille maximale autorisée est de 2 Mo.')
      input.value = '' // Réinitialiser l'input
      selectedFile.value = null
      return
    }
  }
  
  selectedFile.value = file
}

const onUploadInvoice = async () => {
  if (!selectedFile.value) return
  const formData = new FormData()
  formData.append('file', selectedFile.value)
  router.post(route('delivery-notes.invoice.upload', { deliveryNote: props.deliveryNote.id }), formData, {
    forceFormData: true,
    onSuccess: () => {
      selectedFile.value = null
      success('Fichier uploadé avec succès')
    },
    onError: (errors) => {
      const msg = (errors as any).message || 'Erreur lors de l\'upload du fichier.'
      error(msg)
    }
  })
}

const onDeleteInvoice = async () => {
  const confirmed = await confirm('Supprimer le fichier de facture/BL ?')
  if (!confirmed) return
  router.delete(route('delivery-notes.invoice.delete', { deliveryNote: props.deliveryNote.id }), {
    onSuccess: () => {
      success('Fichier supprimé avec succès')
    },
    onError: (errors) => {
      const msg = (errors as any).message || 'Erreur lors de la suppression du fichier.'
      error(msg)
    }
  })
}

const formatSize = (bytes: number) => {
  if (!bytes && bytes !== 0) return ''
  const units = ['octets', 'Ko', 'Mo', 'Go']
  let size = bytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit++
  }
  return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(size)} ${units[unit]}`
}

const validateDeliveryNote = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir valider le bon de livraison "${props.deliveryNote.delivery_number}" ? Le stock sera ajusté.`)
  
  if (confirmed) {
    router.post(route('delivery-notes.validate', { id: props.deliveryNote.id }), {}, {
      onSuccess: () => {
        success(`Bon de livraison "${props.deliveryNote.delivery_number}" validé et stock ajusté avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la validation du bon de livraison.'
        error(errorMessage)
      }
    })
  }
}

const downloadDeliveryNote = async () => {
  isDownloading.value = true
  try {
    const url = route('delivery-notes.download', { deliveryNote: props.deliveryNote.id })
    const response = await fetch(url)
    
    if (!response.ok) {
      throw new Error('Erreur lors du téléchargement')
    }
    
    const blob = await response.blob()
    const downloadUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = downloadUrl
    
    // Extraire le nom du fichier depuis les headers
    const contentDisposition = response.headers.get('Content-Disposition')
    let filename = `Bon_de_livraison_${props.deliveryNote.delivery_number}.pdf`
    if (contentDisposition) {
      const filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
      if (filenameMatch && filenameMatch[1]) {
        filename = filenameMatch[1].replace(/['"]/g, '')
      }
    }
    
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(downloadUrl)
    
    success('Bon de livraison téléchargé avec succès !')
  } catch (err) {
    error('Erreur lors du téléchargement du bon de livraison.')
  } finally {
    isDownloading.value = false
  }
}

const printDeliveryNote = async () => {
  isPrinting.value = true
  try {
    const url = route('delivery-notes.print', { deliveryNote: props.deliveryNote.id })
    const response = await fetch(url)
    
    if (!response.ok) {
      throw new Error('Erreur lors de l\'ouverture du PDF')
    }
    
    const blob = await response.blob()
    const printUrl = window.URL.createObjectURL(blob)
    const printWindow = window.open(printUrl, '_blank')
    
    if (printWindow) {
      printWindow.onload = () => {
        printWindow.print()
      }
    }
  } catch (err) {
    error('Erreur lors de l\'ouverture du bon de livraison pour impression.')
  } finally {
    isPrinting.value = false
  }
}

const deleteDeliveryNote = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le bon de livraison "${props.deliveryNote.delivery_number}" ?`)
  
  if (confirmed) {
    router.delete(route('delivery-notes.destroy', { id: props.deliveryNote.id }), {
      onSuccess: () => {
        success(`Bon de livraison "${props.deliveryNote.delivery_number}" supprimé avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression du bon de livraison.'
        error(errorMessage)
      }
    })
  }
}
</script>

