<template>
  <div ref="containerRef" class="product-autocomplete position-relative" style="z-index: 1;">
    <div class="input-group">
      <input
        ref="inputRef"
        v-model="searchQuery"
        type="text"
        class="form-control"
        :class="{ 'is-invalid': isInvalid, 'is-valid': isValid }"
        :placeholder="placeholder"
        :disabled="disabled"
        @input="handleInput"
        @focus="handleFocus"
        @blur="handleBlur"
        @keydown.enter.prevent="selectFirstMatch"
        @keydown.arrow-down.prevent="navigateDown"
        @keydown.arrow-up.prevent="navigateUp"
        @keydown.escape="closeDropdown"
      />
      <button
        v-if="selectedProduct"
        type="button"
        class="btn btn-outline-secondary"
        @click="clearSelection"
      >
        <i class="bi bi-x"></i>
      </button>
    </div>
    
    <!-- Dropdown des résultats -->
    <Teleport to="body">
      <div
        v-if="showDropdown && filteredProducts.length > 0"
        class="product-dropdown-menu dropdown-menu show"
        :style="{
          position: 'absolute',
          top: dropdownPosition.top + 'px',
          left: dropdownPosition.left + 'px',
          width: dropdownPosition.width + 'px',
          zIndex: 1050,
          maxHeight: '400px',
          overflowY: 'auto'
        }"
      >
      <div
        v-for="(product, index) in filteredProducts"
        :key="product.id"
        class="dropdown-item"
        :class="{ 'active': index === selectedIndex, 'disabled': isProductSelected(product.id) || product.stock_quantity <= 0 }"
        @mousedown.prevent="selectProduct(product)"
        @mouseenter="selectedIndex = index"
      >
        <div class="d-flex align-items-center">
          <!-- Image du produit -->
          <div class="flex-shrink-0 me-3">
            <div
              v-if="product.image_url"
              class="bg-light rounded overflow-hidden d-flex align-items-center justify-content-center"
              style="width: 50px; height: 50px;"
            >
              <img
                :src="product.image_url"
                :alt="product.name"
                class="img-fluid"
                style="width: 100%; height: 100%; object-fit: cover;"
              />
            </div>
            <div
              v-else
              class="bg-light rounded d-flex align-items-center justify-content-center"
              style="width: 50px; height: 50px;"
            >
              <i class="bi bi-box text-muted"></i>
            </div>
          </div>
          
          <!-- Informations du produit -->
          <div class="flex-grow-1">
            <div class="d-flex align-items-center justify-content-between">
              <div class="fw-medium">{{ product.name }}</div>
              <div class="text-muted small ms-2">{{ formatCurrency(product.price) }}</div>
            </div>
            <div class="d-flex align-items-center gap-2 mt-1">
              <span
                v-if="product.stock_quantity <= 0"
                class="badge bg-danger"
              >
                Rupture de stock
              </span>
              <span
                v-else-if="product.stock_quantity <= 5"
                class="badge bg-warning text-dark"
              >
                Stock faible: {{ product.stock_quantity }} {{ product.unit }}
              </span>
              <span
                v-else
                class="badge bg-success"
              >
                Stock: {{ product.stock_quantity }} {{ product.unit }}
              </span>
              <span
                v-if="isProductSelected(product.id)"
                class="badge bg-secondary"
              >
                Déjà sélectionné
              </span>
            </div>
          </div>
        </div>
      </div>
      </div>
      <!-- Message si aucun résultat -->
      <div
        v-if="showDropdown && searchQuery && filteredProducts.length === 0"
        class="product-dropdown-menu dropdown-menu show"
        :style="{
          position: 'absolute',
          top: dropdownPosition.top + 'px',
          left: dropdownPosition.left + 'px',
          width: dropdownPosition.width + 'px',
          zIndex: 1050
        }"
      >
        <div class="dropdown-item text-muted">
          Aucun produit trouvé
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

interface Category {
  id: number
  name: string
  color: string
}

interface Product {
  id: number
  name: string
  price: number
  cost_price?: number | null
  stock_quantity: number
  unit: string
  category?: Category
  image_url?: string | null
}

interface Props {
  products: Product[]
  modelValue?: number | null
  placeholder?: string
  disabled?: boolean
  excludeProductIds?: number[]
  isInvalid?: boolean
  isValid?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Rechercher un produit...',
  disabled: false,
  excludeProductIds: () => [],
  isInvalid: false,
  isValid: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | null): void
  (e: 'selected', product: Product): void
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const containerRef = ref<HTMLElement | null>(null)
const searchQuery = ref('')
const showDropdown = ref(false)
const selectedIndex = ref(-1)
const selectedProduct = ref<Product | null>(null)
const dropdownPosition = ref({ top: 0, left: 0, width: 0 })

