<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Vente {{ sale.sale_number }}</h1>
        <p class="text-muted mb-0">Détails de la vente</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('sales.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <div class="row g-4">
      <!-- Informations de la vente -->
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations générales</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Numéro de vente</label>
                <p class="mb-0 font-monospace">{{ sale.sale_number }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Date</label>
                <p class="mb-0">{{ formatDateTime(sale.created_at) }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Client</label>
                <p class="mb-0">
                  <Link
                    v-if="sale.customer?.id"
                    :href="route('customers.show', { id: sale.customer.id })"
                    class="btn btn-sm btn-outline-primary"
                  >
                    <i class="bi bi-person me-1"></i>
                    {{ sale.customer.name }}
                  </Link>
                  <span v-else class="text-muted">Client anonyme</span>
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Vendeur</label>
                <p class="mb-0">{{ sale.user?.name || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Mode de paiement</label>
                <p class="mb-0">
                  <span class="badge bg-info">{{ getPaymentMethodLabel(sale.payment_method) }}</span>
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Montant total</label>
                <p class="mb-0 fw-bold text-success fs-5">{{ formatCurrency(sale.total_amount) }}</p>
              </div>
              
              <div v-if="sale.due_date" class="col-md-6">
                <label class="form-label text-muted">Date d'échéance</label>
                <p class="mb-0">{{ formatDate(sale.due_date) }}</p>
              </div>
            </div>

            <!-- Notes -->
            <div v-if="sale.notes" class="mt-4 pt-3 border-top">
              <label class="form-label text-muted">Notes</label>
              <div class="bg-light p-3 rounded">
                <p class="mb-0">{{ sale.notes }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Articles de la vente -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Articles vendus</h5>
          </div>
          <div class="card-body">
            <div v-if="!sale.saleItems || sale.saleItems.length === 0" class="text-center py-4 text-muted">
              <i class="bi bi-cart-x fs-1 mb-3"></i>
              <p>Aucun article trouvé pour cette vente.</p>
            </div>
            
            <div v-else class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Produit</th>
                    <th>Catégorie</th>
                    <th>Quantité</th>
                    <th>Prix unitaire</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in sale.saleItems" :key="item.id">
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
                          <div class="text-muted small">{{ item.product?.sku || 'N/A' }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="align-middle">
                      <span
                        v-if="item.product?.category"
                        class="badge"
                        :style="{ backgroundColor: item.product.category.color + '20', color: item.product.category.color }"
                      >
                        {{ item.product.category.name }}
                      </span>
                      <span v-else class="badge bg-secondary">
                        Catégorie supprimée
                      </span>
                    </td>
                    <td class="align-middle">
                      <span class="fw-medium">{{ item.quantity }}</span>
                      <span class="text-muted ms-1">{{ item.product?.unit || 'pièce' }}</span>
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
            <div class="d-flex justify-content-between mb-2">
              <span>Sous-total:</span>
              <span class="fw-medium">{{ formatCurrency(sale.subtotal || sale.total_amount) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Taxes:</span>
              <span class="fw-medium">{{ formatCurrency(sale.tax_amount || 0) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Remise:</span>
              <span class="fw-medium">{{ formatCurrency(sale.discount_amount || 0) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Total encaissé:</span>
              <span class="fw-medium text-primary">{{ formatCurrency(paymentSummary.total_collected) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Solde restant:</span>
              <span
                class="fw-medium"
                :class="paymentSummary.remaining_balance > 0 ? 'text-warning' : 'text-success'"
              >
                {{ formatCurrency(paymentSummary.remaining_balance) }}
              </span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Statut de paiement:</span>
              <span class="fw-medium">
                <span :class="getPaymentStatusClass(paymentSummary.payment_status || 'pending')">
                  {{ getPaymentStatusLabel(paymentSummary.payment_status || 'pending') }}
                </span>
              </span>
            </div>
            <hr>
            <div class="d-flex justify-content-between mb-3">
              <span class="fw-bold">Total:</span>
              <span class="fw-bold text-success fs-5">{{ formatCurrency(sale.total_amount) }}</span>
            </div>
          </div>
        </div>

        <SalePaymentHistory
          v-if="canViewPaymentHistory"
          :transactions="paymentTransactions"
          :ledger-blocked-message="paymentLedgerBlockedMessage"
          :can-void-payment="canVoidPayment"
        />

        <!-- Actions rapides -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Actions rapides</h5>
          </div>
          <div class="card-body">
            <div v-if="paymentLedgerBlockedMessage" class="alert alert-warning py-2 small mb-3">
              {{ paymentLedgerBlockedMessage }}
            </div>
            <div class="d-grid gap-2">
              <button
                v-if="canRecordPayment"
                type="button"
                class="btn btn-success"
                @click="openCollectModal"
              >
                <i class="bi bi-cash-stack me-1"></i>
                Encaisser
              </button>
              <button
                v-if="canPreviewInvoice"
                type="button"
                class="btn btn-outline-primary"
                :disabled="isPreviewing || isDownloading || isPrinting"
                @click="previewInvoice"
              >
                <span v-if="isPreviewing" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-eye me-1"></i>
                {{ isPreviewing ? 'Génération...' : 'Aperçu' }}
              </button>
              <button
                @click="downloadInvoice"
                class="btn btn-success"
                :disabled="isDownloading || isPrinting"
              >
                <span v-if="isDownloading" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-download me-1"></i>
                {{ isDownloading ? 'Téléchargement...' : 'Télécharger la facture' }}
              </button>
              <button
                @click="printInvoice"
                class="btn btn-info"
                :disabled="isPrinting || isDownloading"
              >
                <span v-if="isPrinting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-printer me-1"></i>
                {{ isPrinting ? 'Ouverture...' : 'Imprimer la facture' }}
              </button>
              <Link
                :href="route('sales.edit', { id: sale.id })"
                class="btn btn-outline-primary"
                :class="{ 'disabled': isDownloading || isPrinting }"
                :tabindex="(isDownloading || isPrinting) ? -1 : 0"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier la vente
              </Link>
              <button
                @click="deleteSale"
                class="btn btn-outline-danger"
                :disabled="isDownloading || isPrinting"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer la vente
              </button>
            </div>
          </div>
        </div>

        <!-- Statistiques -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Statistiques</h5>
          </div>
          <div class="card-body">
            <div class="row g-3 text-center">
              <div class="col-6">
                <div class="p-2 bg-light rounded">
                  <div class="fw-bold text-primary">{{ sale.items_count || sale.saleItems?.length || 0 }}</div>
                  <div class="small text-muted">Articles</div>
                </div>
              </div>
              <div class="col-6">
                <div class="p-2 bg-light rounded">
                  <div class="fw-bold text-success">{{ formatCurrency(sale.total_amount) }}</div>
                  <div class="small text-muted">Montant</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <SalePaymentCollectModal
      :show="collectModalOpen"
      :sale-id="sale.id"
      :max-amount="paymentSummary.remaining_balance"
      @close="collectModalOpen = false"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SalePaymentCollectModal from '@/components/sales/SalePaymentCollectModal.vue'
import SalePaymentHistory, { type SalePaymentTransactionRow } from '@/components/sales/SalePaymentHistory.vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useDocumentPdfPreview } from '@/composables/useDocumentPdfPreview'
import { usePermissions } from '@/composables/usePermissions'
import { computed, ref } from 'vue'
import { formatDate, formatDateTime } from '@/utils/dateFormatter'
import { formatCurrency } from '@/utils/currencyFormatter'
import { getSalePaymentMethodLabel, getSalePaymentStatusLabel } from '@/utils/salePaymentLabels'

const { success, error, confirm } = useSweetAlert()
const { openFromUrl } = useDocumentPdfPreview()
const { canAny } = usePermissions()
const canPreviewInvoice = canAny('sales', ['invoice', 'create', 'update'])

const isDownloading = ref(false)
const isPrinting = ref(false)
const isPreviewing = ref(false)
const collectModalOpen = ref(false)

interface Category {
  id: number
  name: string
  color: string
}

interface Product {
  id: number
  name: string
  sku: string
  unit: string
  category: Category
  image_url?: string | null
}

interface SaleItem {
  id: number
  quantity: number
  unit_price: number
  total_price: number
  product: Product
}

interface User {
  id: number
  name: string
}

interface Customer {
  id: number
  name: string
}

interface Sale {
  id: number
  sale_number: string
  total_amount: number
  subtotal?: number
  tax_amount?: number
  discount_amount?: number
  down_payment_amount?: number
  remaining_amount?: number
  payment_status?: string
  payment_method: string
  status?: string
  created_at: string
  notes?: string
  customer?: Customer
  user?: User
  saleItems?: SaleItem[]
  items_count?: number
}

interface PaymentSummary {
  total_amount: number
  total_collected: number
  remaining_balance: number
  payment_status?: string | null
}

interface Props {
  sale: Sale
  payment_summary?: PaymentSummary
  can_record_payment?: boolean
  can_view_payment_history?: boolean
  can_void_payment?: boolean
  payment_ledger_blocked_message?: string | null
  payment_transactions?: SalePaymentTransactionRow[]
}

const props = withDefaults(defineProps<Props>(), {
  can_record_payment: false,
  can_view_payment_history: false,
  can_void_payment: false,
  payment_ledger_blocked_message: null,
  payment_transactions: () => [],
})

const paymentSummary = computed<PaymentSummary>(() => {
  if (props.payment_summary) {
    return props.payment_summary
  }

  return {
    total_amount: props.sale.total_amount,
    total_collected: props.sale.down_payment_amount ?? 0,
    remaining_balance: props.sale.remaining_amount ?? props.sale.total_amount,
    payment_status: props.sale.payment_status ?? 'pending',
  }
})

const canRecordPayment = computed(() => props.can_record_payment)
const canViewPaymentHistory = computed(() => props.can_view_payment_history)
const canVoidPayment = computed(() => props.can_void_payment)
const paymentTransactions = computed(() => props.payment_transactions ?? [])
const paymentLedgerBlockedMessage = computed(() => props.payment_ledger_blocked_message)

const getPaymentMethodLabel = getSalePaymentMethodLabel
const getPaymentStatusLabel = getSalePaymentStatusLabel

const openCollectModal = () => {
  collectModalOpen.value = true
}

const getPaymentStatusClass = (status: string) => {
  const classes: Record<string, string> = {
    'paid': 'badge bg-success',
    'partial': 'badge bg-warning',
    'pending': 'badge bg-danger'
  }
  return classes[status] || 'badge bg-secondary'
}

const deleteSale = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer la vente "${props.sale.sale_number}" ?`)
  
  if (confirmed) {
    router.delete(route('sales.destroy', { id: props.sale.id }), {
      onSuccess: () => {
        success(`Vente "${props.sale.sale_number}" supprimée avec succès !`)
      },
      onError: (errors) => {
        // En cas d'erreur 422, afficher le message d'erreur du serveur
        const errorMessage = errors.message || 'Erreur lors de la suppression de la vente.'
        error(errorMessage)
      }
    })
  }
}

const previewInvoice = async () => {
  if (isPreviewing.value) {
    return
  }

  isPreviewing.value = true

  try {
    await openFromUrl(
      route('sales.invoice.print', { sale: props.sale.id }),
      `Facture_${props.sale.sale_number}.pdf`,
    )
  } finally {
    isPreviewing.value = false
  }
}

const downloadInvoice = async () => {
  if (isDownloading.value) return
  
  isDownloading.value = true
  
  try {
    const url = route('sales.invoice.download', { sale: props.sale.id })
    
    // Utiliser fetch pour télécharger le fichier avec le spinner
    const response = await fetch(url)
    
    if (!response.ok) {
      throw new Error('Erreur lors du téléchargement')
    }
    
    const blob = await response.blob()
    const downloadUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = downloadUrl
    link.download = `facture_${props.sale.sale_number}.pdf`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(downloadUrl)
    
    // Désactiver le spinner après un court délai
    setTimeout(() => {
      isDownloading.value = false
    }, 500)
  } catch (err) {
    isDownloading.value = false
    error('Erreur lors du téléchargement de la facture')
  }
}

const printInvoice = async () => {
  if (isPrinting.value) return
  
  isPrinting.value = true
  
  try {
    const url = route('sales.invoice.print', { sale: props.sale.id })
    
    // Utiliser fetch pour charger le PDF
    const response = await fetch(url)
    
    if (!response.ok) {
      throw new Error('Erreur lors du chargement')
    }
    
    const blob = await response.blob()
    const printUrl = window.URL.createObjectURL(blob)
    const newWindow = window.open(printUrl, '_blank')
    
    if (!newWindow) {
      // Si le popup est bloqué, utiliser window.location
      window.location.href = url
    } else {
      // Attendre que la fenêtre soit chargée avant d'imprimer
      newWindow.onload = () => {
        setTimeout(() => {
          newWindow.print()
          isPrinting.value = false
        }, 500)
      }
    }
    
    // Désactiver le spinner après un délai de sécurité
    setTimeout(() => {
      isPrinting.value = false
    }, 3000)
  } catch (err) {
    isPrinting.value = false
    error('Erreur lors de l\'ouverture de la facture')
  }
}
</script>