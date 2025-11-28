<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">{{ supplier.name }}</h1>
        <p class="text-muted mb-0">Détails du fournisseur</p>
      </div>
      <div>
        <Link
          :href="route('suppliers.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <div class="row g-4">
      <!-- Informations du fournisseur -->
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations générales</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Nom du fournisseur</label>
                <p class="mb-0">{{ supplier.name }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Personne de contact</label>
                <p class="mb-0">{{ supplier.contact_person || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Email</label>
                <p class="mb-0">{{ supplier.email || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Téléphone</label>
                <p class="mb-0">{{ supplier.phone || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Mobile</label>
                <p class="mb-0">{{ supplier.mobile || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Numéro d'identification fiscale</label>
                <p class="mb-0">{{ supplier.tax_id || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Statut</label>
                <p class="mb-0">
                  <span 
                    :class="[
                      'badge',
                      supplier.status === 'active' ? 'bg-success' : 'bg-secondary'
                    ]"
                  >
                    <i :class="['me-1', supplier.status === 'active' ? 'bi bi-check-circle' : 'bi bi-x-circle']"></i>
                    {{ supplier.status_label }}
                  </span>
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Adresse</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label text-muted">Adresse</label>
                <p class="mb-0">{{ supplier.address || 'Non renseignée' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Ville</label>
                <p class="mb-0">{{ supplier.city || 'Non renseignée' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Pays</label>
                <p class="mb-0">{{ supplier.country || 'Non renseigné' }}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="card" v-if="supplier.notes">
          <div class="card-header">
            <h5 class="card-title mb-0">Notes</h5>
          </div>
          <div class="card-body">
            <p class="mb-0">{{ supplier.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="col-lg-4">
        <!-- Informations complémentaires -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations complémentaires</h5>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label text-muted small">Date de création</label>
              <p class="mb-0">{{ formatDate(supplier.created_at) }}</p>
            </div>
            
            <div class="mb-3">
              <label class="form-label text-muted small">Dernière mise à jour</label>
              <p class="mb-0">{{ formatDate(supplier.updated_at) }}</p>
            </div>
          </div>
        </div>

        <!-- Actions rapides -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Actions rapides</h5>
          </div>
          <div class="card-body">
            <div class="d-grid gap-2">
              <Link
                :href="route('suppliers.edit', { id: supplier.id })"
                class="btn btn-primary"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier
              </Link>
              <button
                @click="deleteSupplier"
                class="btn btn-outline-danger"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer le fournisseur
              </button>
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
  status_label?: string
  created_at: string
  updated_at: string
}

interface Props {
  supplier: Supplier
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const deleteSupplier = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le fournisseur "${props.supplier.name}" ?`)
  
  if (confirmed) {
    router.delete(route('suppliers.destroy', { id: props.supplier.id }), {
      onSuccess: () => {
        success(`Fournisseur "${props.supplier.name}" supprimé avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression du fournisseur.'
        error(errorMessage)
      }
    })
  }
}
</script>

