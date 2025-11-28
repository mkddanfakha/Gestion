<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">{{ customer.name }}</h1>
        <p class="text-muted mb-0">Détails du client</p>
      </div>
      <div>
        <Link
          :href="route('customers.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <div class="row g-4">
      <!-- Informations du client -->
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations générales</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Nom complet</label>
                <p class="mb-0">{{ customer.name }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Email</label>
                <p class="mb-0">{{ customer.email || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Téléphone</label>
                <p class="mb-0">{{ customer.phone || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Statut</label>
                <p class="mb-0">
                  <span 
                    :class="[
                      'badge',
                      customer.is_active ? 'bg-success' : 'bg-danger'
                    ]"
                  >
                    <i :class="['me-1', customer.is_active ? 'bi bi-check-circle' : 'bi bi-x-circle']"></i>
                    {{ customer.is_active ? 'Actif' : 'Inactif' }}
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
                <p class="mb-0">{{ customer.address || 'Non renseignée' }}</p>
              </div>
              
              <div class="col-md-4">
                <label class="form-label text-muted">Ville</label>
                <p class="mb-0">{{ customer.city || 'Non renseignée' }}</p>
              </div>
              
              <div class="col-md-4">
                <label class="form-label text-muted">Code postal</label>
                <p class="mb-0">{{ customer.postal_code || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-4">
                <label class="form-label text-muted">Pays</label>
                <p class="mb-0">{{ customer.country || 'Non renseigné' }}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Paramètres commerciaux</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Limite de crédit</label>
                <p class="mb-0">
                  {{ customer.credit_limit ? formatCurrency(customer.credit_limit) : 'Non définie' }}
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Nombre de ventes</label>
                <p class="mb-0">{{ customer.sales_count || 0 }} vente(s)</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="col-lg-4">
        <!-- Dernières ventes -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Dernières ventes</h5>
          </div>
          <div class="card-body">
            <div v-if="customer.sales && customer.sales.length > 0">
              <div 
                v-for="sale in customer.sales.slice(0, 3)" 
                :key="sale.id"
                class="border rounded p-3 mb-3"
              >
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <p class="fw-medium mb-1">{{ sale.sale_number }}</p>
                    <p class="text-muted small mb-0">{{ formatDate(sale.created_at) }}</p>
                  </div>
                  <div class="text-end">
                    <p class="fw-medium text-success mb-1">{{ formatCurrency(sale.total_amount) }}</p>
                    <p class="text-muted small mb-0">{{ getPaymentMethodLabel(sale.payment_method) }}</p>
                  </div>
                </div>
              </div>
            </div>
            
            <div v-else class="text-center py-4">
              <i class="bi bi-cart-x fs-1 text-muted mb-3"></i>
              <p class="text-muted mb-0">Aucune vente enregistrée</p>
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
                :href="route('customers.edit', { id: customer.id })"
                class="btn btn-primary"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier
              </Link>
              <Link
                :href="route('sales.create')"
                class="btn btn-success"
              >
                <i class="bi bi-cart-plus me-1"></i>
                Nouvelle vente
              </Link>
              
              <button
                @click="deleteCustomer"
                class="btn btn-outline-danger"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer le client
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

interface Sale {
  id: number
  sale_number: string
  total_amount: number
  payment_method: string
  created_at: string
}

interface Customer {
  id: number
  name: string
  email?: string
  phone?: string
  address?: string
  city?: string
  postal_code?: string
  country?: string
  credit_limit?: number
  is_active: boolean
  sales_count?: number
  sales?: Sale[]
}

interface Props {
  customer: Customer
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const getPaymentMethodLabel = (method: string) => {
  const labels: Record<string, string> = {
    cash: 'Espèces',
    card: 'Carte',
    transfer: 'Virement'
  }
  return labels[method] || method
}

const deleteCustomer = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le client "${props.customer.name}" ?`)
  
  if (confirmed) {
    router.delete(route('customers.destroy', { id: props.customer.id }), {
      onSuccess: () => {
        success(`Client "${props.customer.name}" supprimé avec succès !`)
      },
      onError: (errors) => {
        // En cas d'erreur 422, afficher le message d'erreur du serveur
        const errorMessage = errors.message || 'Erreur lors de la suppression du client.'
        error(errorMessage)
      }
    })
  }
}
</script>
