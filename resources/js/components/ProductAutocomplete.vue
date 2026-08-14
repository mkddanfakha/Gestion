<template>
  <div ref="containerRef" class="product-autocomplete position-relative">
    <div class="input-group">
      <input
        ref="inputRef"
        :value="searchQuery"
        type="text"
        class="form-control product-autocomplete__input"
        :class="{ 'is-invalid': isInvalid, 'is-valid': isValid }"
        :placeholder="placeholder"
        :disabled="disabled"
        autocomplete="off"
        autocapitalize="off"
        autocorrect="off"
        spellcheck="false"
        enterkeyhint="search"
        inputmode="search"
        @input="onSearchInput"
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
        tabindex="-1"
        @click="clearSelection"
      >
        <i class="bi bi-x"></i>
      </button>
    </div>

    <Teleport to="body" :disabled="useInlineDropdown">
      <div
        v-if="showDropdown"
        ref="dropdownRef"
        class="product-dropdown-menu dropdown-menu show"
        :class="{ 'product-autocomplete__dropdown--inline': useInlineDropdown }"
        :style="dropdownStyle"
      >
        <div v-if="isLoading" class="dropdown-item text-muted">
          Recherche en cours...
        </div>
        <template v-else-if="filteredProducts.length > 0">
          <div v-if="excludedOnlyMatches" class="dropdown-item text-muted small border-bottom">
            <i class="bi bi-info-circle me-1"></i>
            Produit déjà présent dans cette vente.
          </div>
          <div
            v-for="(product, index) in filteredProducts"
            :key="product.id"
            class="dropdown-item"
            :class="{ active: index === selectedIndex, disabled: isProductSelected(product.id) || product.stock_quantity <= 0 }"
            @mousedown.prevent="handleItemMouseDown(product, $event)"
            @touchstart.passive="handleItemTouchStart"
            @touchmove.passive="handleItemTouchMove"
            @touchend="handleItemTouchEnd(product, $event)"
            @mouseenter="selectedIndex = index"
          >
            <div class="d-flex align-items-center">
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

              <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center justify-content-between gap-2">
                  <div class="fw-medium text-truncate">{{ product.name }}</div>
                  <div class="text-muted small flex-shrink-0">{{ formatCurrency(product.price) }}</div>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
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
                    Déjà ajouté
                  </span>
                </div>
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="normalizedQuery" class="dropdown-item text-muted">
          Aucun produit trouvé pour « {{ searchQuery.trim() }} »
        </div>
        <div v-else class="dropdown-item text-muted">
          Aucun produit disponible
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { debounce } from 'lodash-es'
import { formatCurrency } from '@/utils/currencyFormatter'
import { route } from '@/lib/routes'

interface Category {
  id: number
  name: string
  color: string
}

