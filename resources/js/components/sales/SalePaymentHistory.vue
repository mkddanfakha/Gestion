<template>
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="card-title mb-0">Règlements enregistrés</h5>
      <span v-if="transactions.length > 0" class="badge bg-secondary">{{ transactions.length }}</span>
    </div>
    <div class="card-body">
      <div v-if="ledgerBlockedMessage" class="alert alert-warning mb-3">
        {{ ledgerBlockedMessage }}
      </div>

      <div v-if="transactions.length === 0" class="text-muted text-center py-3">
        Aucun règlement enregistré pour cette vente.
      </div>

      <div v-else class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Montant</th>
              <th>Mode</th>
              <th>Référence</th>
              <th>Origine</th>
              <th>Statut</th>
              <th v-if="canVoidPayment" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="transaction in transactions"
              :key="transaction.id"
              :class="{ 'table-secondary': isVoided(transaction) }"
            >
              <td class="align-middle">{{ formatPaidAt(transaction.paid_at) }}</td>
              <td
                class="align-middle fw-semibold"
                :class="isVoided(transaction) ? 'text-muted text-decoration-line-through' : 'text-success'"
              >
                {{ formatCurrency(transaction.amount) }}
              </td>
              <td class="align-middle">{{ transaction.payment_method_label }}</td>
              <td class="align-middle text-muted">{{ transaction.reference || '—' }}</td>
              <td class="align-middle">
                <span
                  class="badge"
                  :class="transaction.origin === 'historical_backfill' ? 'bg-secondary' : 'bg-primary'"
                >
                  {{ transaction.origin_label }}
                </span>
              </td>
              <td class="align-middle">
                <span v-if="isVoided(transaction)" class="badge bg-danger" :title="transaction.void_reason || undefined">
                  Annulé
                </span>
                <span v-else class="badge bg-success">Actif</span>
              </td>
              <td v-if="canVoidPayment" class="align-middle text-end">
                <button
                  v-if="canVoidRow(transaction)"
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="openVoidModal(transaction)"
                >
                  Annuler
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div
      v-if="voidModalOpen && voidTarget"
      ref="voidModalRootRef"
      class="modal fade show d-block"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      aria-labelledby="sale-payment-void-title"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form @submit.prevent="submitVoid">
            <div class="modal-header">
              <h5 id="sale-payment-void-title" class="modal-title">Annuler un règlement</h5>
              <button type="button" class="btn-close" aria-label="Fermer" :disabled="voidForm.processing" @click="closeVoidModal"></button>
            </div>
            <div class="modal-body">
              <p class="text-muted small mb-3">
                Montant :
                <span class="fw-semibold">{{ formatCurrency(voidTarget.amount) }}</span>
                — cette action recalcule le solde de la vente.
              </p>
              <div class="mb-0">
                <label class="form-label" for="void-reason">Motif d'annulation <span class="text-danger">*</span></label>
                <textarea
                  id="void-reason"
                  v-model="voidForm.void_reason"
                  rows="3"
                  maxlength="1000"
                  class="form-control"
                  required
                  minlength="3"
                  :class="{ 'is-invalid': voidForm.errors.void_reason }"
                  :disabled="voidForm.processing"
                ></textarea>
                <div v-if="voidForm.errors.void_reason" class="invalid-feedback d-block">
                  {{ voidForm.errors.void_reason }}
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" :disabled="voidForm.processing" @click="closeVoidModal">
                Fermer
              </button>
              <button type="submit" class="btn btn-danger" :disabled="voidForm.processing || !canSubmitVoid">
                <span v-if="voidForm.processing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                Confirmer l'annulation
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div v-if="voidModalOpen" class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatDateTime } from '@/utils/dateFormatter'

export interface SalePaymentTransactionRow {
  id: number
  amount: number
  paid_at: string | null
  payment_method: string
  payment_method_label: string
  reference?: string | null
  origin: string
  origin_label: string
  status?: string
  void_reason?: string | null
  voided_at?: string | null
}

const props = defineProps<{
  transactions: SalePaymentTransactionRow[]
  ledgerBlockedMessage?: string | null
  canVoidPayment?: boolean
}>()

const voidModalOpen = ref(false)
const voidTarget = ref<SalePaymentTransactionRow | null>(null)
const voidModalRootRef = ref<HTMLElement | null>(null)

const voidForm = useForm({
  void_reason: '',
})

const canVoidPayment = computed(() => props.canVoidPayment === true)

const canSubmitVoid = computed(() => voidForm.void_reason.trim().length >= 3)

function isVoided(transaction: SalePaymentTransactionRow): boolean {
  return transaction.status === 'voided'
}

function canVoidRow(transaction: SalePaymentTransactionRow): boolean {
  return !isVoided(transaction)
}

function formatPaidAt(value: string | null): string {
  if (!value) {
    return '—'
  }

  return formatDateTime(value)
}

function openVoidModal(transaction: SalePaymentTransactionRow): void {
  voidTarget.value = transaction
  voidForm.clearErrors()
  voidForm.void_reason = ''
  voidModalOpen.value = true
}

function closeVoidModal(): void {
  if (voidForm.processing) {
    return
  }
  voidModalOpen.value = false
  voidTarget.value = null
  voidForm.reset()
}

function submitVoid(): void {
  if (!voidTarget.value || !canSubmitVoid.value) {
    return
  }

  voidForm.post(route('sales.payments.void', { payment: voidTarget.value.id }), {
    preserveScroll: true,
    onSuccess: () => {
      closeVoidModal()
    },
  })
}
</script>
