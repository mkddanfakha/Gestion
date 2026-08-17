<template>
  <div ref="containerRef" class="supplier-combobox position-relative">
    <div class="input-group">
      <input
        ref="inputRef"
        :value="searchQuery"
        type="text"
        class="form-control supplier-combobox__input"
        :class="{ 'is-invalid': isInvalid, 'is-valid': isValid }"
        :placeholder="placeholder"
        :disabled="disabled"
        role="combobox"
        :aria-expanded="showDropdown"
        :aria-controls="dropdownId"
        :aria-label="ariaLabel"
        aria-autocomplete="list"
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
        @keydown.escape="closeDropdownOnEscape"
      />
      <button
        v-if="selectedSupplier && !disabled"
        type="button"
        class="btn btn-outline-secondary"
        tabindex="-1"
        aria-label="Effacer la sélection du fournisseur"
        @click="clearSelection"
      >
        <i class="bi bi-x"></i>
      </button>
    </div>

    <Teleport to="body" :disabled="useInlineDropdown">
      <div
        v-if="showDropdown"
        :id="dropdownId"
        ref="dropdownRef"
        class="supplier-dropdown-menu dropdown-menu show"
        :class="{ 'supplier-combobox__dropdown--inline': useInlineDropdown }"
        :style="dropdownStyle"
        role="listbox"
      >
        <div v-if="isLoading" class="dropdown-item text-muted">
          Recherche en cours...
        </div>
        <template v-else-if="filteredSuppliers.length > 0">
          <div
            v-for="(supplier, index) in filteredSuppliers"
            :key="supplier.id"
            class="dropdown-item"
            :class="{ active: index === selectedIndex }"
            role="option"
            :aria-selected="index === selectedIndex"
            @mousedown.prevent="handleItemMouseDown(supplier, $event)"
            @touchstart.passive="handleItemTouchStart"
            @touchmove.passive="handleItemTouchMove"
            @touchend="handleItemTouchEnd(supplier, $event)"
            @mouseenter="selectedIndex = index"
          >
            <div class="min-w-0">
              <div class="fw-medium text-truncate">{{ supplier.name }}</div>
              <div v-if="displayPhone(supplier)" class="text-muted small mt-1 text-truncate">
                <i class="bi bi-telephone me-1" aria-hidden="true"></i>
                {{ displayPhone(supplier) }}
              </div>
              <div v-if="supplier.email" class="text-muted small mt-1 text-truncate">
                <i class="bi bi-envelope me-1" aria-hidden="true"></i>
                {{ supplier.email }}
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="normalizedQuery" class="dropdown-item text-muted">
          Aucun fournisseur trouvé pour « {{ searchQuery.trim() }} »
        </div>
        <div v-else class="dropdown-item text-muted">
          Aucun fournisseur disponible
        </div>
        <div
          v-if="allowCreate"
          class="dropdown-item supplier-combobox__create-item border-top"
          :class="{ active: selectedIndex === createOptionIndex }"
          role="button"
          tabindex="0"
          @mousedown.prevent="handleCreateMouseDown"
          @touchstart.passive="handleItemTouchStart"
          @touchmove.passive="handleItemTouchMove"
          @touchend="handleCreateTouchEnd($event)"
          @mouseenter="selectedIndex = createOptionIndex"
        >
          <i class="bi bi-plus-circle me-2"></i>
          Ajouter un nouveau fournisseur
        </div>
      </div>
    </Teleport>

    <SupplierQuickCreateModal
      v-model:open="showCreateModal"
      :initial-name="createInitialName"
      @created="handleSupplierCreated"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { debounce } from 'lodash-es'
import { route } from '@/lib/routes'
import SupplierQuickCreateModal from '@/components/suppliers/SupplierQuickCreateModal.vue'
import type { CreatedSupplier } from '@/components/suppliers/SupplierQuickCreateModal.vue'
import type { SupplierOption } from '@/types/supplier'

interface Props {
  suppliers: SupplierOption[]
  modelValue?: number | null
  placeholder?: string
  disabled?: boolean
  isInvalid?: boolean
  isValid?: boolean
  allowCreate?: boolean
  ariaLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Rechercher un fournisseur...',
  disabled: false,
  isInvalid: false,
  isValid: false,
  allowCreate: false,
  ariaLabel: 'Fournisseur',
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | null): void
  (e: 'selected', supplier: SupplierOption): void
  (e: 'created', supplier: SupplierOption): void
}>()

const dropdownId = `supplier-combobox-${Math.random().toString(36).slice(2, 9)}`
const inputRef = ref<HTMLInputElement | null>(null)
const containerRef = ref<HTMLElement | null>(null)
const dropdownRef = ref<HTMLElement | null>(null)
const searchQuery = ref('')
const showDropdown = ref(false)
const selectedIndex = ref(-1)
const selectedSupplier = ref<SupplierOption | null>(null)
const isEditingSearch = ref(false)
const isLoading = ref(false)
const remoteSuppliers = ref<SupplierOption[]>([])
const useInlineDropdown = ref(false)
const showCreateModal = ref(false)
const createInitialName = ref('')
const dropdownPosition = ref({
  top: 0,
  left: 0,
  width: 0,
  maxHeight: 300,
  placement: 'bottom' as 'bottom' | 'top',
})

