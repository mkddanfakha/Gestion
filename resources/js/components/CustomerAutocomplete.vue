<template>
  <div ref="containerRef" class="customer-autocomplete position-relative">
    <div class="input-group">
      <input
        ref="inputRef"
        :value="searchQuery"
        type="text"
        class="form-control customer-autocomplete__input"
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
        v-if="selectedCustomer"
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
        class="customer-dropdown-menu dropdown-menu show"
        :class="{ 'customer-autocomplete__dropdown--inline': useInlineDropdown }"
        :style="dropdownStyle"
      >
        <div v-if="isLoading" class="dropdown-item text-muted">
          Recherche en cours...
        </div>
        <template v-else-if="filteredCustomers.length > 0">
          <div
            v-for="(customer, index) in filteredCustomers"
            :key="customer.id"
            class="dropdown-item"
            :class="{ active: index === selectedIndex }"
            @mousedown.prevent="handleItemMouseDown(customer, $event)"
            @touchstart.passive="handleItemTouchStart"
            @touchmove.passive="handleItemTouchMove"
            @touchend="handleItemTouchEnd(customer, $event)"
            @mouseenter="selectedIndex = index"
          >
            <div class="d-flex align-items-center justify-content-between">
              <div class="min-w-0">
                <div class="fw-medium text-truncate">{{ customer.name }}</div>
                <div v-if="customer.email || customer.phone" class="text-muted small mt-1 text-truncate">
                  <span v-if="customer.email">{{ customer.email }}</span>
                  <span v-if="customer.email && customer.phone"> • </span>
                  <span v-if="customer.phone">{{ customer.phone }}</span>
                </div>
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="normalizedQuery" class="dropdown-item text-muted">
          Aucun client trouvé pour « {{ searchQuery.trim() }} »
        </div>
        <div v-else class="dropdown-item text-muted">
          Aucun client disponible
        </div>
        <div
          v-if="allowCreate"
          class="dropdown-item customer-autocomplete__create-item border-top"
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
          Nouveau client
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { debounce } from 'lodash-es'
import { route } from '@/lib/routes'

interface Customer {
  id: number
  name: string
  email?: string | null
  phone?: string | null
}

interface Props {
  customers: Customer[]
  modelValue?: number | null
  placeholder?: string
  disabled?: boolean
  isInvalid?: boolean
  isValid?: boolean
  allowCreate?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Rechercher un client...',
  disabled: false,
  isInvalid: false,
  isValid: false,
  allowCreate: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | null): void
  (e: 'selected', customer: Customer): void
  (e: 'create-request', payload?: { initialName?: string }): void
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const containerRef = ref<HTMLElement | null>(null)
const dropdownRef = ref<HTMLElement | null>(null)
const searchQuery = ref('')
const showDropdown = ref(false)
const selectedIndex = ref(-1)
const selectedCustomer = ref<Customer | null>(null)
const isEditingSearch = ref(false)
const isLoading = ref(false)
const remoteCustomers = ref<Customer[]>([])
const useInlineDropdown = ref(false)
const dropdownPosition = ref({
  top: 0,
  left: 0,
  width: 0,
  maxHeight: 300,
  placement: 'bottom' as 'bottom' | 'top',
})

const catalogCustomers = computed(() => (Array.isArray(props.customers) ? props.customers : []))

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

const normalizedQuery = computed(() => normalizeSearchText(searchQuery.value))

function customerMatchesQuery(customer: Customer, query: string): boolean {
  if (!query) {
    return true
  }

  const fields = [customer.name, customer.email, customer.phone]

  return fields.some((field) => field && normalizeSearchText(String(field)).includes(query))
}

function filterClientCustomers(query: string): Customer[] {
  return catalogCustomers.value.filter((customer) => customerMatchesQuery(customer, query)).slice(0, 20)
}

const filteredCustomers = computed(() => {
  const source = remoteCustomers.value.length > 0 || normalizedQuery.value || showDropdown.value
    ? remoteCustomers.value
    : catalogCustomers.value

  return source.slice(0, 20)
})

const createOptionIndex = computed(() => {
  if (!props.allowCreate || isLoading.value) {
    return -1
  }

  return filteredCustomers.value.length
})