// Produits filtrés selon la recherche
const filteredProducts = computed(() => {
  if (!searchQuery.value) {
    return props.products.filter(p => 
      !props.excludeProductIds.includes(p.id)
    ).slice(0, 10)
  }
  
  const query = searchQuery.value.toLowerCase()
  return props.products.filter(product => {
    const matchesSearch = product.name.toLowerCase().includes(query)
    const notExcluded = !props.excludeProductIds.includes(product.id)
    return matchesSearch && notExcluded
  }).slice(0, 10)
})

// Vérifier si un produit est déjà sélectionné
const isProductSelected = (productId: number): boolean => {
  return props.excludeProductIds.includes(productId)
}

// Calculer la position du dropdown
const updateDropdownPosition = () => {
  if (containerRef.value) {
    const rect = containerRef.value.getBoundingClientRect()
    dropdownPosition.value = {
      top: rect.bottom + window.scrollY,
      left: rect.left + window.scrollX,
      width: rect.width
    }
  }
}

// Gérer le focus
const handleFocus = () => {
  updateDropdownPosition()
  showDropdown.value = true
}

// Gérer la saisie
const handleInput = () => {
  updateDropdownPosition()
  showDropdown.value = true
  selectedIndex.value = -1
}

// Gérer le blur (fermer après un court délai pour permettre le clic)
const handleBlur = () => {
  setTimeout(() => {
    showDropdown.value = false
  }, 200)
}

// Sélectionner un produit
const selectProduct = (product: Product) => {
  if (isProductSelected(product.id) || product.stock_quantity <= 0) {
    return
  }
  
  selectedProduct.value = product
  searchQuery.value = product.name
  emit('update:modelValue', product.id)
  emit('selected', product)
  showDropdown.value = false
}

// Sélectionner le premier produit correspondant
const selectFirstMatch = () => {
  if (filteredProducts.value.length > 0 && selectedIndex.value >= 0) {
    selectProduct(filteredProducts.value[selectedIndex.value])
  } else if (filteredProducts.value.length > 0) {
    selectProduct(filteredProducts.value[0])
  }
}

// Navigation au clavier
const navigateDown = () => {
  if (selectedIndex.value < filteredProducts.value.length - 1) {
    selectedIndex.value++
  }
}

const navigateUp = () => {
  if (selectedIndex.value > 0) {
    selectedIndex.value--
  }
}

// Fermer le dropdown
const closeDropdown = () => {
  showDropdown.value = false
  selectedIndex.value = -1
}

// Effacer la sélection
const clearSelection = () => {
  selectedProduct.value = null
  searchQuery.value = ''
  emit('update:modelValue', null)
  showDropdown.value = false
}

// Formater la devise
const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

// Synchroniser avec la valeur externe
watch(() => props.modelValue, (newValue) => {
  if (newValue && newValue > 0) {
    const product = props.products.find(p => p.id === newValue)
    if (product) {
      selectedProduct.value = product
      searchQuery.value = product.name
    } else {
      selectedProduct.value = null
      searchQuery.value = ''
    }
  } else {
    selectedProduct.value = null
    searchQuery.value = ''
  }
}, { immediate: true })

// Mettre à jour la position du dropdown quand il est visible
watch(showDropdown, (isVisible) => {
  if (isVisible) {
    nextTick(() => {
      updateDropdownPosition()
    })
  }
})

// Fermer le dropdown si on clique en dehors
const handleClickOutside = (event: MouseEvent) => {
  const target = event.target as Node
  if (containerRef.value && !containerRef.value.contains(target)) {
    // Vérifier aussi si le clic est sur le dropdown
    const dropdown = document.querySelector('.product-dropdown-menu')
    if (dropdown && !dropdown.contains(target)) {
      showDropdown.value = false
    }
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  window.addEventListener('scroll', updateDropdownPosition, true)
  window.addEventListener('resize', updateDropdownPosition)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  window.removeEventListener('scroll', updateDropdownPosition, true)
  window.removeEventListener('resize', updateDropdownPosition)
})

defineExpose({
  focus: () => inputRef.value?.focus(),
  clear: clearSelection,
})
</script>

<style scoped>
.product-autocomplete {
  position: relative;
}

.dropdown-menu {
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 1000;
  margin-top: 0.125rem;
}

.dropdown-item {
  cursor: pointer;
  padding: 0.75rem;
}

.dropdown-item:hover:not(.disabled):not(.active) {
  background-color: #f8f9fa;
}

.dropdown-item.active {
  background-color: #0d6efd;
  color: white;
}

.dropdown-item.disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.dropdown-item.disabled:hover {
  background-color: transparent;
}
</style>

