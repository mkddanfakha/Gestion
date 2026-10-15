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
            </tr>
          </thead>
          <tbody>
            <tr v-for="transaction in transactions" :key="transaction.id">
              <td class="align-middle">{{ formatPaidAt(transaction.paid_at) }}</td>
              <td class="align-middle fw-semibold text-success">{{ formatCurrency(transaction.amount) }}</td>
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
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
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
}

defineProps<{
  transactions: SalePaymentTransactionRow[]
  ledgerBlockedMessage?: string | null
}>()

function formatPaidAt(value: string | null): string {
  if (!value) {
    return '—'
  }

  return formatDateTime(value)
}
</script>