const selectableItemCount = computed(() => {
  let count = filteredCustomers.value.length
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
  if (document.activeElement === inputRef.value) {
    showDropdown.value = true
    updateDropdownPosition()
  }
}

const loadCustomers = async (query: string) => {
  isLoading.value = true

  try {
    const url = new URL(route('customers.autocomplete'), window.location.origin)
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

    remoteCustomers.value = await response.json()
  } catch {
    remoteCustomers.value = filterClientCustomers(query)
  } finally {
    isLoading.value = false
  }
}

const debouncedLoadCustomers = debounce((query: string) => {
  void loadCustomers(query)
}, 200)

const requestCustomers = (query = normalizedQuery.value) => {
  debouncedLoadCustomers.cancel()
  void loadCustomers(query)
}

const onSearchInput = (event: Event) => {
  const value = (event.target as HTMLInputElement).value
  searchQuery.value = value
  isEditingSearch.value = true
  selectedIndex.value = -1
  showDropdown.value = true
  updateDropdownPosition()
  debouncedLoadCustomers(value.trim())
}

const handleFocus = () => {
  isEditingSearch.value = true
  updateDropdownPosition()
  showDropdown.value = true
  requestCustomers(searchQuery.value.trim())
}

const handleBlur = () => {
  window.setTimeout(() => {
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

const findCustomerById = (customerId: number): Customer | undefined => {
  return (
    remoteCustomers.value.find((customer) => customer.id === customerId)
    ?? catalogCustomers.value.find((customer) => customer.id === customerId)
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

const handleItemTouchEnd = (customer: Customer, event: TouchEvent) => {
  if (itemTouch.value.moved) {
    return
  }

  event.preventDefault()
  suppressMouseDownUntil = Date.now() + 400
  selectCustomer(customer)
}

const handleItemMouseDown = (customer: Customer, event: MouseEvent) => {
  if (event.button !== 0 || Date.now() < suppressMouseDownUntil) {
    return
  }

  selectCustomer(customer)
}

const selectCustomer = (customer: Customer) => {
  isEditingSearch.value = false
  selectedCustomer.value = customer
  searchQuery.value = customer.name
  emit('update:modelValue', customer.id)
  emit('selected', customer)
  showDropdown.value = false
}

const requestCreateCustomer = () => {
  isEditingSearch.value = false
  showDropdown.value = false
  selectedIndex.value = -1
  emit('create-request', {
    initialName: searchQuery.value.trim() || undefined,
  })
}

const handleCreateMouseDown = (event: MouseEvent) => {
  if (event.button !== 0 || Date.now() < suppressMouseDownUntil) {
    return
  }

  requestCreateCustomer()
}

const handleCreateTouchEnd = (event: TouchEvent) => {
  if (itemTouch.value.moved) {
    return
  }

  event.preventDefault()
  suppressMouseDownUntil = Date.now() + 400
  requestCreateCustomer()
}

const selectHighlightedItem = () => {
  if (selectedIndex.value === createOptionIndex.value) {
    requestCreateCustomer()
    return
  }

  if (selectedIndex.value >= 0 && selectedIndex.value < filteredCustomers.value.length) {
    selectCustomer(filteredCustomers.value[selectedIndex.value])
  }
}

const selectFirstMatch = () => {
  if (selectedIndex.value >= 0) {
    selectHighlightedItem()
  } else if (filteredCustomers.value.length > 0) {
    selectCustomer(filteredCustomers.value[0])
  } else if (props.allowCreate) {
    requestCreateCustomer()
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

const closeDropdown = () => {
  showDropdown.value = false
  selectedIndex.value = -1
}

const clearSelection = () => {
  isEditingSearch.value = false
  selectedCustomer.value = null
  searchQuery.value = ''
  remoteCustomers.value = []
  emit('update:modelValue', null)
  showDropdown.value = false
  nextTick(() => inputRef.value?.focus())
}

const syncFromModelValue = () => {
  if (isEditingSearch.value) {
    return
  }

  if (props.modelValue && props.modelValue > 0) {
    const customer = findCustomerById(props.modelValue)
    if (customer) {
      selectedCustomer.value = customer
      searchQuery.value = customer.name
      return
    }
  }

  if (!searchQuery.value) {
    selectedCustomer.value = null
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
  window.visualViewport?.addEventListener('resize', keepDropdownOpenIfFocused)
  window.visualViewport?.addEventListener('scroll', updateDropdownPosition)
  window.visualViewport?.addEventListener('scroll', keepDropdownOpenIfFocused)
})

onUnmounted(() => {
  debouncedLoadCustomers.cancel()
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
  selectCustomer,
})
</script>