interface Product {
  id: number
  name: string
  sku?: string | null
  barcode?: string | null
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
const dropdownRef = ref<HTMLElement | null>(null)
const searchQuery = ref('')
const showDropdown = ref(false)
const selectedIndex = ref(-1)
const selectedProduct = ref<Product | null>(null)
const isEditingSearch = ref(false)
const isLoading = ref(false)
const remoteProducts = ref<Product[]>([])
const useInlineDropdown = ref(false)
const dropdownPosition = ref({
  top: 0,
  left: 0,
  width: 0,
  maxHeight: 320,
  placement: 'bottom' as 'bottom' | 'top',
})

const catalogProducts = computed(() => (Array.isArray(props.products) ? props.products : []))

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

const normalizedQuery = computed(() => normalizeSearchText(searchQuery.value))

function productMatchesQuery(product: Product, query: string): boolean {
  if (!query) {
    return true
  }

  const fields = [product.name, product.sku, product.barcode, product.category?.name]

  return fields.some((field) => field && normalizeSearchText(String(field)).includes(query))
}

function filterClientProducts(query: string): Product[] {
  return catalogProducts.value.filter((product) => productMatchesQuery(product, query)).slice(0, 20)
}

const isProductSelected = (productId: number): boolean => props.excludeProductIds.includes(productId)

const isProductSelectable = (product: Product): boolean => (
  !isProductSelected(product.id) && product.stock_quantity > 0
)

const filteredProducts = computed(() => {
  const source = remoteProducts.value.length > 0 || normalizedQuery.value || showDropdown.value
    ? remoteProducts.value
    : catalogProducts.value

  const results = source.slice(0, 20)

  if (!normalizedQuery.value) {
    return results.filter((product) => !props.excludeProductIds.includes(product.id))
  }

  return results
})

const excludedOnlyMatches = computed(() => (
  normalizedQuery.value.length > 0
  && filteredProducts.value.length > 0
  && filteredProducts.value.every((product) => isProductSelected(product.id))
))

const dropdownStyle = computed(() => {
  if (useInlineDropdown.value) {
    return {
      zIndex: 1060,
      maxHeight: 'min(50vh, 320px)',
      overflowY: 'auto',
    }
  }

  const { top, left, width, maxHeight, placement } = dropdownPosition.value
  const style: Record<string, string | number> = {
    position: 'fixed',
    left: `${left}px`,
    width: `${width}px`,
    maxHeight: `${maxHeight}px`,
    overflowY: 'auto',
    zIndex: 1060,
  }

  if (placement === 'bottom') {
    style.top = `${top}px`
  } else {
    style.bottom = `${window.innerHeight - top}px`
  }

  return style
})

const updateLayoutMode = () => {
  useInlineDropdown.value = window.matchMedia('(max-width: 767.98px)').matches
}

const updateDropdownPosition = () => {
  if (!containerRef.value || useInlineDropdown.value) {
    return
  }

  const rect = containerRef.value.getBoundingClientRect()
  const viewport = window.visualViewport
  const viewportTop = viewport?.offsetTop ?? 0
  const viewportHeight = viewport?.height ?? window.innerHeight
  const viewportBottom = viewportTop + viewportHeight

  const spaceBelow = viewportBottom - rect.bottom - 8
  const spaceAbove = rect.top - viewportTop - 8
  const placement = spaceBelow < 160 && spaceAbove > spaceBelow ? 'top' : 'bottom'
  const maxHeight = Math.max(120, Math.min(320, placement === 'bottom' ? spaceBelow : spaceAbove))

  dropdownPosition.value = {
    top: placement === 'bottom' ? rect.bottom + 4 : rect.top - 4,
    left: Math.max(8, rect.left),
    width: Math.max(rect.width, 280),
    maxHeight,
    placement,
  }
}

const loadProducts = async (query: string) => {
  isLoading.value = true

  try {
    const url = new URL(route('products.autocomplete'), window.location.origin)
    url.searchParams.set('q', query)

    const response = await fetch(url.toString(), {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      throw new Error('search_failed')
    }

    remoteProducts.value = await response.json()
  } catch {
    remoteProducts.value = filterClientProducts(query)
  } finally {
    isLoading.value = false
  }
}

const debouncedLoadProducts = debounce((query: string) => {
  void loadProducts(query)
}, 250)

const requestProducts = (query = normalizedQuery.value) => {
  debouncedLoadProducts.cancel()
  void loadProducts(query)
}

const onSearchInput = (event: Event) => {
  const value = (event.target as HTMLInputElement).value
  searchQuery.value = value
  isEditingSearch.value = true
  selectedIndex.value = -1
  showDropdown.value = true
  updateDropdownPosition()
  debouncedLoadProducts(value.trim())
}

const handleFocus = () => {
  isEditingSearch.value = true
  updateDropdownPosition()
  showDropdown.value = true
  requestProducts(searchQuery.value.trim())
}

const handleBlur = (event: FocusEvent) => {
  const relatedTarget = event.relatedTarget as Node | null

  window.setTimeout(() => {
    if (relatedTarget && dropdownRef.value?.contains(relatedTarget)) {
      return
    }
    if (document.activeElement === inputRef.value) {
      return
    }

    isEditingSearch.value = false
    showDropdown.value = false
  }, 200)
}

const findProductById = (productId: number): Product | undefined => {
  return (
    remoteProducts.value.find((product) => product.id === productId)
    ?? catalogProducts.value.find((product) => product.id === productId)
  )
}

const TOUCH_MOVE_THRESHOLD_PX = 10
const itemTouch = ref({ x: 0, y: 0, moved: false })
let suppressMouseDownUntil = 0

const handleItemTouchStart = (event: TouchEvent) => {
  const touch = event.touches[0]
  if (!touch) {
    return
  }

  itemTouch.value = {
    x: touch.clientX,
    y: touch.clientY,
    moved: false,
  }
}

const handleItemTouchMove = (event: TouchEvent) => {
  const touch = event.touches[0]
  if (!touch) {
    return
  }

  if (
    Math.abs(touch.clientX - itemTouch.value.x) > TOUCH_MOVE_THRESHOLD_PX
    || Math.abs(touch.clientY - itemTouch.value.y) > TOUCH_MOVE_THRESHOLD_PX
  ) {
    itemTouch.value.moved = true
  }
}

const handleItemTouchEnd = (product: Product, event: TouchEvent) => {
  if (itemTouch.value.moved) {
    return
  }

  event.preventDefault()
  suppressMouseDownUntil = Date.now() + 400
  selectProduct(product)
}

const handleItemMouseDown = (product: Product, event: MouseEvent) => {
  if (event.button !== 0 || Date.now() < suppressMouseDownUntil) {
    return
  }

  selectProduct(product)
}

const selectProduct = (product: Product) => {
  if (isProductSelected(product.id) || product.stock_quantity <= 0) {
    return
  }

  isEditingSearch.value = false
  selectedProduct.value = product
  searchQuery.value = product.name
  emit('update:modelValue', product.id)
  emit('selected', product)
  showDropdown.value = false
}

const selectFirstMatch = () => {
  const selectable = filteredProducts.value.filter(isProductSelectable)
  if (selectable.length === 0) {
    return
  }

  if (selectedIndex.value >= 0) {
    const highlighted = filteredProducts.value[selectedIndex.value]
    if (highlighted && isProductSelectable(highlighted)) {
      selectProduct(highlighted)
      return
    }
  }

  selectProduct(selectable[0])
}

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

const closeDropdown = () => {
  showDropdown.value = false
  selectedIndex.value = -1
}

const clearSelection = () => {
  isEditingSearch.value = false
  selectedProduct.value = null
  searchQuery.value = ''
  remoteProducts.value = []
  emit('update:modelValue', null)
  showDropdown.value = false
  nextTick(() => inputRef.value?.focus())
}

const syncFromModelValue = () => {
  if (isEditingSearch.value) {
    return
  }

  if (props.modelValue && props.modelValue > 0) {
    const product = findProductById(props.modelValue)
    if (product) {
      selectedProduct.value = product
      searchQuery.value = product.name
      return
    }
  }

  if (!isEditingSearch.value && !searchQuery.value) {
    selectedProduct.value = null
  }
}

watch(
  () => props.modelValue,
  (newValue, oldValue) => {
    if (newValue === oldValue || isEditingSearch.value) {
      return
    }

    syncFromModelValue()
  },
)

watch(showDropdown, (isVisible) => {
  if (isVisible) {
    nextTick(() => {
      updateDropdownPosition()
    })
  }
})

const handlePointerDownOutside = (event: Event) => {
  const target = event.target as Node | null
  if (!target) {
    return
  }
  if (containerRef.value?.contains(target)) {
    return
  }
  if (dropdownRef.value?.contains(target)) {
    return
  }
  showDropdown.value = false
}

onMounted(() => {
  updateLayoutMode()
  syncFromModelValue()
  document.addEventListener('mousedown', handlePointerDownOutside)
  window.addEventListener('scroll', updateDropdownPosition, true)
  window.addEventListener('resize', updateLayoutMode)
  window.addEventListener('resize', updateDropdownPosition)
  window.visualViewport?.addEventListener('resize', updateDropdownPosition)
  window.visualViewport?.addEventListener('scroll', updateDropdownPosition)
})

onUnmounted(() => {
  debouncedLoadProducts.cancel()
  document.removeEventListener('mousedown', handlePointerDownOutside)
  window.removeEventListener('scroll', updateDropdownPosition, true)
  window.removeEventListener('resize', updateLayoutMode)
  window.removeEventListener('resize', updateDropdownPosition)
  window.visualViewport?.removeEventListener('resize', updateDropdownPosition)
  window.visualViewport?.removeEventListener('scroll', updateDropdownPosition)
})

defineExpose({
  focus: () => inputRef.value?.focus(),
  clear: clearSelection,
})
</script>
