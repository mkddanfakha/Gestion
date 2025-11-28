<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Modifier le fournisseur</h1>
        <p class="text-muted mb-0">{{ supplier.name }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('suppliers.show', { id: supplier.id })"
          class="btn btn-outline-primary"
        >
          <i class="bi bi-eye me-1"></i>
          Voir le fournisseur
        </Link>
        <Link
          :href="route('suppliers.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <form>
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <!-- Informations générales -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations générales</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">
                    Nom du fournisseur <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.name || clientErrors.name }"
                    @blur="validateField('name', form.name)"
                    @input="validateField('name', form.name)"
                  />
                  <div v-if="errors.name" class="invalid-feedback">{{ errors.name }}</div>
                  <div v-if="clientErrors.name" class="invalid-feedback">{{ clientErrors.name }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Personne de contact</label>
                  <input
                    v-model="form.contact_person"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.contact_person || clientErrors.contact_person }"
                    @blur="validateField('contact_person', form.contact_person)"
                  />
                  <div v-if="errors.contact_person" class="invalid-feedback">{{ errors.contact_person }}</div>
                  <div v-if="clientErrors.contact_person" class="invalid-feedback">{{ clientErrors.contact_person }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': errors.email || clientErrors.email }"
                    @blur="validateField('email', form.email)"
                    @input="validateField('email', form.email)"
                  />
                  <div v-if="errors.email" class="invalid-feedback">{{ errors.email }}</div>
                  <div v-if="clientErrors.email" class="invalid-feedback">{{ clientErrors.email }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Téléphone</label>
                  <input
                    v-model="form.phone"
                    type="tel"
                    class="form-control"
                    :class="{ 'is-invalid': errors.phone || clientErrors.phone }"
                    @blur="validateField('phone', form.phone)"
                    @input="validateField('phone', form.phone)"
                  />
                  <div v-if="errors.phone" class="invalid-feedback">{{ errors.phone }}</div>
                  <div v-if="clientErrors.phone" class="invalid-feedback">{{ clientErrors.phone }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Mobile</label>
                  <input
                    v-model="form.mobile"
                    type="tel"
                    class="form-control"
                    :class="{ 'is-invalid': errors.mobile || clientErrors.mobile }"
                    @blur="validateField('mobile', form.mobile)"
                    @input="validateField('mobile', form.mobile)"
                  />
                  <div v-if="errors.mobile" class="invalid-feedback">{{ errors.mobile }}</div>
                  <div v-if="clientErrors.mobile" class="invalid-feedback">{{ clientErrors.mobile }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Numéro d'identification fiscale</label>
                  <input
                    v-model="form.tax_id"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.tax_id }"
                  />
                  <div v-if="errors.tax_id" class="invalid-feedback">{{ errors.tax_id }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Adresse -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Adresse</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Adresse</label>
                  <textarea
                    v-model="form.address"
                    rows="3"
                    class="form-control"
                    :class="{ 'is-invalid': errors.address }"
                  ></textarea>
                  <div v-if="errors.address" class="invalid-feedback">{{ errors.address }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Ville</label>
                  <input
                    v-model="form.city"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.city }"
                  />
                  <div v-if="errors.city" class="invalid-feedback">{{ errors.city }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Pays</label>
                  <input
                    v-model="form.country"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.country }"
                  />
                  <div v-if="errors.country" class="invalid-feedback">{{ errors.country }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Notes et statut -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations supplémentaires</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Notes</label>
                  <textarea
                    v-model="form.notes"
                    rows="3"
                    class="form-control"
                    :class="{ 'is-invalid': errors.notes }"
                  ></textarea>
                  <div v-if="errors.notes" class="invalid-feedback">{{ errors.notes }}</div>
                </div>

                <div class="col-12">
                  <label class="form-label">
                    Statut <span class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.status"
                    class="form-select"
                    :class="{ 'is-invalid': errors.status }"
                    required
                  >
                    <option value="active">Actif</option>
                    <option value="inactive">Inactif</option>
                  </select>
                  <div v-if="errors.status" class="invalid-feedback">{{ errors.status }}</div>
                  <div class="form-text">
                    Un fournisseur inactif ne sera pas visible dans les listes
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Boutons -->
          <div class="d-flex gap-2">
            <button
              type="button"
              @click="submit"
              class="btn btn-primary"
              :disabled="processing"
            >
              <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Modification...' : 'Modifier le fournisseur' }}
            </button>
            <Link
              :href="route('suppliers.index')"
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

interface Supplier {
  id: number
  name: string
  contact_person?: string
  email?: string
  phone?: string
  mobile?: string
  address?: string
  city?: string
  country?: string
  tax_id?: string
  notes?: string
  status: string
}

interface Props {
  supplier: Supplier
}

const props = defineProps<Props>()

const { success, error } = useSweetAlert()

const clientErrors = ref<Record<string, string>>({})

const validateForm = () => {
  const errors: Record<string, string> = {}
  
  if (!form.name || form.name.trim().length < 2) {
    errors.name = 'Le nom du fournisseur est requis (minimum 2 caractères)'
  }
  
  if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Adresse email invalide'
  }
  
  if (form.phone && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(form.phone)) {
    errors.phone = 'Numéro de téléphone invalide'
  }
  
  if (form.mobile && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(form.mobile)) {
    errors.mobile = 'Numéro de mobile invalide'
  }
  
  return Object.keys(errors).length === 0 ? null : errors
}

const validateField = (fieldName: string, value: any) => {
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'name':
      if (!value || value.trim().length < 2) {
        errorMessage = 'Le nom du fournisseur est requis (minimum 2 caractères)'
      }
      break
      
    case 'email':
      if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        errorMessage = 'Adresse email invalide'
      }
      break
      
    case 'phone':
      if (value && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(value)) {
        errorMessage = 'Numéro de téléphone invalide'
      }
      break
      
    case 'mobile':
      if (value && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(value)) {
        errorMessage = 'Numéro de mobile invalide'
      }
      break
  }
  
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const form = useForm({
  name: props.supplier.name,
  contact_person: props.supplier.contact_person || '',
  email: props.supplier.email || '',
  phone: props.supplier.phone || '',
  mobile: props.supplier.mobile || '',
  address: props.supplier.address || '',
  city: props.supplier.city || '',
  country: props.supplier.country || '',
  tax_id: props.supplier.tax_id || '',
  notes: props.supplier.notes || '',
  status: props.supplier.status,
})

const submit = () => {
  clientErrors.value = {}
  
  const validationErrors = validateForm()
  
  if (validationErrors) {
    clientErrors.value = validationErrors
    return
  }
  
  form.put(route('suppliers.update', { id: props.supplier.id }), {
    onSuccess: () => {
      success('Fournisseur modifié avec succès !')
      clientErrors.value = {}
    },
    onError: () => {
      error('Erreur lors de la modification du fournisseur.')
    }
  })
}

const { errors, processing } = form
</script>

