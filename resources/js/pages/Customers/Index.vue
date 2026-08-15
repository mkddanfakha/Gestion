<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Clients"
        subtitle="Gérez votre base de clients"
        icon="bi-people"
      >
        <template #actions-primary>
          <Link
            :href="route('customers.create')"
            class="btn btn-primary"
            :class="{ 'disabled': isExportingPdf || isExportingExcel }"
            :tabindex="(isExportingPdf || isExportingExcel) ? -1 : 0"
          >
            <i class="bi bi-plus-circle me-1"></i>
            Ajouter un client
          </Link>
        </template>

        <template #actions-secondary>
          <button
            @click="exportPdf"
            class="btn btn-danger"
            :disabled="isExportingPdf || isExportingExcel"
          >
            <span v-if="isExportingPdf" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
            <i v-else class="bi bi-file-pdf me-1"></i>
            {{ isExportingPdf ? 'Export en cours...' : 'Exporter PDF' }}
          </button>
          <button
            @click="exportExcel"
            class="btn btn-success"
            :disabled="isExportingExcel || isExportingPdf"
          >
            <span v-if="isExportingExcel" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
            <i v-else class="bi bi-file-excel me-1"></i>
            {{ isExportingExcel ? 'Export en cours...' : 'Exporter Excel' }}
          </button>
          <Link
            :href="route('customers.potential-duplicates')"
            class="btn btn-outline-warning page-header__action--wide"
          >
            <i class="bi bi-people-fill me-1"></i>
            Doublons potentiels
          </Link>
        </template>
      </PageHeader>

      <section class="page-filters card">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Recherche</label>
              <input
                v-model="filters.search"
                type="search"
                placeholder="Nom, email, téléphone ou n° de pièce..."
                class="form-control"
                @input="search"
              />
            </div>
            <div class="col-12 col-md-2 d-grid d-md-block">
              <label class="form-label d-md-none">Actions</label>
              <button
                @click="clearFilters"
                class="btn btn-outline-secondary w-100"
              >
                <i class="bi bi-x-circle me-1"></i>
                Effacer les filtres
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
              <th>Client</th>
              <th>Contact</th>
              <th>Adresse</th>
              <th>Ventes</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="customer in customers.data" :key="customer.id">
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-3">
                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                      <i class="bi bi-person text-muted"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ customer.name }}</div>
                    <div class="text-muted small">Client #{{ customer.id }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="small">
                  <div class="fw-medium">{{ customer.email || 'Aucun email' }}</div>
                  <div class="text-muted">{{ customer.phone || 'Aucun téléphone' }}</div>
                  <div
                    v-if="customer.identity_document_type && customer.identity_document_number"
                    class="text-muted"
                  >
                    {{ getIdentityTypeShort(customer.identity_document_type) }}
                    • {{ maskIdentityNumber(customer.identity_document_number) }}
                  </div>
                </div>
              </td>
              <td>
                <div class="small">
                  <div class="fw-medium">{{ customer.address || 'Aucune adresse' }}</div>
                  <div class="text-muted">
                    {{ customer.city && customer.postal_code ? `${customer.postal_code} ${customer.city}` : '' }}
                  </div>
                </div>
              </td>
              <td>
                <span class="badge bg-light text-dark">{{ customer.sales_count || 0 }} vente(s)</span>
              </td>
              <td class="text-end">
                <div class="btn-group" role="group">
                  <Link
                    :href="route('customers.show', { id: customer.id })"
                    class="btn btn-sm btn-outline-primary"
                    title="Voir"
                  >
                    <i class="bi bi-eye"></i>
                  </Link>
                  <Link
                    :href="route('customers.edit', { id: customer.id })"
                    class="btn btn-sm btn-outline-secondary"
                    title="Modifier"
                  >
                    <i class="bi bi-pencil"></i>
                  </Link>
                  <button
                    @click="deleteCustomer(customer)"
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
        :links="customers.links"
        :from="customers.from"
        :to="customers.to"
        :total="customers.total"
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
import { ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { getIdentityTypeShort, maskIdentityNumber } from '@/utils/customerIdentity'

const isExportingPdf = ref(false)
const isExportingExcel = ref(false)

interface Customer {
  id: number
  name: string
  email?: string
  phone?: string
  identity_document_type?: string | null
  identity_document_number?: string | null
  address?: string
  city?: string
  postal_code?: string
  country?: string
  sales_count?: number
}

interface PaginatedCustomers {
  data: Customer[]
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
  customers: PaginatedCustomers
  filters: {
    search?: string
  }
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

// Suppression de la logique modale; navigation classique

const filters = ref({ ...props.filters })

const search = () => {
  router.get(route('customers.index'), filters.value, {
    preserveState: true,
    replace: true
  })
}

const clearFilters = () => {
  filters.value = {}
  search()
}

const deleteCustomer = async (customer: Customer) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le client "${customer.name}" ?`)
  
  if (confirmed) {
    router.delete(route('customers.destroy', { id: customer.id }), {
      onSuccess: () => {
        success(`Client "${customer.name}" supprimé avec succès !`)
      },
      onError: (errors) => {
        // En cas d'erreur 422, afficher le message d'erreur du serveur
        const errorMessage = errors.message || 'Erreur lors de la suppression du client.'
        error(errorMessage)
      }
    })
  }
}

const exportPdf = async () => {
  if (isExportingPdf.value || isExportingExcel.value) return
  
  isExportingPdf.value = true
  
  try {
    let url = route('customers.export.pdf')
    const params = new URLSearchParams()
    if (filters.value.search) {
      params.append('search', filters.value.search)
    }
    if (params.toString()) {
      url += '?' + params.toString()
    }
    
    const response = await fetch(url)
    
    if (!response.ok) {
      throw new Error('Erreur lors de l\'export')
    }
    
    const blob = await response.blob()
    const downloadUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = downloadUrl
    
    // Extraire le nom du fichier depuis les headers ou utiliser un nom par défaut
    const contentDisposition = response.headers.get('content-disposition')
    let filename = 'clients_' + new Date().toISOString().split('T')[0] + '.pdf'
    if (contentDisposition) {
      // Gérer les formats: filename="file.pdf" ou filename=file.pdf ou filename*=UTF-8''file.pdf
      let filenameMatch = contentDisposition.match(/filename\*=UTF-8''(.+)/i)
      if (filenameMatch) {
        filename = decodeURIComponent(filenameMatch[1])
      } else {
        filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/i)
        if (filenameMatch && filenameMatch[1]) {
          filename = filenameMatch[1].trim()
        }
      }
    }
    
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(downloadUrl)
    
    setTimeout(() => {
      isExportingPdf.value = false
    }, 500)
  } catch (err) {
    isExportingPdf.value = false
    error('Erreur lors de l\'export PDF')
  }
}

const exportExcel = async () => {
  if (isExportingExcel.value || isExportingPdf.value) return
  
  isExportingExcel.value = true
  
  try {
    let url = route('customers.export.excel')
    const params = new URLSearchParams()
    if (filters.value.search) {
      params.append('search', filters.value.search)
    }
    if (params.toString()) {
      url += '?' + params.toString()
    }
    
    const response = await fetch(url)
    
    if (!response.ok) {
      throw new Error('Erreur lors de l\'export')
    }
    
    const blob = await response.blob()
    const downloadUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = downloadUrl
    
    // Extraire le nom du fichier depuis les headers ou utiliser un nom par défaut
    const contentDisposition = response.headers.get('content-disposition')
    let filename = 'clients_' + new Date().toISOString().split('T')[0] + '.xlsx'
    if (contentDisposition) {
      // Gérer les formats: filename="file.xlsx" ou filename=file.xlsx ou filename*=UTF-8''file.xlsx
      let filenameMatch = contentDisposition.match(/filename\*=UTF-8''(.+)/i)
      if (filenameMatch) {
        filename = decodeURIComponent(filenameMatch[1])
      } else {
        filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/i)
        if (filenameMatch && filenameMatch[1]) {
          filename = filenameMatch[1].trim()
        }
      }
    }
    
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(downloadUrl)
    
    setTimeout(() => {
      isExportingExcel.value = false
    }, 500)
  } catch (err) {
    isExportingExcel.value = false
    error('Erreur lors de l\'export Excel')
  }
}
</script>

