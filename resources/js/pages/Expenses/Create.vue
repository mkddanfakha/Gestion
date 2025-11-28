<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Nouvelle dépense</h1>
        <p class="text-muted mb-0">Enregistrez une nouvelle dépense</p>
      </div>
      <Link
        :href="route('expenses.index')"
        class="btn btn-outline-secondary"
      >
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
      </Link>
    </div>

    <form>
      <!-- Informations générales -->
      <div class="card mb-4">
        <div class="card-header">
          <h5 class="card-title mb-0">
            <i class="bi bi-info-circle me-2"></i>
            Informations générales
          </h5>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label for="title" class="form-label">
                Titre de la dépense <span class="text-danger">*</span>
              </label>
              <input
                v-model="form.title"
                type="text"
                class="form-control"
                :class="{ 'is-invalid': errors.title || clientErrors.title }"
                id="title"
                placeholder="Ex: Achat de fournitures de bureau"
                @input="validateField('title')"
                @blur="validateField('title')"
              />
              <div v-if="errors.title" class="invalid-feedback">
                {{ errors.title }}
              </div>
              <div v-if="clientErrors.title" class="invalid-feedback">
                {{ clientErrors.title }}
              </div>
            </div>
            <div class="col-md-4">
              <label for="amount" class="form-label">
                Montant <span class="text-danger">*</span>
              </label>
              <div class="input-group">
                <input
                  v-model="form.amount"
                  type="number"
                  step="0.01"
                  min="0"
                  class="form-control"
                  :class="{ 'is-invalid': errors.amount || clientErrors.amount }"
                  id="amount"
                  placeholder="0.00"
                  @input="validateField('amount')"
                  @blur="validateField('amount')"
                />
                <span class="input-group-text">Fcfa</span>
              </div>
              <div v-if="errors.amount" class="invalid-feedback">
                {{ errors.amount }}
              </div>
              <div v-if="clientErrors.amount" class="invalid-feedback">
                {{ clientErrors.amount }}
              </div>
            </div>
          </div>
          <div class="row g-3 mt-2">
            <div class="col-md-6">
              <label for="category" class="form-label">
                Catégorie <span class="text-danger">*</span>
              </label>
              <select
                v-model="form.category"
                class="form-select"
                :class="{ 'is-invalid': errors.category || clientErrors.category }"
                id="category"
                @change="validateField('category')"
                @blur="validateField('category')"
              >
                <option value="">Sélectionner une catégorie</option>
                <option value="fournitures">Fournitures</option>
                <option value="equipement">Équipement</option>
                <option value="marketing">Marketing</option>
                <option value="transport">Transport</option>
                <option value="formation">Formation</option>
                <option value="maintenance">Maintenance</option>
                <option value="utilities">Services publics</option>
                <option value="autres">Autres</option>
              </select>
              <div v-if="errors.category" class="invalid-feedback">
                {{ errors.category }}
              </div>
              <div v-if="clientErrors.category" class="invalid-feedback">
                {{ clientErrors.category }}
              </div>
            </div>
            <div class="col-md-6">
              <label for="payment_method" class="form-label">
                Méthode de paiement <span class="text-danger">*</span>
              </label>
              <select
                v-model="form.payment_method"
                class="form-select"
                :class="{ 'is-invalid': errors.payment_method || clientErrors.payment_method }"
                id="payment_method"
                @change="validateField('payment_method')"
                @blur="validateField('payment_method')"
              >
                <option value="">Sélectionner une méthode</option>
                <option value="cash">Espèces</option>
                <option value="bank_transfer">Virement bancaire</option>
                <option value="credit_card">Carte de crédit</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="orange_money">Orange Money</option>
                <option value="wave">Wave</option>
                <option value="check">Chèque</option>
              </select>
              <div v-if="errors.payment_method" class="invalid-feedback">
                {{ errors.payment_method }}
              </div>
              <div v-if="clientErrors.payment_method" class="invalid-feedback">
                {{ clientErrors.payment_method }}
              </div>
            </div>
          </div>
          <div class="row g-3 mt-2">
            <div class="col-md-6">
              <label for="expense_date" class="form-label">
                Date de la dépense <span class="text-danger">*</span>
              </label>
              <input
                v-model="form.expense_date"
                type="date"
                class="form-control"
                :class="{ 'is-invalid': errors.expense_date || clientErrors.expense_date }"
                id="expense_date"
                @change="validateField('expense_date')"
                @blur="validateField('expense_date')"
              />
              <div v-if="errors.expense_date" class="invalid-feedback">
                {{ errors.expense_date }}
              </div>
              <div v-if="clientErrors.expense_date" class="invalid-feedback">
                {{ clientErrors.expense_date }}
              </div>
            </div>
            <div class="col-md-6">
              <label for="vendor" class="form-label">Fournisseur</label>
              <input
                v-model="form.vendor"
                type="text"
                class="form-control"
                :class="{ 'is-invalid': errors.vendor || clientErrors.vendor }"
                id="vendor"
                placeholder="Ex: Magasin ABC"
                @input="validateField('vendor')"
                @blur="validateField('vendor')"
              />
              <div v-if="errors.vendor" class="invalid-feedback">
                {{ errors.vendor }}
              </div>
              <div v-if="clientErrors.vendor" class="invalid-feedback">
                {{ clientErrors.vendor }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Détails supplémentaires -->
      <div class="card mb-4">
        <div class="card-header">
          <h5 class="card-title mb-0">
            <i class="bi bi-file-text me-2"></i>
            Détails supplémentaires
          </h5>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label for="receipt_number" class="form-label">Numéro de reçu/facture</label>
              <input
                v-model="form.receipt_number"
                type="text"
                class="form-control"
                :class="{ 'is-invalid': errors.receipt_number || clientErrors.receipt_number }"
                id="receipt_number"
                placeholder="Ex: FAC-2025-001"
                @input="validateField('receipt_number')"
                @blur="validateField('receipt_number')"
              />
              <div v-if="errors.receipt_number" class="invalid-feedback">
                {{ errors.receipt_number }}
              </div>
              <div v-if="clientErrors.receipt_number" class="invalid-feedback">
                {{ clientErrors.receipt_number }}
              </div>
            </div>
          </div>
          <div class="row g-3 mt-2">
            <div class="col-12">
              <label for="description" class="form-label">Description</label>
              <textarea
                v-model="form.description"
                class="form-control"
                :class="{ 'is-invalid': errors.description || clientErrors.description }"
                id="description"
                rows="3"
                placeholder="Description détaillée de la dépense..."
                @input="validateField('description')"
                @blur="validateField('description')"
              ></textarea>
              <div v-if="errors.description" class="invalid-feedback">
                {{ errors.description }}
              </div>
              <div v-if="clientErrors.description" class="invalid-feedback">
                {{ clientErrors.description }}
              </div>
            </div>
          </div>
          <div class="row g-3 mt-2">
            <div class="col-12">
              <label for="notes" class="form-label">Notes</label>
              <textarea
                v-model="form.notes"
                class="form-control"
                :class="{ 'is-invalid': errors.notes || clientErrors.notes }"
                id="notes"
                rows="2"
                placeholder="Notes additionnelles..."
                @input="validateField('notes')"
                @blur="validateField('notes')"
              ></textarea>
              <div v-if="errors.notes" class="invalid-feedback">
                {{ errors.notes }}
              </div>
              <div v-if="clientErrors.notes" class="invalid-feedback">
                {{ clientErrors.notes }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="card">
        <div class="card-header">
          <h5 class="card-title mb-0">Actions</h5>
        </div>
        <div class="card-body">
          <div class="d-grid gap-2">
            <button
              type="button"
              @click="submit"
              class="btn btn-primary"
              :disabled="processing"
            >
              <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Création...' : 'Créer la dépense' }}
            </button>
            <Link
              :href="route('expenses.index')"
              class="btn btn-outline-secondary"
            >
              Annuler
            </Link>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

const { success, error } = useSweetAlert()

// Form data
const form = useForm({
  title: '',
  description: '',
  amount: 0,
  category: '',
  payment_method: '',
  expense_date: new Date().toISOString().split('T')[0], // Date du jour par défaut
  receipt_number: '',
  vendor: '',
  notes: ''
})

// Client-side validation errors
const clientErrors = ref<Record<string, string>>({})

// Validation functions
const validateRequired = (value: any): boolean => {
  if (typeof value === 'string') {
    return value.trim() !== ''
  }
  if (typeof value === 'number') {
    return value !== null && value !== undefined && value !== 0
  }
  return value !== null && value !== undefined
}

const validateForm = (): Record<string, string> => {
  const errors: Record<string, string> = {}

  if (!validateRequired(form.title)) {
    errors.title = 'Le titre est requis'
  }

  if (!validateRequired(form.amount)) {
    errors.amount = 'Le montant est requis'
  } else if (form.amount < 0) {
    errors.amount = 'Le montant doit être positif'
  }

  if (!validateRequired(form.category)) {
    errors.category = 'La catégorie est requise'
  }

  if (!validateRequired(form.payment_method)) {
    errors.payment_method = 'La méthode de paiement est requise'
  }

  if (!validateRequired(form.expense_date)) {
    errors.expense_date = 'La date de dépense est requise'
  }

  return errors
}

const validateField = (fieldName: string) => {
  const value = form[fieldName as keyof typeof form]
  let errorMessage = ''

  switch (fieldName) {
    case 'title':
      if (!validateRequired(value)) {
        errorMessage = 'Le titre est requis'
      }
      break
    case 'amount':
      if (!validateRequired(value)) {
        errorMessage = 'Le montant est requis'
      } else if (form.amount < 0) {
        errorMessage = 'Le montant doit être positif'
      }
      break
    case 'category':
      if (!validateRequired(value)) {
        errorMessage = 'La catégorie est requise'
      }
      break
    case 'payment_method':
      if (!validateRequired(value)) {
        errorMessage = 'La méthode de paiement est requise'
      }
      break
    case 'expense_date':
      if (!validateRequired(value)) {
        errorMessage = 'La date de dépense est requise'
      }
      break
  }

  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  } else {
    delete clientErrors.value[fieldName]
  }
}

const submit = () => {
  // Clear previous client errors
  clientErrors.value = {}
  
  // Validate form
  const validationErrors = validateForm()
  
  if (Object.keys(validationErrors).length > 0) {
    clientErrors.value = validationErrors
    return
  }

  form.post(route('expenses.store'), {
    onSuccess: () => {
      success('Dépense créée avec succès !')
    },
    onError: () => {
      error('Erreur lors de la création de la dépense.')
    }
  })
}

const { errors, processing } = form
</script>

<style scoped>
/* Visibilité du placeholder */
.dark ::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

.dark input::placeholder,
.dark textarea::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

input::placeholder,
textarea::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}

.form-control::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}
</style>