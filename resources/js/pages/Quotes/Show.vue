<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Devis {{ quote.quote_number }}</h1>
        <p class="text-muted mb-0">Détails du devis</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('quotes.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <div class="row g-4">
      <!-- Informations du devis -->
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations générales</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Numéro de devis</label>
                <p class="mb-0 font-monospace">{{ quote.quote_number }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Date</label>
                <p class="mb-0">{{ formatDate(quote.created_at) }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Client</label>
                <p class="mb-0">{{ quote.customer?.name || 'Client anonyme' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Créé par</label>
                <p class="mb-0">{{ quote.user?.name || 'Non renseigné' }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Statut</label>
                <p class="mb-0">
                  <span :class="['badge', getStatusClass(quote.status)]">
                    <i :class="['me-1', getStatusIcon(quote.status)]"></i>
                    {{ getStatusLabel(quote.status) }}
                  </span>
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Montant total</label>
                <p class="mb-0 fw-bold text-success fs-5">{{ formatCurrency(quote.total_amount) }}</p>
              </div>
              
              <div v-if="quote.valid_until" class="col-md-6">
                <label class="form-label text-muted">Date de validité</label>
                <p class="mb-0">
                  {{ formatDate(quote.valid_until) }}
                  <span 
                    v-if="isExpired(quote.valid_until)"
                    class="badge bg-danger ms-2"
                  >
                    Expiré
                  </span>
                  <span 
                    v-else
                    class="badge bg-success ms-2"
                  >
                    Valide
                  </span>
                </p>
              </div>
            </div>

            <!-- Notes -->
            <div v-if="quote.notes" class="mt-4 pt-3 border-top">
              <label class="form-label text-muted">Notes</label>
              <div class="bg-light p-3 rounded">
                <p class="mb-0">{{ quote.notes }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Articles du devis -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Articles du devis</h5>
          </div>
          <div class="card-body">
            <div v-if="!quote.quoteItems || quote.quoteItems.length === 0" class="text-center py-4 text-muted">
              <i class="bi bi-cart-x fs-1 mb-3"></i>
              <p>Aucun article trouvé pour ce devis.</p>
            </div>
            
            <div v-else class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Produit</th>
                    <th>Catégorie</th>
                    <th>Quantité</th>
                    <th>Prix unitaire</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in quote.quoteItems" :key="item.id">
                    <td class="align-middle">
                      <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                          <div 
                            v-if="item.product?.image_url"
                            class="bg-light rounded overflow-hidden d-flex align-items-center justify-content-center"
                            style="width: 60px; height: 60px;"
                          >
                            <img
                              :src="item.product.image_url"
                              :alt="item.product?.name || 'Produit'"
                              class="img-fluid"
                              style="width: 100%; height: 100%; object-fit: cover;"
                            />
                          </div>
                          <div
                            v-else
                            class="bg-light rounded d-flex align-items-center justify-content-center"
                            style="width: 60px; height: 60px;"
                          >
                            <i class="bi bi-box text-muted"></i>
                          </div>
                        </div>
                        <div>
                          <div class="fw-medium">{{ item.product?.name || 'Produit supprimé' }}</div>
                          <div class="text-muted small">{{ item.product?.sku || 'N/A' }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="align-middle">
                      <span
                        v-if="item.product?.category"
                        class="badge"
                        :style="{ backgroundColor: item.product.category.color + '20', color: item.product.category.color }"
                      >
                        {{ item.product.category.name }}
                      </span>
                      <span v-else class="badge bg-secondary">
                        Catégorie supprimée
                      </span>
                    </td>
                    <td class="align-middle">
                      <span class="fw-medium">{{ item.quantity }}</span>
                      <span class="text-muted ms-1">{{ item.product?.unit || 'pièce' }}</span>
                    </td>
                    <td class="align-middle">{{ formatCurrency(item.unit_price) }}</td>
                    <td class="align-middle fw-medium text-success">{{ formatCurrency(item.total_price) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="col-lg-4">
        <!-- Résumé financier -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Résumé financier</h5>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-between mb-2">
              <span>Sous-total:</span>
              <span class="fw-medium">{{ formatCurrency(quote.subtotal || quote.total_amount) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Taxes:</span>
              <span class="fw-medium">{{ formatCurrency(quote.tax_amount || 0) }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span>Remise:</span>
              <span class="fw-medium">{{ formatCurrency(quote.discount_amount || 0) }}</span>
            </div>
            <hr>
            <div class="d-flex justify-content-between mb-3">
              <span class="fw-bold">Total:</span>
              <span class="fw-bold text-success fs-5">{{ formatCurrency(quote.total_amount) }}</span>
            </div>
          </div>
        </div>

        <!-- Actions rapides -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Actions rapides</h5>
          </div>
          <div class="card-body">
            <div class="d-grid gap-2">
              <button
                @click="downloadQuote"
                class="btn btn-success"
                :disabled="isDownloading || isPrinting"
              >
                <span v-if="isDownloading" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-download me-1"></i>
                {{ isDownloading ? 'Téléchargement...' : 'Télécharger le devis' }}
              </button>
              <button
                @click="printQuote"
                class="btn btn-info"
                :disabled="isPrinting || isDownloading"
              >
                <span v-if="isPrinting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-printer me-1"></i>
                {{ isPrinting ? 'Ouverture...' : 'Imprimer le devis' }}
              </button>
              <button
                v-if="canConvertToSale"
                @click="convertToSale"
                class="btn btn-primary"
                :disabled="isConverting || isDownloading || isPrinting"
              >
                <span v-if="isConverting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                <i v-else class="bi bi-cart-check me-1"></i>
                {{ isConverting ? 'Conversion...' : 'Convertir en vente' }}
              </button>
              <Link
                :href="route('quotes.edit', { id: quote.id })"
                class="btn btn-outline-primary"
                :class="{ 'disabled': isDownloading || isPrinting }"
                :tabindex="(isDownloading || isPrinting) ? -1 : 0"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier le devis
              </Link>
              <button
                @click="deleteQuote"
                class="btn btn-outline-danger"
                :disabled="isDownloading || isPrinting"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer le devis
              </button>
            </div>
          </div>
        </div>

        <!-- Statistiques -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Statistiques</h5>
          </div>
          <div class="card-body">
            <div class="row g-3 text-center">
              <div class="col-6">
                <div class="p-2 bg-light rounded">
                  <div class="fw-bold text-primary">{{ quote.items_count || quote.quoteItems?.length || 0 }}</div>
                  <div class="small text-muted">Articles</div>
                </div>
              </div>
              <div class="col-6">
                <div class="p-2 bg-light rounded">
                  <div class="fw-bold text-success">{{ formatCurrency(quote.total_amount) }}</div>
                  <div class="small text-muted">Montant</div>
                </div>
              </div>
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
import { ref, computed } from 'vue'

const { success, error, confirm } = useSweetAlert()

const isDownloading = ref(false)
const isPrinting = ref(false)
const isConverting = ref(false)

// Vérifier si le devis peut être converti en vente
const canConvertToSale = computed(() => {
  return props.quote.status === 'accepted' || props.quote.status === 'sent'
})

interface Category {
  id: number
  name: string
  color: string
}

interface Product {
  id: number
  name: string
  sku: string
  unit: string
  category: Category
  image_url?: string | null
}

interface QuoteItem {
  id: number
  quantity: number
  unit_price: number
  total_price: number
  product: Product
}

interface User {
  id: number
  name: string
}

interface Customer {
  id: number
  name: string
}

interface Quote {
  id: number
  quote_number: string
  customer_id?: number
  user_id: number
  subtotal: number
  tax_amount: number
  discount_amount: number
  total_amount: number
  status: string
  valid_until?: string
  notes?: string
  created_at: string
  items_count?: number
  customer?: Customer
  user?: User
  quoteItems?: QuoteItem[]
}

interface Props {
  quote: Quote
}

const props = defineProps<Props>()

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const getStatusLabel = (status: string) => {
  const labels: Record<string, string> = {
    'draft': 'Brouillon',
    'sent': 'Envoyé',
    'accepted': 'Accepté',
    'rejected': 'Refusé',
    'expired': 'Expiré'
  }
  return labels[status] || status
}

const getStatusClass = (status: string) => {
  const classes: Record<string, string> = {
    'draft': 'bg-secondary',
    'sent': 'bg-info',
    'accepted': 'bg-success',
    'rejected': 'bg-danger',
    'expired': 'bg-warning text-dark'
  }
  return classes[status] || 'bg-secondary'
}

const getStatusIcon = (status: string) => {
  const icons: Record<string, string> = {
    'draft': 'bi bi-file-earmark',
    'sent': 'bi bi-send',
    'accepted': 'bi bi-check-circle',
    'rejected': 'bi bi-x-circle',
    'expired': 'bi bi-clock-history'
  }
  return icons[status] || 'bi bi-question-circle'
}

const isExpired = (validUntil: string | undefined) => {
  if (!validUntil) return false
  return new Date(validUntil) < new Date()
}

const downloadQuote = async () => {
  if (isDownloading.value) return

  isDownloading.value = true

  try {
    const url = route('quotes.download', { quote: props.quote.id })

    const response = await fetch(url)

    if (!response.ok) {
      throw new Error('Erreur lors du téléchargement')
    }

    const blob = await response.blob()
    const downloadUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = downloadUrl
    link.download = `devis_${props.quote.quote_number}.pdf`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(downloadUrl)

    setTimeout(() => {
      isDownloading.value = false
    }, 500)
  } catch (err) {
    isDownloading.value = false
    error('Erreur lors du téléchargement du devis')
  }
}

const printQuote = async () => {
  if (isPrinting.value) return

  isPrinting.value = true

  try {
    const url = route('quotes.print', { quote: props.quote.id })

    const response = await fetch(url)

    if (!response.ok) {
      throw new Error('Erreur lors du chargement')
    }

    const blob = await response.blob()
    const printUrl = window.URL.createObjectURL(blob)
    const newWindow = window.open(printUrl, '_blank')

    if (!newWindow) {
      window.location.href = url
    } else {
      newWindow.onload = () => {
        setTimeout(() => {
          newWindow.print()
          isPrinting.value = false
        }, 500)
      }
    }

    setTimeout(() => {
      isPrinting.value = false
    }, 3000)
  } catch (err) {
    isPrinting.value = false
    error('Erreur lors de l\'ouverture du devis')
  }
}

const deleteQuote = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le devis "${props.quote.quote_number}" ?`)
  
  if (confirmed) {
    router.delete(route('quotes.destroy', { id: props.quote.id }), {
      onSuccess: () => {
        success(`Devis "${props.quote.quote_number}" supprimé avec succès !`)
      },
      onError: (errors) => {
        const errorMessage = errors.message || 'Erreur lors de la suppression du devis.'
        error(errorMessage)
      }
    })
  }
}

const convertToSale = async () => {
  if (isConverting.value) return
  
  const confirmed = await confirm(
    `Êtes-vous sûr de vouloir convertir le devis "${props.quote.quote_number}" en vente ?\n\nCette action créera une nouvelle vente avec les mêmes informations et décrémentera le stock des produits.`
  )
  
  if (!confirmed) return
  
  isConverting.value = true
  
  router.post(route('quotes.convert-to-sale', { quote: props.quote.id }), {}, {
    onSuccess: () => {
      // Le message de succès sera affiché via la redirection
      isConverting.value = false
    },
    onError: (errors) => {
      isConverting.value = false
      const errorMessage = errors.message || errors.stock || 'Erreur lors de la conversion du devis en vente.'
      error(errorMessage)
    }
  })
}
</script>

