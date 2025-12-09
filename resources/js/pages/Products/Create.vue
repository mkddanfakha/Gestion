<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Nouveau produit</h1>
        <p class="text-muted mb-0">Ajoutez un nouveau produit à votre inventaire</p>
      </div>
      <Link
        :href="route('products.index')"
        class="btn btn-outline-secondary"
      >
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
      </Link>
    </div>

    <form>
      <div class="row">
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
                    <option v-for="category in categories" :key="category.id" :value="category.id">
                      {{ category.name }}
                    </option>
                  </select>
                  <div v-if="errors.category_id" class="invalid-feedback">{{ errors.category_id }}</div>
                  <div v-if="clientErrors.category_id" class="invalid-feedback">{{ clientErrors.category_id }}</div>
                </div>
              </div>

              <div class="mt-3">
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

          <!-- Codes et identification -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Codes et identification</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">SKU (Code produit)</label>
                  <div class="input-group">
                    <input
                      v-model="form.sku"
                      type="text"
                      placeholder="Généré automatiquement"
                      class="form-control"
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
                    class="form-control"
                    :class="{ 'is-invalid': errors.barcode }"
                  />
                  <div v-if="errors.barcode" class="invalid-feedback">{{ errors.barcode }}</div>
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
                    <span class="input-group-text">Fcfa</span>
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
                  </div>
                  <div v-if="errors.price" class="invalid-feedback">{{ errors.price }}</div>
                  <div v-if="clientErrors.price" class="invalid-feedback">{{ clientErrors.price }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Prix de revient</label>
                  <div class="input-group">
                    <span class="input-group-text">Fcfa</span>
                    <input
                      v-model="form.cost_price"
                      type="number"
                      step="0.01"
                      min="0"
                      class="form-control"
                      :class="{ 'is-invalid': errors.cost_price || clientErrors.cost_price }"
                      @blur="validateField('cost_price', form.cost_price)"
                      @input="validateField('cost_price', form.cost_price)"
                    />
                  </div>
                  <div v-if="errors.cost_price" class="invalid-feedback">{{ errors.cost_price }}</div>
                  <div v-if="clientErrors.cost_price" class="invalid-feedback">{{ clientErrors.cost_price }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">
                    Stock initial <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.stock_quantity"
                    type="number"
                    min="0"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.stock_quantity || clientErrors.stock_quantity }"
                  />
                  <div v-if="errors.stock_quantity" class="invalid-feedback">{{ errors.stock_quantity }}</div>
                  <div v-if="clientErrors.stock_quantity" class="invalid-feedback">{{ clientErrors.stock_quantity }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Unité</label>
                  <select
                    v-model="form.unit"
                    class="form-select"
                    :class="{ 'is-invalid': errors.unit || clientErrors.unit }"
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
                    <option value="paquet">Paquet</option>
                    <option value="boîte">Boîte</option>
                    <option value="carton">Carton</option>
                  </select>
                  <div v-if="errors.unit" class="invalid-feedback">{{ errors.unit }}</div>
                  <div v-if="clientErrors.unit" class="invalid-feedback">{{ clientErrors.unit }}</div>
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

              <div class="row g-3 mt-2">
                <div class="col-md-6">
                  <label class="form-label">Stock minimum</label>
                  <input
                    v-model="form.min_stock_level"
                    type="number"
                    min="0"
                    class="form-control"
                    :class="{ 'is-invalid': errors.min_stock_level }"
                  />
                  <div v-if="errors.min_stock_level" class="invalid-feedback">{{ errors.min_stock_level }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Statut</label>
                  <select
                    v-model="form.is_active"
                    class="form-select"
                    :class="{ 'is-invalid': errors.is_active }"
                  >
                    <option :value="true">Actif</option>
                    <option :value="false">Inactif</option>
                  </select>
                  <div v-if="errors.is_active" class="invalid-feedback">{{ errors.is_active }}</div>
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
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
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
                @update:files="handleFilesUpdate"
                @processfile="handleFileProcessed"
                @addfile="handleFileAdded"
              />
              <small class="form-text text-muted mt-2 d-block">
                Formats acceptés: JPG, PNG, GIF, WEBP. Taille max: 5 Mo.
              </small>
            </div>
          </div>

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
                  {{ processing ? 'Création...' : 'Créer le produit' }}
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

          <!-- Aperçu -->
          <div class="card mt-3">
            <div class="card-header">
              <h5 class="card-title mb-0">Aperçu</h5>
            </div>
            <div class="card-body">
              <div class="text-center">
                <!-- Image preview -->
                <div class="mb-3" style="min-height: 200px; display: flex; align-items: center; justify-content: center; background-color: #f8f9fa; border-radius: 0.375rem; padding: 1rem;">
                  <img
                    v-if="previewImageUrl"
                    :src="previewImageUrl"
                    :alt="form.name || 'Produit'"
                    class="img-fluid rounded"
                    style="max-height: 200px; max-width: 100%; object-fit: contain;"
                  />
                  <div v-else class="w-100 d-flex align-items-center justify-content-center" style="min-height: 200px;">
                    <i class="bi bi-box fs-1 text-muted"></i>
                  </div>
                </div>
                
                <!-- Product info -->
                <template v-if="form.name">
                  <h6 class="fw-medium">{{ form.name }}</h6>
                  <p class="text-muted small mb-2">{{ form.description || 'Aucune description' }}</p>
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-medium text-success">{{ formatPrice(form.price) }}</span>
                    <span class="badge bg-light text-dark">{{ form.stock_quantity || 0 }} {{ form.unit }}</span>
                  </div>
                </template>
                <template v-else>
                  <p class="small text-muted">Aperçu du produit</p>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ref, watch, nextTick } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import FilePondImageUpload from '@/components/FilePondImageUpload.vue'

interface Category {
  id: number
  name: string
}

interface Props {
  categories: Category[]
}

const props = defineProps<Props>()

const { success, error } = useSweetAlert()

// État des erreurs de validation côté client
const clientErrors = ref<Record<string, string>>({})

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
  
  if (form.cost_price && form.cost_price < 0) {
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
      if (value && value < 0) {
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

const formatPrice = (price: number) => {
  return new Intl.NumberFormat('fr-FR').format(price) + ' Fcfa'
}

const form = useForm({
  name: '',
  description: '',
  sku: '',
  barcode: '',
  price: 0,
  cost_price: null as number | null,
  stock_quantity: 0,
  min_stock_level: 0,
  unit: 'pièce',
  location: '',
  category_id: '',
  is_active: true,
  expiration_date: '',
  alert_threshold_value: null as number | null,
  alert_threshold_unit: '' as 'days' | 'weeks' | 'months' | '',
})

const filePondRef = ref<InstanceType<typeof FilePondImageUpload> | null>(null)
const uploadedFiles = ref<File[]>([])
const previewImageUrl = ref<string | null>(null)

const handleFileAdded = (file: any) => {
  // Quand un fichier est ajouté, mettre à jour la prévisualisation immédiatement
  setTimeout(() => {
    updatePreviewFromFile(file)
  }, 200)
}

const handleFileProcessed = (file: any) => {
  // Quand un fichier est traité, mettre à jour la prévisualisation
  updatePreviewFromFile(file)
}

const updatePreviewFromFile = (file: any) => {
  if (!file) {
    previewImageUrl.value = null
    return
  }
  
  // Essayer d'obtenir le fichier natif de différentes manières
  let nativeFile = null
  
  // Méthode 1: file.file (propriété directe)
  if (file.file instanceof File) {
    nativeFile = file.file
  }
  // Méthode 2: file.getFile() (méthode FilePond)
  else if (file.getFile && typeof file.getFile === 'function') {
    try {
      nativeFile = file.getFile()
    } catch (e) {
      // Ignorer l'erreur
    }
  }
  // Méthode 3: file.source (pour les fichiers déjà chargés)
  else if (file.source && (file.source.startsWith('http') || file.source.startsWith('/'))) {
    previewImageUrl.value = file.source
    return
  }
  
  // Si on a un fichier natif, créer une URL de prévisualisation
  if (nativeFile instanceof File) {
    const reader = new FileReader()
    reader.onload = (e) => {
      previewImageUrl.value = e.target?.result as string
    }
    reader.onerror = () => {
      previewImageUrl.value = null
    }
    reader.readAsDataURL(nativeFile)
  }
  // Si FilePond a une méthode getFileEncode
  else if (file.getFileEncode && typeof file.getFileEncode === 'function') {
    file.getFileEncode().then((base64: string) => {
      previewImageUrl.value = base64
    }).catch(() => {
      if (file.source) {
        previewImageUrl.value = file.source
      } else {
        previewImageUrl.value = null
      }
    })
  }
  else {
    previewImageUrl.value = null
  }
}

const handleFilesUpdate = (files: any[]) => {
  uploadedFiles.value = files.map((file: any) => {
    // FilePond peut avoir file.file (File natif) ou file.getFile() (méthode)
    const nativeFile = file.file || (file.getFile ? file.getFile() : null)
    return nativeFile
  }).filter(Boolean)
  
  // Mettre à jour l'URL de prévisualisation
  if (files.length > 0) {
    // Utiliser setTimeout pour laisser FilePond finir de charger
    setTimeout(() => {
      updatePreviewFromFile(files[0])
    }, 100)
  } else {
    previewImageUrl.value = null
  }
}

// Surveiller les changements dans uploadedFiles pour mettre à jour la prévisualisation
watch(uploadedFiles, (newFiles) => {
  if (newFiles.length > 0 && newFiles[0] instanceof File) {
    const reader = new FileReader()
    reader.onload = (e) => {
      previewImageUrl.value = e.target?.result as string
    }
    reader.onerror = () => {
      previewImageUrl.value = null
    }
    reader.readAsDataURL(newFiles[0])
  } else if (newFiles.length === 0) {
    previewImageUrl.value = null
  }
}, { deep: true })

const isGeneratingSku = ref(false)

const generateSku = async () => {
  if (!form.name || !form.category_id) {
    return
  }

  isGeneratingSku.value = true
  
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    
    const response = await fetch(route('products.generate-sku'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken || '',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        name: form.name,
        category_id: form.category_id,
      }),
    })

    if (response.ok) {
      const data = await response.json()
      form.sku = data.sku
    }
  } catch (error) {
    console.error('Erreur lors de la génération du SKU:', error)
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
  const formDataObj = form.data()
  
  // Ajouter tous les champs du formulaire
  Object.keys(formDataObj).forEach((key) => {
    const value = (formDataObj as any)[key]
    if (value !== null && value !== undefined && value !== '') {
      if (key === 'is_active') {
        formData.append(key, value ? '1' : '0')
      } else {
        formData.append(key, String(value))
      }
    }
  })
  
  // Ajouter les fichiers
  uploadedFiles.value.forEach((file) => {
    formData.append('images[]', file)
  })
  
  // Envoyer avec Inertia
  form.transform(() => formData).post(route('products.store'), {
    forceFormData: true,
    onSuccess: () => {
      success('Produit créé avec succès !')
      form.reset()
      clientErrors.value = {}
      previewImageUrl.value = null
      if (filePondRef.value) {
        filePondRef.value.removeFiles()
      }
    },
    onError: () => {
      error('Erreur lors de la création du produit.')
    }
  })
}

const { errors, processing } = form
</script>
