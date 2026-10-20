<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Créances clients"
        subtitle="Clients avec un solde dû sur leurs ventes"
        icon="bi-cash-stack"
      >
        <template #actions-secondary>
          <Link :href="route('customers.index')" class="btn btn-outline-secondary">
            <i class="bi bi-people me-1"></i>
            Tous les clients
          </Link>
        </template>
      </PageHeader>

      <section v-if="unreliableExcludedSalesCount && unreliableExcludedSalesCount > 0" class="mb-3">
        <div class="alert alert-warning mb-0" role="alert">
          <i class="bi bi-exclamation-triangle me-2"></i>
          {{ unreliableExcludedSalesCount }} vente(s) non fiabilisée(s) (acompte historique ou désalignement ledger)
          sont exclues du total affiché. Exécutez la reprise des règlements si nécessaire.
        </div>
      </section>

      <section class="page-filters card mb-3">
        <div class="card-body py-3">
          <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
              <label class="form-label">Recherche</label>
              <input
                v-model="filters.search"
                type="search"
                placeholder="Nom, email, téléphone ou n° de pièce..."
                class="form-control"
                @input="search"
              />
            </div>
            <div class="col-12 col-md-3">
              <div class="sale-stat-card h-100">
                <div class="sale-stat-card__icon">
                  <i class="bi bi-wallet2"></i>
                </div>
                <div>
                  <div class="sale-stat-card__value sale-stat-card__value--sm">
                    {{ formatCurrency(portfolioTotal) }}
                  </div>
                  <div class="sale-stat-card__label">Total créances (filtres actifs)</div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-2 d-grid d-md-block">
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
                <th>Client</th>
                <th>Contact</th>
                <th class="text-end">Solde dû</th>
                <th class="text-center">Ventes impayées</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="debtor in debtors.data" :key="debtor.id">
                <td>
                  <div class="fw-medium">{{ debtor.name }}</div>
                  <div class="text-muted small">Client #{{ debtor.id }}</div>
                </td>
                <td>
                  <div class="small">
                    <div>{{ debtor.email || '—' }}</div>
                    <div class="text-muted">{{ debtor.phone || '—' }}</div>
                  </div>
                </td>
                <td class="text-end fw-semibold text-warning">
                  {{ formatCurrency(debtor.remaining_balance) }}
                </td>
                <td class="text-center">
                  <span class="badge bg-light text-dark">{{ debtor.unpaid_sales_count }}</span>
                </td>
                <td class="text-end">
                  <Link
                    :href="route('customers.show', { id: debtor.id })"
                    class="btn btn-sm btn-outline-primary"
                  >
                    <i class="bi bi-eye me-1"></i>
                    Fiche client
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="debtors.data.length === 0" class="card-body text-center text-muted py-5">
          <i class="bi bi-check-circle display-6 d-block mb-2"></i>
          Aucun client débiteur pour ces critères.
        </div>

        <PagePagination
          v-if="debtors.data.length > 0"
          :links="debtors.links"
          :from="debtors.from"
          :to="debtors.to"
          :total="debtors.total"
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
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from '@/lib/routes'
import { formatCurrency } from '@/utils/currencyFormatter'

interface DebtorRow {
  id: number
  name: string
  email?: string | null
  phone?: string | null
  remaining_balance: number
  unpaid_sales_count: number
}

interface PaginatedDebtors {
  data: DebtorRow[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number
  to: number
  prev_page_url: string | null
  next_page_url: string | null
  links: Array<{
    url: string | null
    label: string
    active: boolean
  }>
}

const props = defineProps<{
  debtors: PaginatedDebtors
  portfolioTotal: number
  filters: { search?: string }
  unreliableExcludedSalesCount?: number | null
}>()

const filters = ref({ ...props.filters })

const search = () => {
  router.get(route('customers.receivables'), filters.value, {
    preserveState: true,
    replace: true,
  })
}

const clearFilters = () => {
  filters.value = {}
  search()
}
</script>
