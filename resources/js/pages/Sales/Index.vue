<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-cart-check me-2"></i>
          Ventes
        </h1>
        <p class="text-muted mb-0">Gérez vos ventes et transactions</p>
      </div>
      <Link
        :href="route('sales.create')"
        class="btn btn-success"
      >
        <i class="bi bi-plus-circle me-1"></i>
        Nouvelle vente
      </Link>
    </div>

    <!-- Filtres -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Recherche</label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-search"></i>
              </span>
              <input
                v-model="filters.search"
                type="text"
                placeholder="Nom client ou numéro de vente..."
                class="form-control"
                @input="debouncedSearch"
              />
              <button
                v-if="filters.search"
                @click="clearSearch"
                class="btn btn-outline-secondary"
                type="button"
              >
                <i class="bi bi-x"></i>
              </button>
            </div>
          </div>
          <div class="col-md-2">
            <label class="form-label">Date de début</label>
            <input
              v-model="filters.date_from"
              type="date"
              class="form-control"
              @change="search"
            />
          </div>
          <div class="col-md-2">
            <label class="form-label">Date de fin</label>
            <input
              v-model="filters.date_to"
              type="date"
              class="form-control"
              @change="search"
            />
          </div>
          <div class="col-md-2">
            <label class="form-label">Date d'échéance</label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-calendar-event"></i>
              </span>
              <input
                :key="`due-date-${props.filters.due_date || filters.due_date || 'empty'}`"
                v-model="filters.due_date"
                type="date"
                class="form-control"
                @change="search"
              />
              <button
                v-if="filters.due_date"
                @click="clearDueDateFilter"
                class="btn btn-outline-secondary"
                type="button"
                title="Effacer le filtre de date d'échéance"
              >
                <i class="bi bi-x"></i>
              </button>
            </div>
          </div>
          <div class="col-md-1 d-flex align-items-end">
            <button
              @click="clearFilters"
              class="btn btn-outline-secondary w-100"
            >
              <i class="bi bi-x-circle me-1"></i>
              Effacer
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Tableau des ventes -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Numéro</th>
              <th>Client</th>
              <th>Date</th>
              <th>Montant</th>
              <th>Articles</th>
              <th>Méthode de paiement</th>
              <th>Statut</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sale in sales.data" :key="sale.id">
              <td>
                <code class="text-dark">{{ sale.sale_number }}</code>
              </td>
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-2">
                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                      <i class="bi bi-person text-muted"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ sale.customer?.name || 'Client anonyme' }}</div>
                    <div class="text-muted small">{{ sale.customer?.email || '' }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="small">
                  <div class="fw-medium">{{ formatDate(sale.created_at) }}</div>
                  <div class="text-muted">{{ formatTime(sale.created_at) }}</div>
                </div>
              </td>
              <td>
                <div class="fw-medium text-success">{{ formatCurrency(sale.total_amount) }}</div>
              </td>
              <td>
                <div class="d-flex align-items-center">
                  <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px;">
                    <i class="bi bi-box text-muted small"></i>
                  </div>
                  <span class="fw-medium">{{ sale.items_count || 0 }}</span>
                  <span class="text-muted ms-1 small">article(s)</span>
                </div>
              </td>
              <td>
                <span class="badge bg-light text-dark">
                  <i class="bi bi-credit-card me-1"></i>
                  {{ formatPaymentMethod(sale.payment_method) }}
                </span>
              </td>
              <td>
                <span
                  :class="[
                    'badge',
                    getPaymentStatusClass(sale.payment_status || 'paid')
                  ]"
                >
                  <i 
                    :class="[
                      'me-1',
                      getPaymentStatusIcon(sale.payment_status || 'paid')
                    ]"
                  ></i>
                  {{ getPaymentStatusLabel(sale.payment_status || 'paid') }}
                </span>
              </td>
              <td class="text-end">
                <div class="btn-group" role="group">
                  <Link
                    :href="route('sales.show', { id: sale.id })"
                    class="btn btn-sm btn-outline-primary"
                    title="Voir"
                  >
                    <i class="bi bi-eye"></i>
                  </Link>
                  <Link
                    :href="route('sales.edit', { id: sale.id })"
                    class="btn btn-sm btn-outline-secondary"
                    title="Modifier"
                  >
                    <i class="bi bi-pencil"></i>
                  </Link>
                  <button
                    @click="deleteSale(sale)"
                    class="btn btn-sm btn-outline-danger"
                    title="Supprimer"
                  >
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="sales.links" class="card-footer">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-none d-md-block">
            <p class="text-muted mb-0">
              Affichage de
              <span class="fw-medium">{{ sales.from }}</span>
              à
              <span class="fw-medium">{{ sales.to }}</span>
              sur
              <span class="fw-medium">{{ sales.total }}</span>
              résultats
            </p>
          </div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <template v-for="link in sales.links" :key="link.label">
                <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                  <Link
                    v-if="link.url"
                    :href="link.url"
                    class="page-link"
                    v-html="link.label"
                  />
                  <span
                    v-else
                    class="page-link"
                    v-html="link.label"
                  />
                </li>
              </template>
            </ul>
          </nav>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch, onMounted } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { formatDate, formatTime } from '@/utils/dateFormatter'

