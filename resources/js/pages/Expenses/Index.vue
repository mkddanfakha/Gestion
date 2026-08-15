<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Gestion des dépenses"
        subtitle="Suivez et gérez toutes vos dépenses"
        icon="bi-receipt"
      >
        <template #actions-primary>
          <Link
            :href="route('expenses.create')"
            class="btn btn-primary"
          >
            <i class="bi bi-plus-circle me-1"></i>
            Nouvelle dépense
          </Link>
        </template>
      </PageHeader>

      <div class="row page-stats">
        <div class="col-md-3">
          <div class="card bg-primary text-white">
            <div class="card-body">
              <div class="d-flex justify-content-between">
                <div>
                  <h6 class="card-title">Total dépenses</h6>
                  <h4 class="mb-0">{{ formatCurrency(totalExpenses) }}</h4>
                </div>
                <div class="align-self-center">
                  <i class="bi bi-currency-exchange fs-1"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-info text-white">
            <div class="card-body">
              <div class="d-flex justify-content-between">
                <div>
                  <h6 class="card-title">Ce mois ({{ currentMonthName }})</h6>
                  <h4 class="mb-0">{{ formatCurrency(monthlyExpenses) }}</h4>
                </div>
                <div class="align-self-center">
                  <i class="bi bi-calendar-month fs-1"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-warning text-white">
            <div class="card-body">
              <div class="d-flex justify-content-between">
                <div>
                  <h6 class="card-title">Cette semaine</h6>
                  <h4 class="mb-0">{{ formatCurrency(weeklyExpenses) }}</h4>
                </div>
                <div class="align-self-center">
                  <i class="bi bi-calendar-week fs-1"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-success text-white">
            <div class="card-body">
              <div class="d-flex justify-content-between">
                <div>
                  <h6 class="card-title">Nombre total</h6>
                  <h4 class="mb-0">{{ expenses.meta?.total || expenses.data.length }}</h4>
                </div>
                <div class="align-self-center">
                  <i class="bi bi-list-ul fs-1"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <section class="page-filters card">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Rechercher</label>
              <input
                v-model="searchQuery"
                type="text"
                class="form-control"
                placeholder="Titre, description..."
                @input="debouncedSearch"
              />
            </div>
            <div class="col-md-2">
              <label class="form-label">Catégorie</label>
              <select v-model="selectedCategory" class="form-select" @change="applyFilters">
                <option value="">Toutes les catégories</option>
                <option value="fournitures">Fournitures</option>
                <option value="equipement">Équipement</option>
                <option value="marketing">Marketing</option>
                <option value="transport">Transport</option>
                <option value="formation">Formation</option>
                <option value="maintenance">Maintenance</option>
                <option value="utilities">Services publics</option>
                <option value="autres">Autres</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Méthode de paiement</label>
              <select v-model="selectedPaymentMethod" class="form-select" @change="applyFilters">
                <option value="">Toutes les méthodes</option>
                <option value="cash">Espèces</option>
                <option value="bank_transfer">Virement bancaire</option>
                <option value="credit_card">Carte de crédit</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="orange_money">Orange Money</option>
                <option value="wave">Wave</option>
                <option value="check">Chèque</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Date de début</label>
              <input
                v-model="startDate"
                type="date"
                class="form-control"
                @change="applyFilters"
              />
            </div>
            <div class="col-md-2">
              <label class="form-label">Date de fin</label>
              <input
                v-model="endDate"
                type="date"
                class="form-control"
                @change="applyFilters"
              />
            </div>
            <div class="col-md-1 d-flex align-items-end">
              <button
                @click="clearFilters"
                class="btn btn-outline-secondary"
                title="Effacer les filtres"
              >
                <i class="bi bi-x-circle"></i>
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="page-table card">
        <div v-if="filteredExpenses.length === 0" class="text-center py-5">
          <i class="bi bi-receipt fs-1 text-muted"></i>
          <p class="text-muted mt-3">Aucune dépense trouvée</p>
        </div>
        <div v-else class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Numéro</th>
                <th>Titre</th>
                <th>Catégorie</th>
                <th>Montant</th>
                <th>Méthode de paiement</th>
                <th>Date</th>
                <th>Créé par</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="expense in filteredExpenses" :key="expense.id">
                <td>
                  <span class="badge bg-primary">{{ expense.expense_number }}</span>
                </td>
                <td>
                  <div class="fw-medium">{{ expense.title }}</div>
                  <div v-if="expense.description" class="text-muted small">
                    {{ truncateText(expense.description, 50) }}
                  </div>
                </td>
                <td>
                  <span class="badge bg-info">{{ expense.category_label }}</span>
                </td>
                <td>
                  <span class="fw-bold text-danger">{{ formatCurrency(expense.amount) }}</span>
                </td>
                <td>
                  <span class="badge bg-secondary">{{ expense.payment_method_label }}</span>
                </td>
                <td>
                  <div>{{ formatDate(expense.expense_date) }}</div>
                  <div class="text-muted small">{{ formatTime(expense.created_at) }}</div>
                </td>
                <td>
                  <div class="d-flex align-items-center">
                    <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2">
                      {{ expense.user.name.charAt(0).toUpperCase() }}
                    </div>
                    <div>
                      <div class="fw-medium">{{ expense.user.name }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="btn-group" role="group">
                    <Link
                      :href="route('expenses.show', { id: expense.id })"
                      class="btn btn-sm btn-outline-primary"
                      title="Voir"
                    >
                      <i class="bi bi-eye"></i>
                    </Link>
                    <Link
                      :href="route('expenses.edit', { id: expense.id })"
                      class="btn btn-sm btn-outline-warning"
                      title="Modifier"
                    >
                      <i class="bi bi-pencil"></i>
                    </Link>
                    <button
                      @click="deleteExpense(expense)"
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

        <PagePagination
          :links="expenses.links"
          :from="expenses.from ?? expenses.meta?.from"
          :to="expenses.to ?? expenses.meta?.to"
          :total="expenses.total ?? expenses.meta?.total"
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
import { computed, ref, watch } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { debounce } from 'lodash-es'
import { formatDate, formatTime, getCurrentMonthName } from '@/utils/dateFormatter'
import { formatCurrency } from '@/utils/currencyFormatter'

interface User {
  id: number
  name: string
  email: string
}

interface Expense {
  id: number
  expense_number: string
  title: string
  description?: string
  amount: number
  category: string
  category_label: string
  payment_method: string
  payment_method_label: string
  expense_date: string
  receipt_number?: string
  vendor?: string
  notes?: string
  user: User
  created_at: string
  updated_at: string
}

interface PaginatedExpenses {
  data: Expense[]
  links: any[]
  meta: any
}

interface Statistics {
  total: number
  monthly: number
  weekly: number
}

interface Props {
  expenses: PaginatedExpenses
  statistics: Statistics
}

const props = defineProps<Props>()
const { success, error, confirm } = useSweetAlert()

// Variables réactives pour les filtres
const searchQuery = ref('')
const selectedCategory = ref('')
const selectedPaymentMethod = ref('')
const startDate = ref('')
const endDate = ref('')

// Fonction de recherche avec debounce
const debouncedSearch = debounce(() => {
  applyFilters()
}, 300)

// Computed pour les statistiques (utilise les données du serveur)
const totalExpenses = computed(() => {
  return props.statistics?.total || 0
})

const monthlyExpenses = computed(() => {
  return props.statistics?.monthly || 0
})

const weeklyExpenses = computed(() => {
  return props.statistics?.weekly || 0
})

// Nom du mois en cours
const currentMonthName = computed(() => {
  const monthName = getCurrentMonthName()
  return monthName.charAt(0).toUpperCase() + monthName.slice(1)
})

// Computed pour les dépenses filtrées
const filteredExpenses = computed(() => {
  let filtered = props.expenses.data

  // Filtre par recherche
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(expense =>
      expense.title.toLowerCase().includes(query) ||
      (expense.description && expense.description.toLowerCase().includes(query)) ||
      expense.vendor?.toLowerCase().includes(query) ||
      expense.receipt_number?.toLowerCase().includes(query)
    )
  }

  // Filtre par catégorie
  if (selectedCategory.value) {
    filtered = filtered.filter(expense => expense.category === selectedCategory.value)
  }

  // Filtre par méthode de paiement
  if (selectedPaymentMethod.value) {
    filtered = filtered.filter(expense => expense.payment_method === selectedPaymentMethod.value)
  }

  // Filtre par date
  if (startDate.value) {
    filtered = filtered.filter(expense => expense.expense_date >= startDate.value)
  }

  if (endDate.value) {
    filtered = filtered.filter(expense => expense.expense_date <= endDate.value)
  }

  return filtered
})

