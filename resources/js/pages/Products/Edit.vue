<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Modifier le produit</h1>
        <p class="text-muted mb-0">{{ product.name }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('products.show', { id: product.id })"
          class="btn btn-outline-primary"
        >
          <i class="bi bi-eye me-1"></i>
          Voir le produit
        </Link>
        <Link
          :href="route('products.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <form>
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <!-- Informations générales -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations générales</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">
                    Nom du produit <span class="text-danger">*</span>
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
                  <label class="form-label">
                    Catégorie <span class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.category_id"
                    required
                    class="form-select"
                    :class="{ 'is-invalid': errors.category_id || clientErrors.category_id }"
                    @blur="validateField('category_id', form.category_id)"
                    @change="validateField('category_id', form.category_id)"
                  >
                    <option value="">Sélectionner une catégorie</option>
                    <option 
                      v-for="category in categories" 
                      :key="category.id" 
                      :value="category.id"
                    >
                      {{ category.name }}
                    </option>
                  </select>
                  <div v-if="errors.category_id" class="invalid-feedback">{{ errors.category_id }}</div>
                  <div v-if="clientErrors.category_id" class="invalid-feedback">{{ clientErrors.category_id }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    SKU <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <input
                      v-model="form.sku"
                      type="text"
                      required
                      class="form-control font-monospace"
                      :class="{ 'is-invalid': errors.sku }"
                    />
                    <button
                      type="button"
                      @click="generateSku"
                      :disabled="!form.name || !form.category_id || isGeneratingSku"
                      class="btn btn-outline-primary"
                    >
                      <span v-if="isGeneratingSku" class="spinner-border spinner-border-sm me-1"></span>
                      {{ isGeneratingSku ? 'Génération...' : 'Générer' }}
                    </button>
                  </div>
                  <div v-if="errors.sku" class="text-danger small">{{ errors.sku }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Code-barres</label>
                  <input
                    v-model="form.barcode"
                    type="text"
                    class="form-control font-monospace"
                    :class="{ 'is-invalid': errors.barcode }"
                  />
                  <div v-if="errors.barcode" class="invalid-feedback">{{ errors.barcode }}</div>
                </div>

                <div class="col-12">
                  <label class="form-label">Description</label>
                  <textarea
                    v-model="form.description"
                    rows="3"
                    class="form-control"
                    :class="{ 'is-invalid': errors.description }"
                  ></textarea>
                  <div v-if="errors.description" class="invalid-feedback">{{ errors.description }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Prix et stock -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Prix et stock</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">
                    Prix de vente <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <input
                      v-model="form.price"
                      type="number"
                      step="0.01"
                      min="0"
                      required
                      class="form-control"
                      :class="{ 'is-invalid': errors.price || clientErrors.price }"
                      @blur="validateField('price', form.price)"
                      @input="validateField('price', form.price)"
                    />
                    <span class="input-group-text">Fcfa</span>
                  </div>
                  <div v-if="errors.price" class="invalid-feedback">{{ errors.price }}</div>
                  <div v-if="clientErrors.price" class="invalid-feedback">{{ clientErrors.price }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Prix de revient</label>
                  <div class="input-group">
                    <input
                      v-model="form.cost_price"
                      type="number"
                      step="0.01"
                      min="0"
                      class="form-control"
                      :class="{ 'is-invalid': errors.cost_price }"
                    />
                    <span class="input-group-text">Fcfa</span>
                  </div>
                  <div v-if="errors.cost_price" class="invalid-feedback">{{ errors.cost_price }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Unité</label>
                  <select
                    v-model="form.unit"
                    class="form-select"
                    :class="{ 'is-invalid': errors.unit }"
                  >
                    <option value="pièce">Pièce</option>
                    <option value="kg">Kilogramme</option>
                    <option value="g">Gramme</option>
                    <option value="L">Litre</option>
                    <option value="mL">Millilitre</option>
                    <option value="m">Mètre</option>
                    <option value="cm">Centimètre</option>
                    <option value="m²">Mètre carré</option>
                    <option value="m³">Mètre cube</option>
                    <option value="boîte">Boîte</option>
                    <option value="paquet">Paquet</option>
                    <option value="lot">Lot</option>
                  </select>
                  <div v-if="errors.unit" class="invalid-feedback">{{ errors.unit }}</div>
                </div>

                <div v-if="!isVendeur" class="col-md-6">
                  <label class="form-label">
                    Stock actuel <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.stock_quantity"
                    type="number"
                    min="0"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.stock_quantity }"
                  />
                  <div v-if="errors.stock_quantity" class="invalid-feedback">{{ errors.stock_quantity }}</div>
                </div>

                <div v-if="!isVendeur" class="col-md-6">
                  <label class="form-label">
                    Stock minimum <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.min_stock_level"
                    type="number"
                    min="0"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.min_stock_level }"
                  />
                  <div v-if="errors.min_stock_level" class="invalid-feedback">{{ errors.min_stock_level }}</div>
                </div>
                
                <div v-if="isVendeur" class="col-md-6">
                  <label class="form-label">Stock actuel</label>
                  <input
                    :value="product.stock_quantity"
                    type="number"
                    disabled
                    class="form-control bg-light"
                  />
                  <small class="form-text text-muted">Vous ne pouvez pas modifier le stock</small>
                </div>

                <div v-if="isVendeur" class="col-md-6">
                  <label class="form-label">Stock minimum</label>
                  <input
                    :value="product.min_stock_level"
                    type="number"
                    disabled
                    class="form-control bg-light"
                  />
                  <small class="form-text text-muted">Vous ne pouvez pas modifier le stock minimum</small>
                </div>
              </div>

              <div class="row g-3 mt-2">
                <div class="col-md-6">
                  <label class="form-label">Emplacement (optionnel)</label>
                  <input
                    v-model="form.location"
                    type="text"
                    class="form-control"
                    placeholder="Ex: Rayon A, Étagère 3, etc."
                    :class="{ 'is-invalid': errors.location }"
                  />
                  <div v-if="errors.location" class="invalid-feedback">{{ errors.location }}</div>
                  <small class="form-text text-muted">Emplacement du produit dans le magasin</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Date d'expiration et alerte -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Date d'expiration et alerte</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Date d'expiration (optionnel)</label>
                  <input
                    v-model="form.expiration_date"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': errors.expiration_date }"
                  />
                  <div v-if="errors.expiration_date" class="invalid-feedback">{{ errors.expiration_date }}</div>
                  <small class="form-text text-muted">Date à laquelle le produit expire</small>
                </div>

                <div class="col-md-6" v-if="form.expiration_date">
                  <label class="form-label">Seuil d'alerte</label>
                  <div class="row g-2">
                    <div class="col-6">
                      <input
                        v-model.number="form.alert_threshold_value"
                        type="number"
                        min="1"
                        class="form-control"
                        placeholder="Nombre"
                        :class="{ 'is-invalid': errors.alert_threshold_value }"
                      />
                      <div v-if="errors.alert_threshold_value" class="invalid-feedback">{{ errors.alert_threshold_value }}</div>
                    </div>
                    <div class="col-6">
                      <select
                        v-model="form.alert_threshold_unit"
                        class="form-select"
                        :class="{ 'is-invalid': errors.alert_threshold_unit }"
                      >
                        <option value="">Unité</option>
                        <option value="days">Jours</option>
                        <option value="weeks">Semaines</option>
                        <option value="months">Mois</option>
                      </select>
                      <div v-if="errors.alert_threshold_unit" class="invalid-feedback">{{ errors.alert_threshold_unit }}</div>
                    </div>
                  </div>
                  <small class="form-text text-muted">Alerter X jours/semaines/mois avant l'expiration</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Image du produit -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Image du produit</h5>
            </div>
            <div class="card-body">
              <FilePondImageUpload
                ref="filePondRef"
                name="images"
                :accepted-file-types="['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp']"
                max-file-size="5MB"
                :max-files="1"
                :allow-multiple="false"
                :image-resize-target-width="1200"
                :image-resize-target-height="1200"
                image-resize-mode="contain"
                :image-resize-upscale="false"
                label-idle="Glissez-déposez une image ou <span class='filepond--label-action'>Parcourir</span>"
                :credits="false"
                :files="existingImages"
                @update:files="handleFilesUpdate"
              />
              <small class="form-text text-muted mt-2 d-block">
                Formats acceptés: JPG, PNG, GIF, WEBP. Taille max: 5 Mo.
              </small>
            </div>
          </div>

          <!-- Statut -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Statut</h5>
            </div>
            <div class="card-body">
              <div class="form-check form-switch">
                <input
                  v-model="form.is_active"
                  class="form-check-input"
                  type="checkbox"
                  id="is_active"
                />
                <label class="form-check-label" for="is_active">
                  Produit actif
                </label>
                <div class="form-text">
                  Un produit inactif ne sera pas visible dans les ventes
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
              {{ processing ? 'Modification...' : 'Modifier le produit' }}
            </button>
            <Link
              :href="route('products.index')"
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
import { Link, useForm, router } from '@inertiajs/vue3'
import { ref, computed, toRefs } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { usePermissions } from '@/composables/usePermissions'
import FilePondImageUpload from '@/components/FilePondImageUpload.vue'

const { isVendeur } = usePermissions()

interface Category {
  id: number
  name: string
}

interface Product {
  id: number
  name: string
  description?: string
  sku: string
  barcode?: string
  price: number
  cost_price?: number
  stock_quantity: number
  min_stock_level: number
  unit: string
  location?: string
  is_active: boolean
  category_id: number
  expiration_date?: string
  alert_threshold_value?: number
  alert_threshold_unit?: 'days' | 'weeks' | 'months'
}

interface Image {
  id: number
  url: string
  name: string
}

interface Props {
  product: Product
  categories: Category[]
  images?: Image[]
  uploadUrl?: string
}

const props = withDefaults(defineProps<Props>(), {
  images: () => [],
  uploadUrl: () => route('products.upload-image'),
})

const { success, error, confirm } = useSweetAlert()

// Initialiser le formulaire AVANT les fonctions qui l'utilisent
const form = useForm({
  name: props.product.name,
  description: props.product.description || '',
  sku: props.product.sku,
  barcode: props.product.barcode || '',
  price: props.product.price,
  cost_price: props.product.cost_price || 0,
  stock_quantity: props.product.stock_quantity,
  min_stock_level: props.product.min_stock_level,
  unit: props.product.unit,
  location: props.product.location || '',
  is_active: props.product.is_active,
  category_id: props.product.category_id,
  expiration_date: props.product.expiration_date ? new Date(props.product.expiration_date).toISOString().split('T')[0] : '',
  alert_threshold_value: props.product.alert_threshold_value || null,
  alert_threshold_unit: props.product.alert_threshold_unit || '' as 'days' | 'weeks' | 'months' | '',
})

// Computed pour les erreurs du formulaire
const errors = computed(() => form.errors)

// Computed pour l'état de traitement du formulaire
const processing = computed(() => form.processing)

// État des erreurs de validation côté client
const clientErrors = ref<Record<string, string>>({})

// Variables pour la génération de SKU
const isGeneratingSku = ref(false)

// Fonction pour obtenir le label d'un champ
const getFieldLabel = (field: string): string => {
  const labels: Record<string, string> = {
    name: 'Nom du produit',
    description: 'Description',
    sku: 'SKU',
    barcode: 'Code-barres',
    price: 'Prix',
    cost_price: 'Prix de revient',
    stock_quantity: 'Quantité en stock',
    min_stock_level: 'Stock minimum',
    unit: 'Unité',
    location: 'Emplacement',
    category_id: 'Catégorie',
    is_active: 'Statut',
    expiration_date: 'Date d\'expiration',
    alert_threshold_value: 'Valeur du seuil d\'alerte',
    alert_threshold_unit: 'Unité du seuil d\'alerte',
    images: 'Images',
    delete_images: 'Images à supprimer',
  }
  return labels[field] || field
}

// Validation simple côté client
const validateForm = () => {
  const errors: Record<string, string> = {}
  
  if (!form.name || form.name.trim().length < 2) {
    errors.name = 'Le nom du produit est requis (minimum 2 caractères)'
  }
  
  if (form.name && form.name.length > 255) {
    errors.name = 'Le nom ne peut pas dépasser 255 caractères'
  }
  
  if (form.sku && form.sku.length > 6) {
    errors.sku = 'Le SKU ne peut pas dépasser 6 caractères'
  }
  
  if (form.sku && !/^[A-Z]{2}[0-9]{4}$/.test(form.sku)) {
    errors.sku = 'Le SKU doit être au format CCNNNN (2 lettres majuscules + 4 chiffres)'
  }
  
  if (form.barcode && form.barcode.length > 255) {
    errors.barcode = 'Le code-barres ne peut pas dépasser 255 caractères'
  }
  
  if (form.barcode && !/^[a-zA-Z0-9]+$/.test(form.barcode)) {
    errors.barcode = 'Le code-barres ne peut contenir que des caractères alphanumériques'
  }
  
  if (!form.price || form.price < 0) {
    errors.price = 'Le prix est requis et doit être positif'
  }
  
  if (form.cost_price < 0) {
    errors.cost_price = 'Le prix de revient ne peut pas être négatif'
  }
  
  if (!form.stock_quantity || form.stock_quantity < 0) {
    errors.stock_quantity = 'La quantité en stock est requise et doit être positive'
  }
  
  if (!form.min_stock_level || form.min_stock_level < 0) {
    errors.min_stock_level = 'Le niveau de stock minimum est requis et doit être positif'
  }
  
  if (!form.unit || form.unit.length > 50) {
    errors.unit = 'L\'unité est requise et ne peut pas dépasser 50 caractères'
  }
  
  if (!form.category_id) {
    errors.category_id = 'La catégorie est requise'
  }
  
  return Object.keys(errors).length === 0 ? null : errors
}

// Validation en temps réel pour un champ spécifique
const validateField = (fieldName: string, value: any) => {
  // Effacer l'erreur précédente pour ce champ
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'name':
      if (!value || value.trim().length < 2) {
        errorMessage = 'Le nom du produit est requis (minimum 2 caractères)'
      } else if (value.length > 255) {
        errorMessage = 'Le nom ne peut pas dépasser 255 caractères'
      }
      break
      
    case 'sku':
      if (value && value.length > 6) {
        errorMessage = 'Le SKU ne peut pas dépasser 6 caractères'
      } else if (value && !/^[A-Z]{2}[0-9]{4}$/.test(value)) {
        errorMessage = 'Le SKU doit être au format CCNNNN (2 lettres majuscules + 4 chiffres)'
      }
      break
      
    case 'barcode':
      if (value && value.length > 255) {
        errorMessage = 'Le code-barres ne peut pas dépasser 255 caractères'
      } else if (value && !/^[a-zA-Z0-9]+$/.test(value)) {
        errorMessage = 'Le code-barres ne peut contenir que des caractères alphanumériques'
      }
      break
      
    case 'price':
      if (!value || value < 0) {
        errorMessage = 'Le prix est requis et doit être positif'
      }
      break
      
    case 'cost_price':
      if (value < 0) {
        errorMessage = 'Le prix de revient ne peut pas être négatif'
      }
      break
      
    case 'stock_quantity':
      if (!value || value < 0) {
        errorMessage = 'La quantité en stock est requise et doit être positive'
      }
      break
      
    case 'min_stock_level':
      if (!value || value < 0) {
        errorMessage = 'Le niveau de stock minimum est requis et doit être positif'
      }
      break
      
    case 'unit':
      if (!value || value.length > 50) {
        errorMessage = 'L\'unité est requise et ne peut pas dépasser 50 caractères'
      }
      break
      
    case 'category_id':
      if (!value) {
        errorMessage = 'La catégorie est requise'
      }
      break
  }
  
  // Ajouter l'erreur si elle existe
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const filePondRef = ref<InstanceType<typeof FilePondImageUpload> | null>(null)
const uploadedFiles = ref<File[]>([])
const deletedImageIds = ref<number[]>([])

const existingImages = computed(() => {
  return props.images.map((img) => {
    // Utiliser l'URL telle quelle si elle est relative, sinon convertir en relative
    let url = img.url
    if (url.startsWith('http')) {
      // Si c'est une URL absolue, extraire le chemin pour éviter les problèmes CORS
      try {
        const urlObj = new URL(url)
        url = urlObj.pathname
      } catch {
        // Si l'URL est invalide, utiliser telle quelle
      }
    }
    // Si l'URL ne commence pas par /, l'ajouter
    if (!url.startsWith('/')) {
      url = '/' + url
    }
    return {
      source: url,
      options: {
        type: 'limbo' as const,
        metadata: {
          mediaId: img.id, // Stocker l'ID du média dans les métadonnées
          originalUrl: img.url, // Stocker l'URL originale pour référence
        },
      },
    }
  })
})

// Fonction pour normaliser une URL (convertir en chemin relatif)
const normalizeUrl = (url: string): string => {
  if (!url || typeof url !== 'string') return ''
  
  // Si c'est une URL absolue, extraire le chemin
  if (url.startsWith('http')) {
    try {
      const urlObj = new URL(url)
      url = urlObj.pathname
    } catch {
      // Si l'URL est invalide, utiliser telle quelle
    }
  }
  
  // S'assurer que l'URL commence par /
  if (!url.startsWith('/')) {
    url = '/' + url
  }
  
  return url
}

const handleFilesUpdate = (files: any[]) => {
  // Filtrer uniquement les nouveaux fichiers (ceux qui ont un objet File natif)
  // Ne pas inclure les fichiers existants qui sont chargés depuis le serveur
  uploadedFiles.value = files
    .filter((file: any) => {
      // Vérifier que c'est un nouveau fichier (a un objet File natif)
      // et que ce n'est pas un fichier existant (source n'est pas une URL)
      const hasNativeFile = file.file instanceof File
      const source = file.source
      const isExistingFile = source && typeof source === 'string' && (source.startsWith('http') || source.startsWith('/'))
      return hasNativeFile && !isExistingFile
    })
    .map((file: any) => file.file)
  
  // Identifier les images supprimées en utilisant les IDs des médias stockés dans les métadonnées
  const currentImageIds = props.images.map((img) => img.id)
  
  // Vérifier s'il y a un nouveau fichier uploadé
  const hasNewFile = uploadedFiles.value.length > 0
  
  // Si un nouveau fichier est ajouté et qu'il y avait une image existante,
  // l'ancienne sera automatiquement remplacée, donc la marquer comme supprimée
  if (hasNewFile && currentImageIds.length > 0) {
    // L'ancienne image sera remplacée par la nouvelle, donc on supprime toutes les anciennes
    deletedImageIds.value = currentImageIds
    console.log('Nouveau fichier ajouté, ancienne image sera remplacée:', deletedImageIds.value)
    return
  }
  
  // Si pas de nouveau fichier, vérifier quelles images ont été supprimées manuellement
  // Si FilePond est vide mais qu'il y avait des images, elles ont été supprimées
  // Sinon, on considère que toutes les images existantes sont toujours présentes
  if (files.length === 0 && currentImageIds.length > 0) {
    // FilePond est vide, toutes les images ont été supprimées
    deletedImageIds.value = currentImageIds
    console.log('FilePond vide, toutes les images seront supprimées:', deletedImageIds.value)
    return
  }
  
  // Si FilePond contient des fichiers, essayer de récupérer les IDs
  // Extraire les IDs des médias qui sont encore présents dans FilePond
  // Filtrer d'abord les fichiers qui sont vraiment des images existantes (ont un ID)
  const remainingMediaIds = files
      .filter((file: any) => {
        // Ignorer les fichiers qui sont clairement des nouveaux fichiers (File natif)
        if (file.file instanceof File && !file.source) {
          return false
        }
        // Ignorer les fichiers qui ont du HTML comme source
        if (file.source && typeof file.source === 'string' && file.source.trim().startsWith('<!DOCTYPE')) {
          return false
        }
        return true
      })
      .map((file: any) => {
      // Récupérer l'ID du média depuis les métadonnées FilePond
      let mediaId = null
      
      // Essayer d'accéder aux métadonnées de différentes façons
      // FilePond peut stocker les métadonnées dans différentes propriétés
      if (file.metadata) {
        if (typeof file.metadata === 'object' && file.metadata.mediaId) {
          mediaId = file.metadata.mediaId
        } else if (typeof file.metadata === 'function') {
          const metadata = file.metadata()
          mediaId = metadata?.mediaId
        }
      }
      
      if (!mediaId && file.getMetadata && typeof file.getMetadata === 'function') {
        try {
          const metadata = file.getMetadata()
          mediaId = metadata?.mediaId
        } catch (e) {
          // Ignorer les erreurs
        }
      }
      
      if (!mediaId && file.serverId) {
        mediaId = file.serverId
      }
      
      // Si on n'a pas trouvé d'ID dans les métadonnées, essayer de le déduire de l'URL ou du nom du fichier
      if (!mediaId) {
        // Essayer depuis file.source (URL) - s'assurer que c'est une chaîne et pas du HTML
        if (file.source && typeof file.source === 'string' && !file.source.trim().startsWith('<!DOCTYPE')) {
          const match = file.source.match(/\/storage\/(\d+)\//)
          if (match && match[1]) {
            const potentialId = parseInt(match[1], 10)
            if (!isNaN(potentialId) && currentImageIds.includes(potentialId)) {
              mediaId = potentialId
            }
          }
        }
        
        // Essayer depuis file.filename (nom du fichier contient l'ID au format: mediaId_filename)
        if (!mediaId && file.filename && typeof file.filename === 'string') {
          // Format: mediaId_originalFileName
          const match = file.filename.match(/^(\d+)_/)
          if (match && match[1]) {
            const potentialId = parseInt(match[1], 10)
            if (!isNaN(potentialId) && currentImageIds.includes(potentialId)) {
              mediaId = potentialId
            }
          }
        }
        
        // Essayer depuis file.file.name si c'est un File (pour les fichiers chargés manuellement)
        if (!mediaId && file.file && file.file instanceof File && file.file.name) {
          const match = file.file.name.match(/^(\d+)_/)
          if (match && match[1]) {
            const potentialId = parseInt(match[1], 10)
            if (!isNaN(potentialId) && currentImageIds.includes(potentialId)) {
              mediaId = potentialId
            }
          }
        }
        
        // Essayer depuis la propriété personnalisée __mediaId
        if (!mediaId && (file as any).__mediaId) {
          mediaId = (file as any).__mediaId
        }
        
        // Essayer depuis file.id si c'est un nombre (peut être l'ID du média)
        if (!mediaId && file.id && typeof file.id === 'number') {
          if (currentImageIds.includes(file.id)) {
            mediaId = file.id
          }
        }
      }
      
      // S'assurer que mediaId est un nombre valide
      if (mediaId && typeof mediaId === 'number' && !isNaN(mediaId) && currentImageIds.includes(mediaId)) {
        return mediaId
      }
      
      // Si mediaId est une chaîne qui ressemble à un nombre, essayer de le convertir
      if (mediaId && typeof mediaId === 'string' && /^\d+$/.test(mediaId)) {
        const numId = parseInt(mediaId, 10)
        if (!isNaN(numId) && currentImageIds.includes(numId)) {
          return numId
        }
      }
      
      return null
    })
    .filter((id: any) => {
      // Filtrer uniquement les nombres valides qui correspondent à des images existantes
      return id !== null && id !== undefined && typeof id === 'number' && !isNaN(id) && currentImageIds.includes(id)
    })
  
  // Les images supprimées sont celles qui ont un ID dans currentImageIds mais pas dans remainingMediaIds
  // IMPORTANT: Ne pas supprimer les images qui n'ont pas d'ID (nouvelles images)
  // Si on n'a pas pu récupérer d'IDs depuis FilePond mais qu'il y a des fichiers,
  // on considère que toutes les images existantes sont toujours présentes (pas de suppression)
  if (remainingMediaIds.length === 0 && files.length > 0 && currentImageIds.length > 0) {
    // On n'a pas pu identifier les images dans FilePond, mais il y a des fichiers
    // On considère que toutes les images existantes sont toujours présentes
    deletedImageIds.value = []
    console.log('Impossible de récupérer les IDs depuis FilePond, aucune image ne sera supprimée')
  } else {
    // On a réussi à identifier certaines images, supprimer celles qui ne sont plus présentes
    deletedImageIds.value = currentImageIds.filter((id) => !remainingMediaIds.includes(id))
  }
  
  // Debug: afficher les IDs pour vérification
  console.log('Images actuelles:', currentImageIds)
  console.log('Nombre de fichiers dans FilePond:', files.length)
  console.log('Fichiers FilePond:', files.map((f: any) => ({
    filename: f.filename,
    file_name: f.file?.name,
    source: typeof f.source === 'string' ? f.source.substring(0, 50) : f.source,
    metadata: f.metadata,
    __mediaId: (f as any).__mediaId
  })))
  console.log('Images restantes dans FilePond:', remainingMediaIds)
  console.log('Images à supprimer:', deletedImageIds.value)
  
}

const generateSku = async () => {
  if (!form.name || !form.category_id) return
  
  isGeneratingSku.value = true
  
  try {
    const response = await fetch(route('products.generate-sku'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      },
      body: JSON.stringify({
        name: form.name,
        category_id: form.category_id
      })
    })
    
    if (response.ok) {
      const data = await response.json()
      form.sku = data.sku
    }
  } catch (err) {
    console.error('Erreur lors de la génération du SKU:', err)
  } finally {
    isGeneratingSku.value = false
  }
}

const submit = () => {
  // Effacer les erreurs précédentes
  clientErrors.value = {}
  
  const validationErrors = validateForm()
  
  if (validationErrors) {
    // Afficher les erreurs dans le formulaire
    clientErrors.value = validationErrors
    return
  }
  
  // Préparer les données du formulaire avec les fichiers
  const formData = new FormData()
  
  // Ajouter la méthode PUT (Laravel utilise le method spoofing)
  formData.append('_method', 'PUT')
  
  // Ajouter tous les champs du formulaire directement depuis form
  // Utiliser les valeurs du formulaire directement plutôt que form.data()
  formData.append('name', form.name || '')
  formData.append('description', form.description || '')
  formData.append('sku', form.sku || '')
  formData.append('barcode', form.barcode || '')
  formData.append('price', String(form.price || 0))
  if (form.cost_price !== null && form.cost_price !== undefined) {
    formData.append('cost_price', String(form.cost_price))
  }
  formData.append('stock_quantity', String(form.stock_quantity || 0))
  formData.append('min_stock_level', String(form.min_stock_level || 0))
  formData.append('unit', form.unit || '')
  if (form.location) {
    formData.append('location', form.location)
  }
  formData.append('category_id', String(form.category_id || ''))
  formData.append('is_active', form.is_active ? '1' : '0')
  // Gérer la date d'expiration : si vide ou null, envoyer explicitement null
  if (form.expiration_date && form.expiration_date.trim() !== '') {
    formData.append('expiration_date', form.expiration_date)
  } else {
    // Envoyer explicitement null pour effacer la date d'expiration
    formData.append('expiration_date', '')
  }
  if (form.alert_threshold_value !== null && form.alert_threshold_value !== undefined) {
    formData.append('alert_threshold_value', String(form.alert_threshold_value))
  }
  if (form.alert_threshold_unit) {
    formData.append('alert_threshold_unit', form.alert_threshold_unit)
  }
  
  // Ajouter les fichiers (uniquement les nouveaux fichiers, pas les fichiers existants)
  uploadedFiles.value.forEach((file) => {
    // Vérifier que c'est bien un objet File natif
    if (file instanceof File) {
      formData.append('images[]', file)
    }
  })
  
  // Gérer la suppression des images
  // RÈGLE IMPORTANTE : Si aucun nouveau fichier n'est ajouté et qu'il y a des images existantes,
  // on ne supprime JAMAIS les images, peu importe ce que dit deletedImageIds
  const hasNewFiles = uploadedFiles.value.length > 0
  const hasExistingImages = props.images && props.images.length > 0
  
  // Vérifier l'état actuel de FilePond au moment de la soumission
  const filePondFiles = filePondRef.value?.getFiles() || []
  const filePondHasFiles = filePondFiles.length > 0
  
  console.log('État au moment de la soumission:', {
    hasNewFiles,
    hasExistingImages,
    filePondHasFiles,
    filePondFilesCount: filePondFiles.length,
    deletedImageIds: deletedImageIds.value,
    existingImagesCount: props.images?.length || 0
  })
  
  // Ne supprimer les images que si :
  // 1. Un nouveau fichier est ajouté (remplacement) OU
  // 2. L'utilisateur a explicitement supprimé l'image (FilePond est vide mais il y avait des images)
  
  // PROTECTION : Si aucun nouveau fichier n'est ajouté et qu'il y a des images existantes,
  // on ne supprime JAMAIS les images
  if (!hasNewFiles && hasExistingImages) {
    // Pas de nouveau fichier et il y a des images existantes
    // Ne jamais supprimer les images dans ce cas
    console.log('Aucun nouveau fichier et images existantes présentes - aucune image ne sera supprimée')
    deletedImageIds.value = []
  } else if (deletedImageIds.value.length > 0) {
    // Il y a des images marquées pour suppression
    // Vérifier si on doit vraiment les supprimer
    if (hasNewFiles) {
      // Un nouveau fichier est ajouté, supprimer les anciennes images
      console.log('Nouveau fichier ajouté, suppression des anciennes images:', deletedImageIds.value)
    } else if (!filePondHasFiles && hasExistingImages) {
      // FilePond est vide mais il y avait des images - elles ont été supprimées manuellement
      console.log('FilePond est vide, les images seront supprimées:', deletedImageIds.value)
    } else {
      // Cas inattendu, ne pas supprimer par sécurité
      console.log('Cas inattendu, aucune image ne sera supprimée par sécurité')
      deletedImageIds.value = []
    }
    
    // Ajouter les IDs des images à supprimer (seulement si le tableau n'est pas vide)
    if (deletedImageIds.value.length > 0) {
      deletedImageIds.value.forEach((id) => {
        formData.append('delete_images[]', String(id))
      })
    }
  }
  
  // Vérifier que toutes les valeurs requises sont présentes
  if (!form.name || !form.sku || !form.price || !form.stock_quantity || !form.min_stock_level || !form.unit || !form.category_id) {
    error('Veuillez remplir tous les champs requis.')
    return
  }
  
  // Envoyer avec Inertia en utilisant router.put directement avec FormData
  try {
    router.post(route('products.update', { id: props.product.id }), formData, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: (page) => {
        // Afficher le message de succès
        const flash = (page.props as any)?.flash
        if (flash?.success) {
          success(flash.success)
        } else {
          success('Produit modifié avec succès !')
        }
        clientErrors.value = {}
      },
      onError: (errors) => {
        // Afficher les messages d'erreur avec SweetAlert
        if (errors && typeof errors === 'object' && Object.keys(errors).length > 0) {
          // Construire le message d'erreur avec toutes les erreurs en HTML
          const errorMessages = Object.entries(errors)
            .map(([field, message]) => {
              const fieldLabel = getFieldLabel(field)
              return `<strong>${fieldLabel}:</strong> ${message}`
            })
            .join('<br>')
          
          error(errorMessages)
        } else {
          error('Erreur lors de la modification du produit.')
        }
      }
    })
  } catch (err) {
    error('Une erreur inattendue s\'est produite lors de la modification du produit.')
  }
}
</script>