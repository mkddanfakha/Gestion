<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Factures fournisseurs"
        subtitle="Suivi des factures et dettes fournisseurs"
        icon="bi-receipt"
      >
        <template #actions-primary>
          <Link
            v-if="canCreateInvoice"
            :href="route('supplier-invoices.create')"
            class="btn btn-primary"
          >
            <i class="bi bi-plus-circle me-1"></i>
            Nouvelle facture
          </Link>
        </template>
      </PageHeader>

      <section class="page-filters card">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Recherche</label>
              <input
                v-model="filters.search"
                type="search"
                placeholder="N° de facture ou fournisseur..."
                class="form-control"
                @input="search"
              />
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Statut document</label>
              <select v-model="filters.status" class="form-select" @change="search">
                <option value="">Tous</option>
                <option value="draft">Brouillon</option>
                <option value="open">Ouverte</option>
                <option value="cancelled">Annulée</option>
              </select>
            </div>
            <div class="col-12 col-md-2 d-grid d-md-block">
              <label class="form-label d-md-none">Actions</label>
              <button type="button" class="btn btn-outline-secondary w-100" @click="clearFilters">
                <i class="bi bi-x-circle me-1"></i>
                Effacer
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="page-table card">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>N° facture</th>
                <th>Fournisseur</th>
                <th>Date facture</th>
                <th>Échéance</th>
                <th>Document</th>
                <th>Paiement</th>
                <th class="text-end">Total</th>
                <th class="text-end">Réglé</th>
                <th class="text-end">Reste dû</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="supplierInvoices.data.length === 0">
                <td colspan="10" class="text-center text-muted py-4">
                  Aucune facture fournisseur trouvée.
                </td>
              </tr>
              <tr v-for="invoice in supplierInvoices.data" :key="invoice.id">
                <td>
                  <code class="text-dark">{{ invoice.invoice_number }}</code>
                </td>
                <td>{{ invoice.supplier?.name || '—' }}</td>
                <td>{{ formatDateDisplay(invoice.invoice_date) }}</td>
                <td>
                  <span>{{ invoice.due_date ? formatDateDisplay(invoice.due_date) : '—' }}</span>
                  <span
                    v-if="isOverdue(invoice)"
                    class="badge bg-danger ms-1"
                  >
                    En retard
                  </span>
                </td>
                <td>
                  <span class="badge" :class="getDocumentStatusBadgeClass(invoice.status)">
                    {{ getDocumentStatusLabel(invoice.status) }}
                  </span>
                </td>
                <td>
                  <SalePaymentStatusBadge :status="invoice.payment_status" />
                </td>
                <td class="text-end">{{ formatCurrency(Number(invoice.total_amount)) }}</td>
                <td class="text-end">{{ formatCurrency(Number(invoice.paid_amount)) }}</td>
                <td class="text-end" :class="remainingCellClass(invoice)">
                  {{ formatRemaining(invoice) }}
                </td>
                <td class="text-end">
                  <Link
                    :href="route('supplier-invoices.show', { supplierInvoice: invoice.id })"
                    class="btn btn-sm btn-outline-primary"
                    title="Voir"
                  >
                    <i class="bi bi-eye"></i>
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <PagePagination
          :links="supplierInvoices.links"
          :from="supplierInvoices.from"
          :to="supplierInvoices.to"
          :total="supplierInvoices.total"
        />
      </section>
    </IndexPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import IndexPageLayout from '@/components/page/IndexPageLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import PagePagination from '@/components/page/PagePagination.vue'
import SalePaymentStatusBadge from '@/components/sales/SalePaymentStatusBadge.vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { computed, ref } from 'vue'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatDate } from '@/utils/dateFormatter'
import { usePermissions } from '@/composables/usePermissions'
import {
  getDocumentStatusBadgeClass,
  getDocumentStatusLabel,
  isActiveSupplierDebt,
  isSupplierInvoiceOverdue,
} from '@/utils/supplierInvoiceUi'

interface SupplierInvoiceRow {
  id: number
  invoice_number: string
  invoice_date: string
  due_date?: string | null
  status: string
  payment_status: string
  total_amount: number | string
  paid_amount: number | string
  remaining_amount: number | string
  supplier?: { id: number; name: string } | null
}

interface PaginatedInvoices {
  data: SupplierInvoiceRow[]
  from: number
  to: number
  total: number
  links: Array<{ url: string | null; label: string; active: boolean }>
}

const props = defineProps<{
  supplierInvoices: PaginatedInvoices
  filters: {
    search?: string
    status?: string
  }
}>()

const { canCreate } = usePermissions()
const canCreateInvoice = computed(() => canCreate('supplier-invoices'))

const filters = ref({
  search: props.filters.search ?? '',
  status: props.filters.status ?? '',
})

function formatDateDisplay(value: string): string {
  return formatDate(value)
}

function isOverdue(invoice: SupplierInvoiceRow): boolean {
  return isSupplierInvoiceOverdue(invoice.status, invoice.remaining_amount, invoice.due_date ?? undefined)
}

function remainingCellClass(invoice: SupplierInvoiceRow): string {
  if (!isActiveSupplierDebt(invoice.status, invoice.remaining_amount)) {
    return 'text-muted'
  }

  return 'fw-semibold text-warning'
}

function formatRemaining(invoice: SupplierInvoiceRow): string {
  if (!isActiveSupplierDebt(invoice.status, invoice.remaining_amount)) {
    return '—'
  }

  return formatCurrency(Number(invoice.remaining_amount))
}

const search = () => {
  router.get(route('supplier-invoices.index'), filters.value, {
    preserveState: true,
    replace: true,
  })
}

const clearFilters = () => {
  filters.value = { search: '', status: '' }
  search()
}
</script>
