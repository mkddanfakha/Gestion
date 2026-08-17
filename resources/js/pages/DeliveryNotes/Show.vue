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
                <label class="form-label text-muted">Bon de commande</label>
                <p class="mb-0">
                  <Link
                    v-if="deliveryNote.purchase_order?.id"
                    :href="route('purchase-orders.show', { id: deliveryNote.purchase_order.id })"
                    class="text-decoration-none"
                  >
                    <code>{{ deliveryNote.purchase_order.po_number }}</code>
                  </Link>
                  <span v-else class="badge bg-light text-muted border">BL sans bon de commande</span>
                </p>
              </div>
              
              <div class="col-md-6" v-if="deliveryNote.invoice_number">
                <label class="form-label text-muted">Réf. facture fournisseur</label>
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

        <div v-if="purchaseOrderReceipt" class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Progression du bon de commande</h5>
          </div>
          <div class="card-body">
            <div class="row g-3 text-center mb-3">
              <div class="col-6 col-md-3">
                <div class="text-muted small">Commandé</div>
                <div class="fw-semibold">{{ purchaseOrderReceipt.totals.ordered }}</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-muted small">Livré (validé)</div>
                <div class="fw-semibold text-success">{{ purchaseOrderReceipt.totals.delivered }}</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-muted small">En attente (BL brouillon)</div>
                <div class="fw-semibold text-warning">{{ purchaseOrderReceipt.totals.pending }}</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-muted small">Reste</div>
                <div class="fw-semibold text-primary">{{ purchaseOrderReceipt.totals.remaining }}</div>
              </div>
            </div>
            <div class="progress" style="height: 8px">
              <div
                class="progress-bar"
                role="progressbar"
                :style="{ width: `${Math.min(purchaseOrderReceipt.progress_percent, 100)}%` }"
                :aria-valuenow="purchaseOrderReceipt.progress_percent"
                aria-valuemin="0"
                aria-valuemax="100"
              ></div>
            </div>
            <p class="small text-muted mt-2 mb-0">
              Les BL en attente ne sont pas comptabilisés comme livrés tant qu'ils ne sont pas validés.
            </p>
          </div>
        </div>

        <!-- Articles du bon de livraison -->
        <div class="card mb-4">
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

        <!-- Document officiel -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-file-earmark-pdf me-2"></i>
              Document officiel
            </h5>
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3">
              Bon de livraison généré par MKD-Pro à partir des données actuelles du BL.
            </p>
            <div class="d-flex align-items-start gap-3 mb-3">
              <div class="text-danger fs-3">
                <i class="bi bi-file-earmark-pdf"></i>
              </div>
              <div>
                <div class="fw-semibold">{{ deliveryNote.delivery_number }}</div>
                <div class="text-muted small font-monospace">{{ officialPdfFilename }}</div>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button
                v-if="canPreviewDeliveryNote"
                type="button"
                class="btn btn-outline-primary"
                :disabled="isPreviewing || isDownloading || isPrinting"
                @click="previewDeliveryNote"
              >
                <span v-if="isPreviewing" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-eye me-1"></i>
                {{ isPreviewing ? 'Génération...' : 'Prévisualiser' }}
              </button>
              <button
                type="button"
                class="btn btn-primary"
                :disabled="isDownloading || isPrinting || isPreviewing"
                @click="downloadDeliveryNote"
              >
                <span v-if="isDownloading" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-download me-1"></i>
                {{ isDownloading ? 'Téléchargement...' : 'Télécharger PDF' }}
              </button>
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="isPrinting || isDownloading || isPreviewing"
                @click="printDeliveryNote"
              >
                <span v-if="isPrinting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-printer me-1"></i>
                {{ isPrinting ? 'Ouverture...' : 'Imprimer' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Pièces jointes -->
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
              <i class="bi bi-paperclip me-2"></i>
              Pièces jointes
            </h5>
            <span v-if="deliveryNote.attachments?.length" class="badge bg-secondary">
              {{ deliveryNote.attachments.length }}
            </span>
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3">
              Documents fournis par l'utilisateur ou le fournisseur (factures, BL signés, photos, etc.).
            </p>
            <AttachmentList
              v-if="deliveryNote.attachments?.length"
              :attachments="deliveryNote.attachments"
              :allow-delete="canUpdateDeliveryNote"
              @preview="openAttachmentPreview"
            />
            <p v-else class="text-muted mb-0">Aucune pièce jointe.</p>
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
              <Link
                v-if="deliveryNote.status === 'pending'"
                :href="route('delivery-notes.edit', { id: deliveryNote.id })"
                class="btn btn-outline-primary"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier le BL
              </Link>
              <button
                v-if="deliveryNote.status === 'pending' && isAdmin"
                @click="validateDeliveryNote"
                class="btn btn-success"
                :disabled="isValidating"
              >
                <span v-if="isValidating" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-check-circle me-1"></i>
                {{ isValidating ? 'Validation...' : 'Valider le BL' }}
              </button>
              <button
                v-if="deliveryNote.status !== 'cancelled' && canUpdateDeliveryNote"
                @click="cancelDeliveryNote"
                class="btn btn-outline-warning"
              >
                <i class="bi bi-x-circle me-1"></i>
                Annuler le BL
              </button>
              <button
                v-if="deliveryNote.status === 'pending'"
                @click="deleteDeliveryNote"
                class="btn btn-outline-danger"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer le bon de livraison
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { formatCurrency } from '@/utils/currencyFormatter'
import AppLayout from '@/layouts/AppLayout.vue'
import AttachmentList from '@/components/attachments/AttachmentList.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useDocumentPdfPreview } from '@/composables/useDocumentPdfPreview'
import { useDocumentPreview } from '@/composables/useDocumentPreview'
import { usePermissions } from '@/composables/usePermissions'
import type { AttachmentRecord } from '@/types/attachment'
import type { PurchaseOrderReceiptSummary } from '@/composables/usePurchaseOrderReceipt'
import { ref, computed } from 'vue'

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
  attachments?: AttachmentRecord[]
}

interface Props {
  deliveryNote: DeliveryNote
  purchaseOrderReceipt?: PurchaseOrderReceiptSummary | null
}

const props = defineProps<Props>()

const page = usePage()
const isAdmin = computed(() => {
  const user = (page.props.auth as any)?.user
  return user?.role === 'admin'
})

const { success, error, confirm } = useSweetAlert()
const { openFromUrl } = useDocumentPdfPreview()
const { openAttachment } = useDocumentPreview()
const { canAny } = usePermissions()
const canPreviewDeliveryNote = canAny('delivery-notes', ['print', 'create', 'update'])
const canUpdateDeliveryNote = canAny('delivery-notes', ['update'])

const isDownloading = ref(false)
const isPrinting = ref(false)
const isPreviewing = ref(false)
const isValidating = ref(false)
const isCancelling = ref(false)

const officialPdfFilename = computed(() => `BL-${props.deliveryNote.delivery_number}.pdf`)

const openAttachmentPreview = (attachment: AttachmentRecord) => {
  void openAttachment(attachment, `Aperçu — ${attachment.original_name}`)
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

const validateDeliveryNote = async () => {
  if (isValidating.value) {
    return
  }

  const confirmed = await confirm(`Êtes-vous sûr de vouloir valider le bon de livraison "${props.deliveryNote.delivery_number}" ? Le stock sera ajusté.`)
  
  if (confirmed) {
    isValidating.value = true
    router.post(route('delivery-notes.validate', { deliveryNote: props.deliveryNote.id }), {}, {
      onSuccess: () => {
        success(`Bon de livraison "${props.deliveryNote.delivery_number}" validé et stock ajusté avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la validation du bon de livraison.'
        error(errorMessage)
      },
      onFinish: () => {
        isValidating.value = false
      },
    })
  }
}

const cancelDeliveryNote = async () => {
  if (isCancelling.value) {
    return
  }

  const confirmed = await confirm(`Annuler le bon de livraison "${props.deliveryNote.delivery_number}" ? Le stock et le bon de commande seront recalculés.`)

  if (confirmed) {
    isCancelling.value = true
    router.post(route('delivery-notes.cancel', { deliveryNote: props.deliveryNote.id }), {}, {
      onSuccess: () => {
        success(`Bon de livraison "${props.deliveryNote.delivery_number}" annulé avec succès.`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de l\'annulation du bon de livraison.'
        error(errorMessage)
      },
      onFinish: () => {
        isCancelling.value = false
      },
    })
  }
}

const previewDeliveryNote = async () => {
  if (isPreviewing.value) {
    return
  }

  isPreviewing.value = true

  try {
    await openFromUrl(
      route('delivery-notes.print', { deliveryNote: props.deliveryNote.id }),
      officialPdfFilename.value,
      undefined,
      `Bon de livraison ${props.deliveryNote.delivery_number}`,
      {
        downloadUrl: route('delivery-notes.download', { deliveryNote: props.deliveryNote.id }),
      },
    )
  } finally {
    isPreviewing.value = false
  }
}

const downloadDeliveryNote = async () => {
  if (isDownloading.value) {
    return
  }

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
    
    const contentDisposition = response.headers.get('Content-Disposition')
    let filename = officialPdfFilename.value
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
    error('Impossible de générer le PDF du bon de livraison.')
  } finally {
    isDownloading.value = false
  }
}

const printDeliveryNote = async () => {
  if (isPrinting.value) {
    return
  }

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
    } else {
      window.location.href = url
    }
  } catch (err) {
    error('Impossible de générer le PDF du bon de livraison.')
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