const catalogSuppliers = computed(() => (Array.isArray(props.suppliers) ? props.suppliers : []))

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

const normalizedQuery = computed(() => normalizeSearchText(searchQuery.value))

function supplierMatchesQuery(supplier: SupplierOption, query: string): boolean {
  if (!query) {
    return true
  }

  const fields = [
    supplier.name,
    supplier.contact_person,
    supplier.email,
    supplier.phone,
    supplier.mobile,
  ]

  return fields.some((field) => field && normalizeSearchText(String(field)).includes(query))
}

function filterLocalSuppliers(query: string): SupplierOption[] {
  return catalogSuppliers.value.filter((supplier) => supplierMatchesQuery(supplier, query)).slice(0, 20)
}

const filteredSuppliers = computed(() => {
  const source = remoteSuppliers.value.length > 0 || normalizedQuery.value || showDropdown.value
    ? remoteSuppliers.value
    : catalogSuppliers.value

  return source.slice(0, 20)
})

const createOptionIndex = computed(() => {
  if (!props.allowCreate || isLoading.value) {
    return -1
  }

  return filteredSuppliers.value.length
})

const selectableItemCount = computed(() => {
  let count = filteredSuppliers.value.length
  if (props.allowCreate && !isLoading.value) {
    count += 1
  }

  return count
})

