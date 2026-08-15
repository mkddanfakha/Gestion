<template>
  <section class="customer-crm-history card">
    <div class="card-header customer-crm-history__tabs-wrap">
      <ul class="nav nav-tabs card-header-tabs customer-crm-history__tabs" role="tablist">
        <li v-for="tab in tabs" :key="tab.key" class="nav-item" role="presentation">
          <button
            type="button"
            class="nav-link"
            :class="{ active: activeTab === tab.key }"
            @click="emit('change-tab', tab.key)"
          >
            {{ tab.label }}
          </button>
        </li>
      </ul>
    </div>

    <div class="card-body">
      <div v-if="activeTab === 'sales'" class="table-responsive">
        <table v-if="sales.data.length" class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Référence</th>
              <th>Montant</th>
              <th>Statut</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sale in sales.data" :key="sale.id">
              <td>{{ formatShortDate(sale.created_at) }}</td>
              <td>{{ sale.sale_number }}</td>
              <td>{{ formatCurrency(sale.total_amount) }}</td>
              <td>
                <span :class="getPaymentStatusBadgeClass(sale.payment_status)">
                  {{ getPaymentStatusLabel(sale.payment_status) }}
                </span>
              </td>
              <td class="text-end">
                <Link :href="route('sales.show', { id: sale.id })" class="btn btn-sm btn-outline-primary">
                  Voir
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else message="Aucune vente" icon="bi-cart-x" />
        <PaginationBar :paginator="sales" @paginate="emit('paginate', $event)" />
      </div>

      <div v-else-if="activeTab === 'payments'" class="table-responsive">
        <table v-if="payments.data.length" class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Document</th>
              <th>Montant</th>
              <th>Mode de paiement</th>
              <th>Référence</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="payment in payments.data" :key="`${payment.reference}-${payment.date}`">
              <td>{{ formatShortDate(payment.date) }}</td>
              <td>{{ payment.document }}</td>
              <td>{{ formatCurrency(payment.amount) }}</td>
              <td>{{ getPaymentMethodLabel(payment.payment_method) }}</td>
              <td>{{ payment.reference }}</td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else message="Aucun paiement" icon="bi-cash-stack" />
        <PaginationBar :paginator="payments" @paginate="emit('paginate', $event)" />
      </div>

      <div v-else-if="activeTab === 'invoices'" class="table-responsive">
        <table v-if="invoices.data.length" class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Numéro</th>
              <th>Date</th>
              <th>Montant</th>
              <th>Payé</th>
              <th>Reste</th>
              <th>Échéance</th>
              <th>Statut</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="invoice in invoices.data" :key="invoice.id">
              <td>{{ invoice.sale_number }}</td>
              <td>{{ formatShortDate(invoice.created_at) }}</td>
              <td>{{ formatCurrency(invoice.total_amount) }}</td>
              <td>{{ formatCurrency(invoice.paid_amount) }}</td>
              <td>{{ formatCurrency(invoice.remaining_amount) }}</td>
              <td>{{ invoice.due_date ? formatShortDate(invoice.due_date) : '—' }}</td>
              <td>
                <span :class="getPaymentStatusBadgeClass(invoice.payment_status)">
                  {{ getPaymentStatusLabel(invoice.payment_status) }}
                </span>
              </td>
              <td class="text-end">
                <Link :href="route('sales.show', { id: invoice.id })" class="btn btn-sm btn-outline-primary">
                  Voir
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else message="Aucune facture" icon="bi-receipt" />
        <PaginationBar :paginator="invoices" @paginate="emit('paginate', $event)" />
      </div>

      <div v-else-if="activeTab === 'quotes'" class="table-responsive">
        <table v-if="quotes.data.length" class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Numéro</th>
              <th>Date</th>
              <th>Montant</th>
              <th>Statut</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="quote in quotes.data" :key="quote.id">
              <td>{{ quote.quote_number }}</td>
              <td>{{ formatShortDate(quote.created_at) }}</td>
              <td>{{ formatCurrency(quote.total_amount) }}</td>
              <td>{{ getQuoteStatusLabel(quote.status) }}</td>
              <td class="text-end">
                <Link :href="route('quotes.show', { id: quote.id })" class="btn btn-sm btn-outline-primary">
                  Voir
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else message="Aucun devis" icon="bi-file-earmark-text" />
        <PaginationBar :paginator="quotes" @paginate="emit('paginate', $event)" />
      </div>

      <div v-else class="customer-crm-history__activity">
        <div v-if="activity.data.length" class="list-group list-group-flush">
          <div v-for="entry in activity.data" :key="entry.id" class="list-group-item customer-crm-history__activity-item">
            <div class="customer-crm-history__activity-date">
              {{ formatLongDate(entry.created_at) }}
            </div>
            <div class="customer-crm-history__activity-text">
              {{ entry.description }}
            </div>
            <div v-if="entry.user_name" class="small text-muted">
              Par {{ entry.user_name }}
            </div>
          </div>
        </div>
        <EmptyState v-else message="Aucune activité" icon="bi-clock-history" />
        <PaginationBar :paginator="activity" @paginate="emit('paginate', $event)" />
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatLongDate, formatShortDate } from '@/utils/dateFormatter'
import {
  getPaymentStatusBadgeClass,
  getPaymentStatusLabel,
  type SalePaymentStatus,
} from '@/utils/salePaymentStatus'
import EmptyState from '@/components/customers/CustomerCrmEmptyState.vue'
import PaginationBar from '@/components/customers/CustomerCrmPagination.vue'

export interface CrmPaginator<T> {
  data: T[]
  links?: Array<{ url: string | null; label: string; active: boolean }>
  from?: number | null
  to?: number | null
  total?: number
}

defineProps<{
  activeTab: string
  sales: CrmPaginator<any>
  payments: CrmPaginator<any>
  invoices: CrmPaginator<any>
  quotes: CrmPaginator<any>
  activity: CrmPaginator<any>
}>()

const emit = defineEmits<{
  'change-tab': [tab: string]
  paginate: [url: string]
}>()

const tabs = [
  { key: 'sales', label: 'Ventes' },
  { key: 'payments', label: 'Paiements' },
  { key: 'invoices', label: 'Factures' },
  { key: 'quotes', label: 'Devis' },
  { key: 'activity', label: 'Activité' },
]

const getPaymentMethodLabel = (method?: string | null) => {
  const labels: Record<string, string> = {
    cash: 'Espèces',
    card: 'Carte',
    bank_transfer: 'Virement',
    check: 'Chèque',
    orange_money: 'Orange Money',
    wave: 'Wave',
  }

  return method ? labels[method] ?? method : '—'
}

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
</script>
