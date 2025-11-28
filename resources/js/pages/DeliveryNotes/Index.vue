<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-clipboard-check me-2"></i>
          Bons de livraison
        </h1>
        <p class="text-muted mb-0">Gérez vos bons de livraison fournisseurs</p>
      </div>
      <Link
        :href="route('delivery-notes.create')"
        class="btn btn-primary"
      >
        <i class="bi bi-plus-circle me-1"></i>
        Nouveau bon de livraison
      </Link>
    </div>

    <!-- Statistiques -->
    <div class="row mb-4">
      <div class="col-md-4">
        <div class="card bg-success text-white">
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <div>
                <h6 class="card-title">Total BL validés</h6>
                <h4 class="mb-0">{{ formatCurrency(statistics.total_validated_amount) }}</h4>
              </div>
              <div class="align-self-center">
                <i class="bi bi-check-circle fs-1"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card bg-info text-white">
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <div>
                <h6 class="card-title">Nombre total</h6>
                <h4 class="mb-0">{{ deliveryNotes.total }}</h4>
              </div>
              <div class="align-self-center">
                <i class="bi bi-clipboard-data fs-1"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card bg-primary text-white">
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <div>
                <h6 class="card-title">BL validés</h6>
                <h4 class="mb-0">{{ validatedCount }}</h4>
              </div>
              <div class="align-self-center">
                <i class="bi bi-clipboard-check fs-1"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filtres -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Recherche</label>
            <input
              v-model="filters.search"
              type="text"
              placeholder="Numéro de BL ou fournisseur..."
              class="form-control"
              @input="search"
            />
          </div>
          <div class="col-md-3">
            <label class="form-label">Statut</label>
            <select
              v-model="filters.status"
              class="form-select"
              @change="search"
            >
              <option value="">Tous les statuts</option>
              <option value="pending">En attente</option>
              <option value="validated">Validé</option>
              <option value="cancelled">Annulé</option>
            </select>
          </div>
          <div class="col-md-2 d-flex align-items-end">
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

    <!-- Tableau des bons de livraison -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Numéro</th>
              <th>Fournisseur</th>
              <th>Date de livraison</th>
              <th>Facture</th>
              <th>Montant total</th>
              <th>Articles</th>
              <th>Statut</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="dn in deliveryNotes.data" :key="dn.id">
              <td>
                <code class="text-dark">{{ dn.delivery_number }}</code>
              </td>
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-2">
                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                      <i class="bi bi-truck text-muted"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ dn.supplier?.name || 'N/A' }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="small">
                  <div class="fw-medium">{{ formatDate(dn.delivery_date) }}</div>
                </div>
              </td>
              <td>
                <code v-if="dn.invoice_number" class="text-info">{{ dn.invoice_number }}</code>
                <span v-else class="text-muted">-</span>
              </td>
              <td>
                <div class="fw-medium text-success">{{ formatCurrency(dn.total_amount) }}</div>
              </td>
              <td>
                <span class="badge bg-light text-dark">{{ dn.items?.length || 0 }} article(s)</span>
              </td>
              <td>
                <span class="badge" :class="getStatusBadgeClass(dn.status)">
                  {{ dn.status_label }}
                </span>
              </td>
              <td class="text-end">
                <div class="btn-group" role="group">
                  <Link
                    :href="route('delivery-notes.show', { id: dn.id })"
                    class="btn btn-sm btn-outline-primary"
                    title="Voir"
                  >
                    <i class="bi bi-eye"></i>
                  </Link>
                  <Link
                    v-if="dn.status === 'pending'"
                    :href="route('delivery-notes.edit', { id: dn.id })"
                    class="btn btn-sm btn-outline-warning"
                    title="Modifier"
                  >
                    <i class="bi bi-pencil"></i>
                  </Link>
                  <button
                    v-if="dn.status === 'pending'"
                    @click="validateDeliveryNote(dn)"
                    class="btn btn-sm btn-outline-success"
                    title="Valider"
                  >
                    <i class="bi bi-check-circle"></i>
                  </button>
                  <button
                    v-if="dn.status === 'pending'"
                    @click="deleteDeliveryNote(dn)"
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
      <div v-if="deliveryNotes.links" class="card-footer">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-none d-md-block">
            <p class="text-muted mb-0">
              Affichage de
              <span class="fw-medium">{{ deliveryNotes.from }}</span>
              à
              <span class="fw-medium">{{ deliveryNotes.to }}</span>
              sur
              <span class="fw-medium">{{ deliveryNotes.total }}</span>
              résultats
            </p>
          </div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <template v-for="link in deliveryNotes.links" :key="link.label">
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
import { route } from '@/lib/routes'
import { ref, computed } from 'vue'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface DeliveryNote {
  id: number
  delivery_number: string
  supplier?: any
  delivery_date: string
  invoice_number?: string
  total_amount: number
  status: string
  status_label?: string
  items?: any[]
}

interface PaginatedDeliveryNotes {
  data: DeliveryNote[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number
  to: number
  links: Array<{
    url: string | null
    label: string
    active: boolean
  }>
}

interface Props {
  deliveryNotes: PaginatedDeliveryNotes
  filters: {
    search?: string
    status?: string
  }
  statistics: {
    total_validated_amount: number
  }
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

const filters = ref({ ...props.filters })

// Computed pour les statistiques
const validatedCount = computed(() => {
  return props.deliveryNotes.data.filter(dn => dn.status === 'validated').length
})

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const getStatusBadgeClass = (status: string) => {
  const classes: Record<string, string> = {
    pending: 'bg-warning',
    validated: 'bg-success',
    cancelled: 'bg-danger'
  }
  return classes[status] || 'bg-secondary'
}

const search = () => {
  router.get(route('delivery-notes.index'), filters.value, {
    preserveState: true,
    replace: true
  })
}

const clearFilters = () => {
  filters.value = {}
  search()
}

const validateDeliveryNote = async (dn: DeliveryNote) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir valider le bon de livraison "${dn.delivery_number}" ? Le stock sera ajusté.`)
  
  if (confirmed) {
    router.post(route('delivery-notes.validate', { id: dn.id }), {}, {
      onSuccess: () => {
        success(`Bon de livraison "${dn.delivery_number}" validé et stock ajusté avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la validation du bon de livraison.'
        error(errorMessage)
      }
    })
  }
}

const deleteDeliveryNote = async (dn: DeliveryNote) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le bon de livraison "${dn.delivery_number}" ?`)
  
  if (confirmed) {
    router.delete(route('delivery-notes.destroy', { id: dn.id }), {
      onSuccess: () => {
        success(`Bon de livraison "${dn.delivery_number}" supprimé avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression du bon de livraison.'
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