const dropdownStyle = computed(() => {
  if (useInlineDropdown.value) {
    return {
      zIndex: 1060,
      maxHeight: 'min(50vh, 300px)',
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

const displayPhone = (supplier: SupplierOption): string | null => {
  return supplier.phone?.trim() || supplier.mobile?.trim() || null
}

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
  const maxHeight = Math.max(120, Math.min(300, placement === 'bottom' ? spaceBelow : spaceAbove))

  dropdownPosition.value = {
    top: placement === 'bottom' ? rect.bottom + 4 : rect.top - 4,
    left: Math.max(8, rect.left),
    width: Math.max(rect.width, 260),
    maxHeight,
    placement,
  }
}

const keepDropdownOpenIfFocused = () => {
  if (Date.now() < suppressDropdownOpenUntil) {
    return
  }

  // Ne pas rouvrir après une sélection : le viewport mobile (clavier) déclenche souvent resize/scroll.
  if (!isEditingSearch.value && !showDropdown.value) {
    return
  }

  if (document.activeElement === inputRef.value) {
    showDropdown.value = true
    updateDropdownPosition()
  }
}

const loadSuppliers = async (query: string) => {
  isLoading.value = true

  try {
    const url = new URL(route('suppliers.autocomplete'), window.location.origin)
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

    remoteSuppliers.value = await response.json()
  } catch {
    remoteSuppliers.value = filterLocalSuppliers(query)
  } finally {
    isLoading.value = false
  }
}

const debouncedLoadSuppliers = debounce((query: string) => {
  void loadSuppliers(query)
}, 200)

const requestSuppliers = (query = normalizedQuery.value) => {
  debouncedLoadSuppliers.cancel()
  void loadSuppliers(query)
}

const onSearchInput = (event: Event) => {
  const value = (event.target as HTMLInputElement).value
  searchQuery.value = value
  isEditingSearch.value = true
  selectedIndex.value = -1
  showDropdown.value = true
  updateDropdownPosition()
  debouncedLoadSuppliers(value.trim())
}

const handleFocus = () => {
  if (props.disabled) {
    return
  }

  if (Date.now() < suppressDropdownOpenUntil) {
    return
  }

  isEditingSearch.value = true
  updateDropdownPosition()
  showDropdown.value = true
  requestSuppliers(searchQuery.value.trim())
}

const handleBlur = () => {
  window.setTimeout(() => {
    if (Date.now() < suppressDropdownOpenUntil) {
      showDropdown.value = false
      isEditingSearch.value = false
      return
    }

    if (document.activeElement === inputRef.value) {
      return
    }
    if (dropdownRef.value?.contains(document.activeElement)) {
      return
    }

    isEditingSearch.value = false
    showDropdown.value = false
  }, 200)
}

const findSupplierById = (supplierId: number): SupplierOption | undefined => {
  return (
    remoteSuppliers.value.find((supplier) => supplier.id === supplierId)
    ?? catalogSuppliers.value.find((supplier) => supplier.id === supplierId)
  )
}

const TOUCH_MOVE_THRESHOLD_PX = 10
const itemTouch = ref({ x: 0, y: 0, moved: false })
let suppressMouseDownUntil = 0
let suppressDropdownOpenUntil = 0

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

const handleItemTouchEnd = (supplier: SupplierOption, event: TouchEvent) => {
  if (itemTouch.value.moved) {
    return
  }

  event.preventDefault()
  suppressMouseDownUntil = Date.now() + 400
  selectSupplier(supplier)
}

const handleItemMouseDown = (supplier: SupplierOption, event: MouseEvent) => {
  if (event.button !== 0 || Date.now() < suppressMouseDownUntil) {
    return
  }

  selectSupplier(supplier)
}

const closeDropdown = (options: { suppressReopenMs?: number } = {}) => {
  debouncedLoadSuppliers.cancel()
  isEditingSearch.value = false
  showDropdown.value = false
  selectedIndex.value = -1

  if (options.suppressReopenMs && options.suppressReopenMs > 0) {
    suppressDropdownOpenUntil = Date.now() + options.suppressReopenMs
  }
}

const selectSupplier = (supplier: SupplierOption) => {
  selectedSupplier.value = supplier
  searchQuery.value = supplier.name
  emit('update:modelValue', supplier.id)
  emit('selected', supplier)
  closeDropdown({ suppressReopenMs: 500 })

  if (useInlineDropdown.value) {
    nextTick(() => inputRef.value?.blur())
  }
}

const requestCreateSupplier = () => {
  if (!props.allowCreate || props.disabled) {
    return
  }

  createInitialName.value = searchQuery.value.trim()
  showCreateModal.value = true
  closeDropdown({ suppressReopenMs: 500 })

  if (useInlineDropdown.value) {
    nextTick(() => inputRef.value?.blur())
  }
}

const handleCreateMouseDown = (event: MouseEvent) => {
  if (event.button !== 0 || Date.now() < suppressMouseDownUntil) {
    return
  }

  requestCreateSupplier()
}

const handleCreateTouchEnd = (event: TouchEvent) => {
  if (itemTouch.value.moved) {
    return
  }

  event.preventDefault()
  suppressMouseDownUntil = Date.now() + 400
  requestCreateSupplier()
}

const handleSupplierCreated = (supplier: CreatedSupplier) => {
  selectSupplier(supplier)
  emit('created', supplier)
}

const selectHighlightedItem = () => {
  if (selectedIndex.value === createOptionIndex.value) {
    requestCreateSupplier()
    return
  }

  if (selectedIndex.value >= 0 && selectedIndex.value < filteredSuppliers.value.length) {
    selectSupplier(filteredSuppliers.value[selectedIndex.value])
  }
}

const selectFirstMatch = () => {
  if (selectedIndex.value >= 0) {
    selectHighlightedItem()
  } else if (filteredSuppliers.value.length > 0) {
    selectSupplier(filteredSuppliers.value[0])
  } else if (props.allowCreate) {
    requestCreateSupplier()
  }
}

const navigateDown = () => {
  if (selectableItemCount.value === 0) {
    return
  }

  if (selectedIndex.value < selectableItemCount.value - 1) {
    selectedIndex.value++
  }
}

const navigateUp = () => {
  if (selectedIndex.value > 0) {
    selectedIndex.value--
  }
}

const closeDropdownOnEscape = () => {
  closeDropdown()
}

const clearSelection = () => {
  isEditingSearch.value = false
  selectedSupplier.value = null
  searchQuery.value = ''
  remoteSuppliers.value = []
  emit('update:modelValue', null)
  closeDropdown()
  nextTick(() => inputRef.value?.focus())
}

const syncFromModelValue = () => {
  if (isEditingSearch.value) {
    return
  }

  if (props.modelValue && props.modelValue > 0) {
    const supplier = findSupplierById(props.modelValue)
    if (supplier) {
      selectedSupplier.value = supplier
      searchQuery.value = supplier.name
      return
    }
  }

  if (!searchQuery.value) {
    selectedSupplier.value = null
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

watch(
  () => props.suppliers,
  () => {
    syncFromModelValue()
  },
  { deep: true },
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
  window.visualViewport?.addEventListener('resize', keepDropdownOpenIfFocused)
  window.visualViewport?.addEventListener('scroll', updateDropdownPosition)
  window.visualViewport?.addEventListener('scroll', keepDropdownOpenIfFocused)
})

onUnmounted(() => {
  debouncedLoadSuppliers.cancel()
  document.removeEventListener('mousedown', handlePointerDownOutside)
  window.removeEventListener('scroll', updateDropdownPosition, true)
  window.removeEventListener('resize', updateLayoutMode)
  window.removeEventListener('resize', updateDropdownPosition)
  window.visualViewport?.removeEventListener('resize', updateDropdownPosition)
  window.visualViewport?.removeEventListener('resize', keepDropdownOpenIfFocused)
  window.visualViewport?.removeEventListener('scroll', updateDropdownPosition)
  window.visualViewport?.removeEventListener('scroll', keepDropdownOpenIfFocused)
})

defineExpose({
  focus: () => inputRef.value?.focus(),
  clear: clearSelection,
  selectSupplier,
})
</script>