interface Sale {
  id: number
  sale_number: string
  total_amount: number
  payment_method: string
  payment_status?: string
  created_at: string
  status: string
  items_count?: number
  customer?: {
    name: string
    email?: string
  }
}

interface PaginatedSales {
  data: Sale[]
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

interface Props {
  sales: PaginatedSales
  filters: {
    date_from?: string
    date_to?: string
    search?: string
    due_date?: string
  }
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

// Initialiser les filtres depuis les props
const filters = ref<{
  date_from?: string
  date_to?: string
  search?: string
  due_date?: string
}>({ ...props.filters })

// Synchroniser les filtres avec les props au montage
onMounted(() => {
  // S'assurer que les filtres sont synchronisés au montage, en priorisant les props
  if (props.filters) {
    // Les props doivent être prioritaires, donc on les met en dernier dans le spread
    filters.value = { ...filters.value, ...props.filters }
  }
})

// Synchroniser les filtres avec les props quand ils changent (pour les liens externes)
watch(() => props.filters, (newFilters) => {
  if (newFilters) {
    // Mettre à jour les filtres depuis les props, en préservant les valeurs locales non définies dans les props
    Object.keys(newFilters).forEach(key => {
      if (newFilters[key] !== undefined && newFilters[key] !== null && newFilters[key] !== '') {
        filters.value[key] = newFilters[key]
      }
    })
  }
}, { deep: true, immediate: true })

import { formatDate, formatTime } from '@/utils/dateFormatter'

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatPaymentMethod = (method: string) => {
  const labels: Record<string, string> = {
    cash: 'Espèces',
    card: 'Carte',
    bank_transfer: 'Virement bancaire',
    check: 'Chèque',
    orange_money: 'Orange Money',
    wave: 'Wave'
  }
  return labels[method] || method
}

const getPaymentStatusLabel = (status: string) => {
  const labels: Record<string, string> = {
    'paid': 'Payé',
    'partial': 'Paiement partiel',
    'pending': 'En attente'
  }
  return labels[status] || status
}

const getPaymentStatusClass = (status: string) => {
  const classes: Record<string, string> = {
    'paid': 'bg-success',
    'partial': 'bg-warning text-dark',
    'pending': 'bg-danger'
  }
  return classes[status] || 'bg-secondary'
}

const getPaymentStatusIcon = (status: string) => {
  const icons: Record<string, string> = {
    'paid': 'bi bi-check-circle',
    'partial': 'bi bi-clock',
    'pending': 'bi bi-exclamation-circle'
  }
  return icons[status] || 'bi bi-question-circle'
}

const getPaymentMethodLabel = (method: string) => {
  return formatPaymentMethod(method)
}

const search = () => {
  router.get(route('sales.index'), filters.value, {
    preserveState: true,
    replace: true
  })
}

// Recherche avec debounce pour éviter trop de requêtes
let searchTimeout: ReturnType<typeof setTimeout> | null = null
const debouncedSearch = () => {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }
  searchTimeout = setTimeout(() => {
    search()
  }, 500) // Attendre 500ms après la dernière frappe
}

const clearFilters = () => {
  filters.value = {}
  search()
}

const clearSearch = () => {
  filters.value.search = ''
  search()
}

const clearDueDateFilter = () => {
  filters.value.due_date = undefined
  search()
}

const deleteSale = async (sale: Sale) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer la vente "${sale.sale_number}" ?`)
  
  if (confirmed) {
    router.delete(route('sales.destroy', { id: sale.id }), {
      onSuccess: () => {
        success(`Vente "${sale.sale_number}" supprimée avec succès !`)
      },
      onError: (errors) => {
        // En cas d'erreur 422, afficher le message d'erreur du serveur
        const errorMessage = errors.message || 'Erreur lors de la suppression de la vente.'
        error(errorMessage)
      }
    })
  }
}
</script>

<style scoped>
/* Visibilité du placeholder */
.dark ::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

.dark input::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

input::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}

.form-control::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}
</style>
