<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-file-earmark-check me-2"></i>
          Devis
        </h1>
        <p class="text-muted mb-0">Gérez vos devis et propositions commerciales</p>
      </div>
      <Link
        :href="route('quotes.create')"
        class="btn btn-primary"
      >
        <i class="bi bi-plus-circle me-1"></i>
        Nouveau devis
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
                placeholder="Nom client ou numéro de devis..."
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
            <label class="form-label">Client</label>
            <select
              v-model="filters.customer_id"
              class="form-select"
              @change="search"
            >
              <option value="">Tous les clients</option>
              <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                {{ customer.name }}
              </option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Statut</label>
            <select
              v-model="filters.status"
              class="form-select"
              @change="search"
            >
              <option value="">Tous les statuts</option>
              <option value="draft">Brouillon</option>
              <option value="sent">Envoyé</option>
              <option value="accepted">Accepté</option>
              <option value="rejected">Refusé</option>
              <option value="expired">Expiré</option>
            </select>
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
          <div class="col-md-1 d-flex align-items-end">
            <button
              @click="clearFilters"
              class="btn btn-outline-secondary w-100"
            >
              <i class="bi bi-x-circle"></i>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Tableau des devis -->
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
              <th>Statut</th>
              <th>Validité</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="quote in quotes.data" :key="quote.id">
              <td>
                <code class="text-dark">{{ quote.quote_number }}</code>
              </td>
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-2">
                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                      <i class="bi bi-person text-muted"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ quote.customer?.name || 'Client anonyme' }}</div>
                    <div class="text-muted small">{{ quote.customer?.email || '' }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="small">
                  <div class="fw-medium">{{ formatDate(quote.created_at) }}</div>
                  <div class="text-muted">{{ formatTime(quote.created_at) }}</div>
                </div>
              </td>
              <td>
                <div class="fw-medium text-success">{{ formatCurrency(quote.total_amount) }}</div>
              </td>
              <td>
                <div class="d-flex align-items-center">
                  <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px;">
                    <i class="bi bi-box text-muted small"></i>
                  </div>
                  <span class="fw-medium">{{ quote.items_count || 0 }}</span>
                  <span class="text-muted ms-1 small">article(s)</span>
                </div>
              </td>
              <td>
                <span
                  :class="[
                    'badge',
                    getStatusClass(quote.status)
                  ]"
                >
                  <i 
                    :class="[
                      'me-1',
                      getStatusIcon(quote.status)
                    ]"
                  ></i>
                  {{ getStatusLabel(quote.status) }}
                </span>
              </td>
              <td>
                <div v-if="quote.valid_until" class="small">
                  <div class="fw-medium">{{ formatDate(quote.valid_until) }}</div>
                  <span 
                    v-if="isExpired(quote.valid_until)"
                    class="badge bg-danger"
                  >
                    Expiré
                  </span>
                  <span 
                    v-else
                    class="badge bg-info"
                  >
                    Valide
                  </span>
                </div>
                <span v-else class="text-muted small">-</span>
              </td>
              <td class="text-end">
                <div class="btn-group" role="group">
                  <Link
                    :href="route('quotes.show', { id: quote.id })"
                    class="btn btn-sm btn-outline-primary"
                    title="Voir"
                  >
                    <i class="bi bi-eye"></i>
                  </Link>
                  <Link
                    :href="route('quotes.edit', { id: quote.id })"
                    class="btn btn-sm btn-outline-secondary"
                    title="Modifier"
                  >
                    <i class="bi bi-pencil"></i>
                  </Link>
                  <button
                    @click="deleteQuote(quote)"
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
      <div v-if="quotes.links" class="card-footer">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-none d-md-block">
            <p class="text-muted mb-0">
              Affichage de
              <span class="fw-medium">{{ quotes.from }}</span>
              à
              <span class="fw-medium">{{ quotes.to }}</span>
              sur
              <span class="fw-medium">{{ quotes.total }}</span>
              résultats
            </p>
          </div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <template v-for="link in quotes.links" :key="link.label">
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
import { ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { formatDate, formatTime } from '@/utils/dateFormatter'

interface Quote {
  id: number
  quote_number: string
  total_amount: number
  status: string
  valid_until?: string
  created_at: string
  items_count?: number
  customer?: {
    name: string
    email?: string
  }
}

interface PaginatedQuotes {
  data: Quote[]
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

interface Customer {
  id: number
  name: string
}

interface Props {
  quotes: PaginatedQuotes
  customers: Customer[]
  filters: {
    customer_id?: number
    status?: string
    date_from?: string
    date_to?: string
    search?: string
  }
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

const filters = ref({ ...props.filters })

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

import { formatDate, formatTime } from '@/utils/dateFormatter'

const getStatusLabel = (status: string) => {
  const labels: Record<string, string> = {
    'draft': 'Brouillon',
    'sent': 'Envoyé',
    'accepted': 'Accepté',
    'rejected': 'Refusé',
    'expired': 'Expiré'
  }
  return labels[status] || status
}

const getStatusClass = (status: string) => {
  const classes: Record<string, string> = {
    'draft': 'bg-secondary',
    'sent': 'bg-info',
    'accepted': 'bg-success',
    'rejected': 'bg-danger',
    'expired': 'bg-warning text-dark'
  }
  return classes[status] || 'bg-secondary'
}

const getStatusIcon = (status: string) => {
  const icons: Record<string, string> = {
    'draft': 'bi bi-file-earmark',
    'sent': 'bi bi-send',
    'accepted': 'bi bi-check-circle',
    'rejected': 'bi bi-x-circle',
    'expired': 'bi bi-clock-history'
  }
  return icons[status] || 'bi bi-question-circle'
}

const isExpired = (validUntil: string | undefined) => {
  if (!validUntil) return false
  return new Date(validUntil) < new Date()
}

const search = () => {
  router.get(route('quotes.index'), filters.value, {
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

const deleteQuote = async (quote: Quote) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le devis "${quote.quote_number}" ?`)
  
  if (confirmed) {
    router.delete(route('quotes.destroy', { id: quote.id }), {
      onSuccess: () => {
        success(`Devis "${quote.quote_number}" supprimé avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression du devis.'
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

