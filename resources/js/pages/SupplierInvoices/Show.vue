<template>
  <AppLayout>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <div>
        <h1 class="h2 mb-1">Facture {{ supplierInvoice.invoice_number }}</h1>
        <p class="text-muted mb-0">Fiche facture fournisseur</p>
      </div>
      <Link :href="route('supplier-invoices.index')" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
      </Link>
    </div>

    <div v-if="formErrors.status" class="alert alert-danger">
      {{ formErrors.status }}
    </div>
    <div v-if="formErrors.amount" class="alert alert-danger">
      {{ formErrors.amount }}
    </div>
    <div v-if="formErrors.void_reason" class="alert alert-danger">
      {{ formErrors.void_reason }}
    </div>

    <div class="row g-4">
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h5 class="card-title mb-0">Informations générales</h5>
            <div class="d-flex flex-wrap gap-2">
              <span class="badge" :class="getDocumentStatusBadgeClass(supplierInvoice.status)">
                {{ getDocumentStatusLabel(supplierInvoice.status) }}
              </span>
              <SalePaymentStatusBadge :status="paymentSummary.payment_status" />
              <span v-if="isOverdue" class="badge bg-danger">En retard</span>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Fournisseur</label>
                <p class="mb-0">
                  <Link
                    v-if="supplierInvoice.supplier?.id"
                    :href="route('suppliers.show', { id: supplierInvoice.supplier.id })"
                    class="fw-medium"
                  >
                    {{ supplierInvoice.supplier.name }}
                  </Link>
                  <span v-else>—</span>
                </p>
              </div>
              <div class="col-md-6">
                <label class="form-label text-muted">Date de facture</label>
                <p class="mb-0">{{ formatDateDisplay(supplierInvoice.invoice_date) }}</p>
              </div>
              <div class="col-md-6">
                <label class="form-label text-muted">Date d'échéance</label>
                <p class="mb-0">
                  {{ supplierInvoice.due_date ? formatDateDisplay(supplierInvoice.due_date) : '—' }}
                </p>
              </div>
              <div class="col-md-6">
                <label class="form-label text-muted">Enregistrée par</label>
                <p class="mb-0">{{ supplierInvoice.user?.name || '—' }}</p>
              </div>
              <div class="col-md-6" v-if="supplierInvoice.purchase_order">
                <label class="form-label text-muted">Bon de commande</label>
                <p class="mb-0">
                  <Link
                    :href="route('purchase-orders.show', { id: supplierInvoice.purchase_order.id })"
                    class="font-monospace"
                  >
                    {{ supplierInvoice.purchase_order.po_number }}
                  </Link>
                </p>
              </div>
              <div class="col-md-6" v-if="supplierInvoice.delivery_note">
                <label class="form-label text-muted">Bon de livraison</label>
                <p class="mb-0">
                  <Link
                    :href="route('delivery-notes.show', { id: supplierInvoice.delivery_note.id })"
                    class="font-monospace"
                  >
                    {{ supplierInvoice.delivery_note.delivery_number }}
                  </Link>
                </p>
              </div>
            </div>
            <div v-if="supplierInvoice.notes" class="mt-4 pt-3 border-top">
              <label class="form-label text-muted">Notes</label>
              <div class="bg-light p-3 rounded">
                <p class="mb-0">{{ supplierInvoice.notes }}</p>
              </div>
            </div>
          </div>
        </div>

        <SupplierInvoicePaymentHistory
          v-if="canViewPaymentHistory"
          :transactions="paymentTransactions"
          :ledger-blocked-message="paymentLedgerBlockedMessage"
          :can-void-payment="canVoidPayment"
        />
      </div>

      <div class="col-lg-4">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Montants</h5>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-between mb-2">
              <span>Total facture</span>
              <span class="fw-medium">{{ formatCurrency(paymentSummary.total_amount) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Total réglé</span>
              <span class="fw-medium text-primary">{{ formatCurrency(paymentSummary.total_paid) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Reste dû</span>
              <span
                class="fw-medium"
                :class="activeDebt ? (paymentSummary.remaining_balance > 0 ? 'text-warning' : 'text-success') : 'text-muted'"
              >
                {{ activeDebt ? formatCurrency(paymentSummary.remaining_balance) : '—' }}
              </span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Statut de paiement</span>
              <SalePaymentStatusBadge :status="paymentSummary.payment_status" />
            </div>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Actions</h5>
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
                @click="collectModalOpen = true"
              >
                <i class="bi bi-cash-stack me-1"></i>
                Enregistrer un règlement
              </button>
              <button
                v-if="canOpenInvoice"
                type="button"
                class="btn btn-primary"
                :disabled="opening"
                @click="confirmOpen"
              >
                <span v-if="opening" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="bi bi-unlock me-1"></i>
                Ouvrir la facture
              </button>
              <p v-if="supplierInvoice.status === 'draft'" class="text-muted small mb-0">
                Tant que la facture est en brouillon, elle ne constitue pas une dette active.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <SupplierInvoicePaymentCollectModal
      :show="collectModalOpen"
      :supplier-invoice-id="supplierInvoice.id"
      :max-amount="paymentSummary.remaining_balance"
      @close="collectModalOpen = false"
      @success="onPaymentRecorded"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SalePaymentStatusBadge from '@/components/sales/SalePaymentStatusBadge.vue'
import SupplierInvoicePaymentCollectModal from '@/components/supplier-invoices/SupplierInvoicePaymentCollectModal.vue'
import SupplierInvoicePaymentHistory, {
  type SupplierInvoicePaymentTransactionRow,
} from '@/components/supplier-invoices/SupplierInvoicePaymentHistory.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { computed, ref } from 'vue'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatDate } from '@/utils/dateFormatter'
import { useSweetAlert } from '@/composables/useSweetAlert'
import {
  getDocumentStatusBadgeClass,
  getDocumentStatusLabel,
  isActiveSupplierDebt,
  isSupplierInvoiceOverdue,
} from '@/utils/supplierInvoiceUi'

interface PaymentSummary {
  total_amount: number
  total_paid: number
  remaining_balance: number
  payment_status: string
  document_status: string
}

interface SupplierInvoiceDetail {
  id: number
  invoice_number: string
  invoice_date: string
  due_date?: string | null
  status: string
  notes?: string | null
  supplier?: { id: number; name: string } | null
  user?: { id: number; name: string } | null
  purchase_order?: { id: number; po_number: string } | null
  delivery_note?: { id: number; delivery_number: string } | null
}

const props = defineProps<{
  supplierInvoice: SupplierInvoiceDetail
  paymentSummary: PaymentSummary
  canOpenInvoice: boolean
  canRecordPayment: boolean
  canViewPaymentHistory: boolean
  canVoidPayment: boolean
  paymentTransactions: SupplierInvoicePaymentTransactionRow[]
  paymentLedgerBlockedMessage?: string | null
}>()

const page = usePage()
const { confirm, success, error } = useSweetAlert()
const opening = ref(false)
const collectModalOpen = ref(false)

const formErrors = computed(() => (page.props.errors as Record<string, string>) ?? {})

const activeDebt = computed(() =>
  isActiveSupplierDebt(props.supplierInvoice.status, props.paymentSummary.remaining_balance),
)

const isOverdue = computed(() =>
  isSupplierInvoiceOverdue(
    props.supplierInvoice.status,
    props.paymentSummary.remaining_balance,
    props.supplierInvoice.due_date ?? undefined,
  ),
)

function formatDateDisplay(value: string): string {
  return formatDate(value)
}

async function confirmOpen(): Promise<void> {
  const ok = await confirm(
    'Confirmer l\'ouverture de cette facture ? Elle deviendra une dette fournisseur active et pourra recevoir des règlements.',
  )

  if (!ok) {
    return
  }

  opening.value = true

  router.post(route('supplier-invoices.open', { supplierInvoice: props.supplierInvoice.id }), {}, {
    preserveScroll: true,
    onSuccess: () => {
      success('Facture fournisseur ouverte.')
    },
    onError: () => {
      error('Impossible d\'ouvrir la facture. Vérifiez les messages affichés.')
    },
    onFinish: () => {
      opening.value = false
    },
  })
}

function onPaymentRecorded(): void {
  success('Règlement enregistré avec succès.')
}
</script>
