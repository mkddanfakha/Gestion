<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-receipt me-2"></i>
          Détails de la dépense
        </h1>
        <p class="text-muted mb-0">{{ expense.expense_number }}</p>
      </div>
      <div>
        <Link
          :href="route('expenses.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour
        </Link>
      </div>
    </div>

    <div class="row">
      <!-- Informations principales -->
      <div class="col-md-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-info-circle me-2"></i>
              Informations générales
            </h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-medium">Titre</label>
                <div class="form-control-plaintext text-dark-emphasis">{{ expense.title }}</div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium">Montant</label>
                <div class="form-control-plaintext">
                  <span class="fs-5 fw-bold text-danger">{{ formatCurrency(expense.amount) }}</span>
                </div>
              </div>
            </div>
            <div class="row g-3 mt-2">
              <div class="col-md-6">
                <label class="form-label fw-medium">Catégorie</label>
                <div class="form-control-plaintext">
                  <span class="badge bg-info fs-6">{{ expense.category_label }}</span>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium">Méthode de paiement</label>
                <div class="form-control-plaintext">
                  <span class="badge bg-secondary fs-6">{{ expense.payment_method_label }}</span>
                </div>
              </div>
            </div>
            <div class="row g-3 mt-2">
              <div class="col-md-6">
                <label class="form-label fw-medium">Date de la dépense</label>
                <div class="form-control-plaintext text-dark-emphasis">{{ formatDate(expense.expense_date) }}</div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium">Fournisseur</label>
                <div class="form-control-plaintext text-dark-emphasis">{{ expense.vendor || 'Non spécifié' }}</div>
              </div>
            </div>
            <div class="row g-3 mt-2" v-if="expense.receipt_number">
              <div class="col-md-6">
                <label class="form-label fw-medium">Numéro de reçu/facture</label>
                <div class="form-control-plaintext">
                  <code class="text-info">{{ expense.receipt_number }}</code>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Description -->
        <div v-if="expense.description" class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-file-text me-2"></i>
              Description
            </h5>
          </div>
          <div class="card-body">
            <p class="mb-0 text-dark-emphasis">{{ expense.description }}</p>
          </div>
        </div>

        <!-- Notes -->
        <div v-if="expense.notes" class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-sticky me-2"></i>
              Notes
            </h5>
          </div>
          <div class="card-body">
            <p class="mb-0 text-dark-emphasis">{{ expense.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Informations secondaires -->
      <div class="col-md-4">
        <!-- Informations de création -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-person me-2"></i>
              Créé par
            </h5>
          </div>
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="avatar-lg bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3">
                {{ expense.user.name.charAt(0).toUpperCase() }}
              </div>
              <div>
                <div class="fw-medium">{{ expense.user.name }}</div>
                <div class="text-muted small">{{ expense.user.email }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Dates -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-calendar me-2"></i>
              Dates
            </h5>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label fw-medium">Créé le</label>
              <div class="form-control-plaintext">
                {{ formatDateTime(expense.created_at) }}
              </div>
            </div>
            <div>
              <label class="form-label fw-medium">Modifié le</label>
              <div class="form-control-plaintext">
                {{ formatDateTime(expense.updated_at) }}
              </div>
            </div>
          </div>
        </div>

        <!-- Actions rapides -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-lightning me-2"></i>
              Actions rapides
            </h5>
          </div>
          <div class="card-body">
            <div class="d-grid gap-2">
              <Link
                :href="route('expenses.edit', { id: expense.id })"
                class="btn btn-warning"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier
              </Link>
              <button
                @click="deleteExpense"
                class="btn btn-danger"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer
              </button>
              <Link
                :href="route('expenses.index')"
                class="btn btn-outline-secondary"
              >
                <i class="bi bi-list me-1"></i>
                Voir toutes les dépenses
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { formatDate, formatDateTime } from '@/utils/dateFormatter'

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

interface Props {
  expense: Expense
}

const props = defineProps<Props>()
const { success, error, confirm } = useSweetAlert()

// Fonctions utilitaires
const formatCurrency = (amount: number): string => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const deleteExpense = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer la dépense "${props.expense.title}" ?`)

  if (confirmed) {
    router.delete(route('expenses.destroy', { id: props.expense.id }), {
      onSuccess: () => {
        success(`Dépense "${props.expense.title}" supprimée avec succès !`)
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
.avatar-lg {
  width: 48px;
  height: 48px;
  font-size: 18px;
}

.form-control-plaintext {
  padding: 0.375rem 0;
  margin-bottom: 0;
  line-height: 1.5;
  color: #212529;
  background-color: transparent;
  border: solid transparent;
  border-width: 1px 0;
}

.badge {
  font-size: 0.875em;
}

/* Dark mode styles */
:deep(.dark) .form-control-plaintext {
  color: #e2e8f0 !important;
}

:deep(.dark) .text-dark-emphasis {
  color: #e2e8f0 !important;
}

:deep(.dark) .card-body .form-control-plaintext {
  color: #e2e8f0 !important;
}

:deep(.dark) code {
  background-color: #334155 !important;
  color: #60a5fa !important;
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
}

:deep(.dark) .text-muted {
  color: #94a3b8 !important;
}

:deep(.dark) .fw-medium {
  color: #e2e8f0 !important;
}

:deep(.dark) .card-body p {
  color: #e2e8f0 !important;
}
</style>
