<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-truck me-2"></i>
          Fournisseurs
        </h1>
        <p class="text-muted mb-0">Gérez vos fournisseurs</p>
      </div>
      <div class="d-flex gap-2">
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
          :href="route('suppliers.create')"
          class="btn btn-primary"
          :class="{ 'disabled': isExportingPdf || isExportingExcel }"
          :tabindex="(isExportingPdf || isExportingExcel) ? -1 : 0"
        >
          <i class="bi bi-plus-circle me-1"></i>
          Ajouter un fournisseur
        </Link>
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
              placeholder="Nom, contact, email ou téléphone..."
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
              <option value="active">Actif</option>
              <option value="inactive">Inactif</option>
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

    <!-- Tableau des fournisseurs -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Fournisseur</th>
              <th>Contact</th>
              <th>Adresse</th>
              <th>Statut</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="supplier in suppliers.data" :key="supplier.id">
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-3">
                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                      <i class="bi bi-truck text-muted"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ supplier.name }}</div>
                    <div class="text-muted small">{{ supplier.contact_person || 'Aucun contact' }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="small">
                  <div class="fw-medium">{{ supplier.email || 'Aucun email' }}</div>
                  <div class="text-muted">{{ supplier.phone || supplier.mobile || 'Aucun téléphone' }}</div>
                </div>
              </td>
              <td>
                <div class="small">
                  <div class="fw-medium">{{ supplier.address || 'Aucune adresse' }}</div>
                  <div class="text-muted">
                    {{ supplier.city ? `${supplier.city}` : '' }}
                  </div>
                </div>
              </td>
              <td>
                <span class="badge" :class="supplier.status === 'active' ? 'bg-success' : 'bg-secondary'">
                  {{ supplier.status_label }}
                </span>
              </td>
              <td class="text-end">
                <div class="btn-group" role="group">
                  <Link
                    :href="route('suppliers.show', { id: supplier.id })"
                    class="btn btn-sm btn-outline-primary"
                    title="Voir"
                  >
                    <i class="bi bi-eye"></i>
                  </Link>
                  <Link
                    :href="route('suppliers.edit', { id: supplier.id })"
                    class="btn btn-sm btn-outline-secondary"
                    title="Modifier"
                  >
                    <i class="bi bi-pencil"></i>
                  </Link>
                  <button
                    @click="deleteSupplier(supplier)"
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
      <div v-if="suppliers.links" class="card-footer">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-none d-md-block">
            <p class="text-muted mb-0">
              Affichage de
              <span class="fw-medium">{{ suppliers.from }}</span>
              à
              <span class="fw-medium">{{ suppliers.to }}</span>
              sur
              <span class="fw-medium">{{ suppliers.total }}</span>
              résultats
            </p>
          </div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <template v-for="link in suppliers.links" :key="link.label">
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
import { ref } from 'vue'
import { useSweetAlert } from '@/composables/useSweetAlert'

const isExportingPdf = ref(false)
const isExportingExcel = ref(false)

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
  status: string
  status_label?: string
}

interface PaginatedSuppliers {
  data: Supplier[]
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
  suppliers: PaginatedSuppliers
  filters: {
    search?: string
    status?: string
  }
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

const filters = ref({ ...props.filters })

const search = () => {
  router.get(route('suppliers.index'), filters.value, {
    preserveState: true,
    replace: true
  })
}

const clearFilters = () => {
  filters.value = {}
  search()
}

const deleteSupplier = async (supplier: Supplier) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le fournisseur "${supplier.name}" ?`)
  
  if (confirmed) {
    router.delete(route('suppliers.destroy', { id: supplier.id }), {
      onSuccess: () => {
        success(`Fournisseur "${supplier.name}" supprimé avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression du fournisseur.'
        error(errorMessage)
      }
    })
  }
}

const exportPdf = async () => {
  if (isExportingPdf.value || isExportingExcel.value) return
  
  isExportingPdf.value = true
  
  try {
    let url = route('suppliers.export.pdf')
    const params = new URLSearchParams()
    if (filters.value.search) {
      params.append('search', filters.value.search)
    }
    if (filters.value.status) {
      params.append('status', filters.value.status)
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
    let filename = 'fournisseurs_' + new Date().toISOString().split('T')[0] + '.pdf'
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
    let url = route('suppliers.export.excel')
    const params = new URLSearchParams()
    if (filters.value.search) {
      params.append('search', filters.value.search)
    }
    if (filters.value.status) {
      params.append('status', filters.value.status)
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
    let filename = 'fournisseurs_' + new Date().toISOString().split('T')[0] + '.xlsx'
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

<style scoped>
/* Assurer la visibilité du placeholder en dark mode */
.dark ::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

.dark input::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

/* Assurer la visibilité du placeholder en light mode */
input::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}

.form-control::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}
</style>

