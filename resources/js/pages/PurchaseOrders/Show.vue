<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Bon de commande {{ purchaseOrder.po_number }}</h1>
        <p class="text-muted mb-0">Détails du bon de commande</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('purchase-orders.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <div class="row g-4">
      <!-- Informations du bon de commande -->
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations générales</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Numéro de BC</label>
                <p class="mb-0 font-monospace">{{ purchaseOrder.po_number }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Statut</label>
                <p class="mb-0">
                  <span class="badge" :class="getStatusBadgeClass(purchaseOrder.status)">
                    {{ purchaseOrder.status_label }}
                  </span>
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Fournisseur</label>
                <p class="mb-0">{{ purchaseOrder.supplier?.name || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Date de commande</label>
                <p class="mb-0">{{ formatDate(purchaseOrder.order_date) }}</p>
              </div>
              
              <div class="col-md-6" v-if="purchaseOrder.expected_delivery_date">
                <label class="form-label text-muted">Date de livraison prévue</label>
                <p class="mb-0">{{ formatDate(purchaseOrder.expected_delivery_date) }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Créé par</label>
                <p class="mb-0">{{ purchaseOrder.user?.name || 'Non renseigné' }}</p>
              </div>
            </div>

            <!-- Notes -->
            <div v-if="purchaseOrder.notes" class="mt-4 pt-3 border-top">
              <label class="form-label text-muted">Notes</label>
              <div class="bg-light p-3 rounded">
                <p class="mb-0">{{ purchaseOrder.notes }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- État de la commande -->
        <div class="card mb-4" v-if="receiptSummary">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0">État de la commande</h5>
            <span class="badge" :class="getStatusBadgeClass(purchaseOrder.status)">
              {{ purchaseOrder.status_label }}
            </span>
          </div>
          <div class="card-body">
            <div class="row g-3 mb-3">
              <div class="col-6 col-md-3">
                <div class="text-muted small">Commandé</div>
                <div class="fs-5 fw-semibold">{{ receiptSummary.totals.ordered }}</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-muted small">Livré</div>
                <div class="fs-5 fw-semibold text-success">{{ receiptSummary.totals.delivered }}</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-muted small">Restant</div>
                <div class="fs-5 fw-semibold text-primary">{{ receiptSummary.totals.remaining }}</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-muted small">Progression</div>
                <div class="fs-5 fw-semibold">{{ receiptSummary.progress_percent }} %</div>
              </div>
            </div>

            <div class="progress mb-3" style="height: 1.25rem;">
              <div
                class="progress-bar"
                :class="receiptSummary.is_fully_delivered ? 'bg-success' : 'bg-primary'"
                role="progressbar"
                :style="{ width: `${Math.min(receiptSummary.progress_percent, 100)}%` }"
                :aria-valuenow="receiptSummary.progress_percent"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                {{ receiptSummary.progress_percent }} %
              </div>
            </div>

            <div v-if="receiptSummary.is_fully_delivered" class="alert alert-success mb-0">
              <i class="bi bi-check-circle me-1"></i>
              Commande entièrement livrée
            </div>
            <div v-else-if="receiptSummary.can_create_delivery && canCreateDeliveryNote" class="d-grid d-md-block">
              <Link
                :href="route('delivery-notes.create', { purchase_order_id: purchaseOrder.id })"
                class="btn btn-primary"
              >
                <i class="bi bi-plus-circle me-1"></i>
                Créer un bon de livraison
              </Link>
            </div>
          </div>
        </div>

        <!-- Articles du bon de commande -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Articles commandés</h5>
          </div>
          <div class="card-body">
            <div v-if="!purchaseOrder.items || purchaseOrder.items.length === 0" class="text-center py-4 text-muted">
              <i class="bi bi-cart-x fs-1 mb-3"></i>
              <p>Aucun article trouvé pour ce bon de commande.</p>
            </div>
            
            <div v-else class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Produit</th>
                    <th>Commandé</th>
                    <th>Livré</th>
                    <th>Restant</th>
                    <th>Prix unitaire</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in purchaseOrder.items" :key="item.id">
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
                      <span class="badge bg-secondary">{{ item.ordered_quantity ?? item.quantity }}</span>
                    </td>
                    <td class="align-middle">
                      <span class="badge bg-success">{{ item.delivered_quantity ?? 0 }}</span>
                    </td>
                    <td class="align-middle">
                      <span class="badge bg-primary">{{ item.remaining_quantity ?? item.quantity }}</span>
                    </td>
                    <td class="align-middle">{{ formatCurrency(item.unit_price) }}</td>
                    <td class="align-middle fw-medium text-success">{{ formatCurrency(item.total_price) }}</td>
                  </tr>
                </tbody>
              </table>
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
            <span v-if="purchaseOrder.attachments?.length" class="badge bg-secondary">
              {{ purchaseOrder.attachments.length }}
            </span>
          </div>
          <div class="card-body">
            <AttachmentList
              v-if="purchaseOrder.attachments?.length"
              :attachments="purchaseOrder.attachments"
              :allow-delete="canUpdatePurchaseOrder"
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
                <span class="fw-medium">{{ formatCurrency(purchaseOrder.subtotal) }}</span>
              </div>
              <div class="d-flex justify-content-between mb-2" v-if="purchaseOrder.tax_amount > 0">
                <span class="text-muted">Taxes</span>
                <span class="fw-medium">{{ formatCurrency(purchaseOrder.tax_amount) }}</span>
              </div>
              <div class="d-flex justify-content-between mb-2" v-if="purchaseOrder.discount_amount > 0">
                <span class="text-muted">Remise</span>
                <span class="fw-medium text-danger">-{{ formatCurrency(purchaseOrder.discount_amount) }}</span>
              </div>
              <hr>
              <div class="d-flex justify-content-between">
                <span class="fw-bold">Total</span>
                <span class="fw-bold text-success fs-5">{{ formatCurrency(purchaseOrder.total_amount) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Bons de livraison associés -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-clipboard-check me-1"></i>
              Bons de livraison ({{ purchaseOrder.delivery_notes?.length || 0 }})
            </h5>
          </div>
          <div class="card-body">
            <div v-if="!purchaseOrder.delivery_notes || purchaseOrder.delivery_notes.length === 0" class="text-center py-4 text-muted">
              <i class="bi bi-inbox fs-1 mb-3"></i>
              <p>Aucun bon de livraison pour ce bon de commande.</p>
              <Link
                v-if="receiptSummary?.can_create_delivery && canCreateDeliveryNote"
                :href="route('delivery-notes.create', { purchase_order_id: purchaseOrder.id })"
                class="btn btn-primary btn-sm"
              >
                <i class="bi bi-plus-circle me-1"></i>
                Créer un bon de livraison
              </Link>
            </div>
            
            <div v-else>
              <div
                v-for="dn in purchaseOrder.delivery_notes"
                :key="dn.id"
                class="border rounded p-3 mb-3"
              >
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                  <div>
                    <div class="fw-semibold font-monospace">{{ dn.delivery_number }}</div>
                    <div class="text-muted small">{{ formatDate(dn.delivery_date) }}</div>
                  </div>
                  <span class="badge" :class="getDeliveryStatusBadgeClass(dn.status)">
                    {{ dn.status_label || dn.status }}
                  </span>
                </div>
                <div class="mt-2">
                  <span class="badge bg-secondary me-2">{{ dn.total_quantity ?? 0 }} unité(s)</span>
                  <span class="text-success fw-medium">{{ formatCurrency(dn.total_amount) }}</span>
                </div>
                <div class="mt-3">
                  <Link
                    :href="route('delivery-notes.show', { id: dn.id })"
                    class="btn btn-sm btn-outline-primary"
                  >
                    <i class="bi bi-eye me-1"></i>
                    Voir
                  </Link>
                </div>
              </div>
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
                v-if="canPreviewPurchaseOrder"
                type="button"
                class="btn btn-outline-primary"
                :disabled="isPreviewing || isDownloading || isPrinting"
                @click="previewPurchaseOrder"
              >
                <span v-if="isPreviewing" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-eye me-1"></i>
                {{ isPreviewing ? 'Génération...' : 'Aperçu' }}
              </button>
              <button
                @click="downloadPurchaseOrder"
                class="btn btn-primary"
                :disabled="isDownloading || isPrinting"
              >
                <span v-if="isDownloading" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-download me-1"></i>
                {{ isDownloading ? 'Téléchargement...' : 'Télécharger le BC' }}
              </button>
              <button
                @click="printPurchaseOrder"
                class="btn btn-info"
                :disabled="isPrinting || isDownloading"
              >
                <span v-if="isPrinting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-printer me-1"></i>
                {{ isPrinting ? 'Ouverture...' : 'Imprimer le BC' }}
              </button>
              <Link
                :href="route('purchase-orders.edit', { id: purchaseOrder.id })"
                class="btn btn-outline-primary"
                :class="{ 'disabled': isDownloading || isPrinting }"
                :tabindex="(isDownloading || isPrinting) ? -1 : 0"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier le bon de commande
              </Link>
              <button
                @click="deletePurchaseOrder"
                class="btn btn-outline-danger"
                :disabled="isDownloading || isPrinting"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer le bon de commande
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AttachmentList from '@/components/attachments/AttachmentList.vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useDocumentPdfPreview } from '@/composables/useDocumentPdfPreview'
import { useDocumentPreview } from '@/composables/useDocumentPreview'
import { usePermissions } from '@/composables/usePermissions'
import type { AttachmentRecord } from '@/types/attachment'
import { ref, computed } from 'vue'
import { formatCurrency } from '@/utils/currencyFormatter'

import type { PurchaseOrderReceiptSummary } from '@/composables/usePurchaseOrderReceipt'

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

interface PurchaseOrderItem {
  id: number
  product?: Product
  quantity: number
  ordered_quantity?: number
  delivered_quantity?: number
  remaining_quantity?: number
  unit_price: number
  total_price: number
}

interface DeliveryNoteSummary {
  id: number
  delivery_number: string
  delivery_date: string
  total_amount: number
  total_quantity?: number
  status: string
  status_label?: string
}

interface PurchaseOrder {
  id: number
  po_number: string
  supplier?: any
  user?: any
  order_date: string
  expected_delivery_date?: string
  status: string
  status_label?: string
  notes?: string
  subtotal: number
  tax_amount: number
  discount_amount: number
  total_amount: number
  items?: PurchaseOrderItem[]
  delivery_notes?: DeliveryNoteSummary[]
  attachments?: AttachmentRecord[]
}

interface Props {
  purchaseOrder: PurchaseOrder
  receiptSummary?: PurchaseOrderReceiptSummary | null
  canUpdatePurchaseOrder?: boolean
}

const props = defineProps<Props>()

const canUpdatePurchaseOrder = computed(() => props.canUpdatePurchaseOrder ?? false)

const { success, error, confirm } = useSweetAlert()
const { openFromUrl } = useDocumentPdfPreview()
const { openAttachment } = useDocumentPreview()
const { canAny } = usePermissions()
const canCreateDeliveryNote = canAny('delivery-notes', ['create'])
const canPreviewPurchaseOrder = canAny('purchase-orders', ['print', 'create', 'update'])

const isDownloading = ref(false)
const isPrinting = ref(false)
const isPreviewing = ref(false)

const openAttachmentPreview = (attachment: AttachmentRecord) => {
  void openAttachment(attachment, `Aperçu — ${attachment.original_name}`)
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const getStatusBadgeClass = (status: string) => {
  const classes: Record<string, string> = {
    draft: 'bg-secondary',
    sent: 'bg-info',
    confirmed: 'bg-warning',
    partially_received: 'bg-primary',
    received: 'bg-success',
    cancelled: 'bg-danger'
  }
  return classes[status] || 'bg-secondary'
}

const getDeliveryStatusBadgeClass = (status: string) => {
  const classes: Record<string, string> = {
    pending: 'bg-warning text-dark',
    validated: 'bg-success',
    cancelled: 'bg-danger',
  }
  return classes[status] || 'bg-secondary'
}

const previewPurchaseOrder = async () => {
  if (isPreviewing.value) {
    return
  }

  isPreviewing.value = true

  try {
    await openFromUrl(
      route('purchase-orders.print', { purchaseOrder: props.purchaseOrder.id }),
      `BC_${props.purchaseOrder.po_number}.pdf`,
    )
  } finally {
    isPreviewing.value = false
  }
}

const downloadPurchaseOrder = async () => {
  isDownloading.value = true
  try {
    const url = route('purchase-orders.download', { purchaseOrder: props.purchaseOrder.id })
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
    let filename = `Bon_de_commande_${props.purchaseOrder.po_number}.pdf`
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
    
    success('Bon de commande téléchargé avec succès !')
  } catch (err) {
    error('Erreur lors du téléchargement du bon de commande.')
  } finally {
    isDownloading.value = false
  }
}

const printPurchaseOrder = async () => {
  isPrinting.value = true
  try {
    const url = route('purchase-orders.print', { purchaseOrder: props.purchaseOrder.id })
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
    error('Erreur lors de l\'ouverture du bon de commande pour impression.')
  } finally {
    isPrinting.value = false
  }
}

const deletePurchaseOrder = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le bon de commande "${props.purchaseOrder.po_number}" ?`)
  
  if (confirmed) {
    router.delete(route('purchase-orders.destroy', { id: props.purchaseOrder.id }), {
      onSuccess: () => {
        success(`Bon de commande "${props.purchaseOrder.po_number}" supprimé avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression du bon de commande.'
        error(errorMessage)
      }
    })
  }
}
</script>

