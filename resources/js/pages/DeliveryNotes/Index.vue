<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Bons de livraison"
        subtitle="Gérez vos bons de livraison fournisseurs"
        icon="bi-clipboard-check"
      >
        <template #actions-primary>
          <button type="button" class="btn btn-primary" @click="showCreateModal = true">
            <i class="bi bi-plus-circle me-1"></i>
            Créer un BL
          </button>
        </template>
      </PageHeader>

      <div class="row page-stats">
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

      <section class="page-filters card">
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
      </section>

      <section class="page-table card">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Numéro</th>
                <th>Fournisseur</th>
                <th>Bon de commande</th>
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
                  <Link
                    v-if="dn.purchase_order?.po_number"
                    :href="route('purchase-orders.show', { id: dn.purchase_order.id })"
                    class="text-decoration-none"
                  >
                    <code>{{ dn.purchase_order.po_number }}</code>
                  </Link>
                  <span v-else class="badge bg-light text-muted border">Sans BC</span>
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
                      :disabled="validatingIds.has(dn.id)"
                    >
                      <span v-if="validatingIds.has(dn.id)" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                      <i v-else class="bi bi-check-circle"></i>
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

        <PagePagination
          :links="deliveryNotes.links"
          :from="deliveryNotes.from"
          :to="deliveryNotes.to"
          :total="deliveryNotes.total"
        />
      </section>
    </IndexPageLayout>

    <div
      class="modal fade"
      :class="{ show: showCreateModal }"
      :style="{ display: showCreateModal ? 'block' : 'none' }"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      @click.self="showCreateModal = false"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Comment créer le BL ?</h5>
            <button type="button" class="btn-close" aria-label="Fermer" @click="showCreateModal = false"></button>
          </div>
          <div class="modal-body">
            <div class="d-grid gap-3">
              <Link
                :href="route('delivery-notes.create-from-purchase-order')"
                class="btn btn-outline-primary text-start p-3"
                @click="showCreateModal = false"
              >
                <div class="d-flex align-items-start gap-3">
                  <i class="bi bi-box-seam fs-4"></i>
                  <div>
                    <div class="fw-semibold">Depuis un bon de commande</div>
                    <div class="small text-muted">
                      Recommandé — préremplit automatiquement le BL avec les quantités restantes.
                    </div>
                  </div>
                </div>
              </Link>
              <Link
                :href="route('delivery-notes.create', { standalone: 1 })"
                class="btn btn-outline-secondary text-start p-3"
                @click="showCreateModal = false"
              >
                <div class="d-flex align-items-start gap-3">
                  <i class="bi bi-file-earmark fs-4"></i>
                  <div>
                    <div class="fw-semibold">Sans bon de commande</div>
                    <div class="small text-muted">Pour une livraison exceptionnelle ou sans BC enregistré.</div>
                  </div>
                </div>
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div v-if="showCreateModal" class="modal-backdrop fade show"></div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import IndexPageLayout from '@/components/page/IndexPageLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import PagePagination from '@/components/page/PagePagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { ref, computed } from 'vue'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { formatCurrency } from '@/utils/currencyFormatter'

interface DeliveryNote {
  id: number
  delivery_number: string
  supplier?: any
  purchase_order?: { id: number; po_number: string }
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
const validatingIds = ref<Set<number>>(new Set())
const showCreateModal = ref(false)

// Computed pour les statistiques
const validatedCount = computed(() => {
  return props.deliveryNotes.data.filter(dn => dn.status === 'validated').length
})

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
  if (validatingIds.value.has(dn.id)) {
    return
  }

  const confirmed = await confirm(`Êtes-vous sûr de vouloir valider le bon de livraison "${dn.delivery_number}" ? Le stock sera ajusté.`)
  
  if (confirmed) {
    validatingIds.value = new Set([...validatingIds.value, dn.id])
    router.post(route('delivery-notes.validate', { deliveryNote: dn.id }), {}, {
      onSuccess: () => {
        success(`Bon de livraison "${dn.delivery_number}" validé et stock ajusté avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la validation du bon de livraison.'
        error(errorMessage)
      },
      onFinish: () => {
        const next = new Set(validatingIds.value)
        next.delete(dn.id)
        validatingIds.value = next
      },
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