// Fonctions utilitaires
const truncateText = (text: string, maxLength: number): string => {
  return text.length > maxLength ? text.substring(0, maxLength) + '...' : text
}

const applyFilters = () => {
  // Les filtres sont appliqués automatiquement via computed
}

const clearFilters = () => {
  searchQuery.value = ''
  selectedCategory.value = ''
  selectedPaymentMethod.value = ''
  startDate.value = ''
  endDate.value = ''
}

const deleteExpense = async (expense: Expense) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer la dépense "${expense.title}" ?`)

  if (confirmed) {
    router.delete(route('expenses.destroy', { id: expense.id }), {
      onSuccess: () => {
        success(`Dépense "${expense.title}" supprimée avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression de la dépense.'
        error(errorMessage)
      }
    })
  }
}
</script>

<style scoped>
.avatar-sm {
  width: 32px;
  height: 32px;
  font-size: 14px;
}

.table th {
  border-top: none;
  font-weight: 600;
  color: var(--color-text-muted);
}

.btn-group .btn {
  border-radius: 0;
}

.btn-group .btn:first-child {
  border-top-left-radius: 0.375rem;
  border-bottom-left-radius: 0.375rem;
}

.btn-group .btn:last-child {
  border-top-right-radius: 0.375rem;
  border-bottom-right-radius: 0.375rem;
}
</style>
