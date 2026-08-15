<template>
  <section class="customer-crm-summary card">
    <div class="card-header">
      <h2 class="card-title h6 mb-0">Résumé commercial</h2>
    </div>
    <div class="card-body">
      <dl class="customer-crm-summary__list">
        <div class="customer-crm-summary__item">
          <dt>Dernière vente</dt>
          <dd>{{ lastSaleLabel }}</dd>
        </div>
        <div class="customer-crm-summary__item">
          <dt>Dernière visite</dt>
          <dd>Non disponible</dd>
        </div>
        <div class="customer-crm-summary__item">
          <dt>Factures impayées</dt>
          <dd>
            <button
              type="button"
              class="btn btn-link btn-sm p-0 align-baseline customer-crm-summary__link"
              :disabled="summary.unpaid_invoices_count === 0"
              @click="emit('show-unpaid')"
            >
              {{ summary.unpaid_invoices_count }}
            </button>
          </dd>
        </div>
        <div class="customer-crm-summary__item">
          <dt>Devis en attente</dt>
          <dd>
            <button
              type="button"
              class="btn btn-link btn-sm p-0 align-baseline customer-crm-summary__link"
              :disabled="summary.pending_quotes_count === 0"
              @click="emit('show-quotes')"
            >
              {{ summary.pending_quotes_count }}
            </button>
          </dd>
        </div>
      </dl>

      <div v-if="unpaidInvoices.length > 0" ref="unpaidRef" class="customer-crm-summary__details mt-4">
        <h3 class="h6 mb-3">Factures impayées</h3>
        <div class="list-group list-group-flush">
          <Link
            v-for="invoice in unpaidInvoices"
            :key="invoice.id"
            :href="route('sales.show', { id: invoice.id })"
            class="list-group-item list-group-item-action customer-crm-summary__invoice"
          >
            <div class="d-flex justify-content-between gap-3 flex-wrap">
              <div>
                <div class="fw-semibold">{{ invoice.sale_number }}</div>
                <div class="small text-muted">
                  Échéance :
                  {{ invoice.due_date ? formatShortDate(invoice.due_date) : 'Non renseignée' }}
                </div>
              </div>
              <div class="text-end">
                <div>{{ formatCurrency(invoice.total_amount) }}</div>
                <div class="small text-danger">
                  Reste : {{ formatCurrency(invoice.remaining_amount) }}
                </div>
              </div>
            </div>
          </Link>
        </div>
      </div>

      <div v-if="pendingQuotes.length > 0" ref="quotesRef" class="customer-crm-summary__details mt-4">
        <h3 class="h6 mb-3">Devis en attente</h3>
        <div class="list-group list-group-flush">
          <Link
            v-for="quote in pendingQuotes"
            :key="quote.id"
            :href="route('quotes.show', { id: quote.id })"
            class="list-group-item list-group-item-action"
          >
            <div class="d-flex justify-content-between gap-3 flex-wrap">
              <div>
                <div class="fw-semibold">{{ quote.quote_number }}</div>
                <div class="small text-muted">{{ getQuoteStatusLabel(quote.status) }}</div>
              </div>
              <div class="text-end">{{ formatCurrency(quote.total_amount) }}</div>
            </div>
          </Link>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatLongDate, formatShortDate } from '@/utils/dateFormatter'

export interface CustomerCrmSummaryProps {
  orders_count: number
  total_purchased: number
  total_paid: number
  remaining_balance: number
  unpaid_invoices_count: number
  pending_quotes_count: number
  last_sale_at?: string | null
}

export interface UnpaidInvoiceItem {
  id: number
  sale_number: string
  total_amount: number
  remaining_amount: number
  due_date?: string | null
}

export interface PendingQuoteItem {
  id: number
  quote_number: string
  total_amount: number
  status: string
}

const props = defineProps<{
  summary: CustomerCrmSummaryProps
  unpaidInvoices: UnpaidInvoiceItem[]
  pendingQuotes: PendingQuoteItem[]
}>()

const emit = defineEmits<{
  'show-unpaid': []
  'show-quotes': []
}>()

const unpaidRef = ref<HTMLElement | null>(null)
const quotesRef = ref<HTMLElement | null>(null)

const lastSaleLabel = computed(() =>
  props.summary.last_sale_at ? formatLongDate(props.summary.last_sale_at) : 'Aucune vente',
)

const getQuoteStatusLabel = (status: string) => {
  const labels: Record<string, string> = {
    draft: 'Brouillon',
    sent: 'Envoyé',
    accepted: 'Accepté',
    rejected: 'Rejeté',
    expired: 'Expiré',
  }

  return labels[status] ?? status
}

defineExpose({
  scrollToUnpaid: () => unpaidRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
  scrollToQuotes: () => quotesRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
})
</script>
