<template>
  <div ref="containerRef" class="customer-autocomplete position-relative" style="z-index: 1;">
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
        v-if="selectedCustomer"
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
        v-if="showDropdown && filteredCustomers.length > 0"
        class="customer-dropdown-menu"
        :style="{
          position: 'absolute',
          top: dropdownPosition.top + 'px',
          left: dropdownPosition.left + 'px',
          width: dropdownPosition.width + 'px',
          zIndex: 1050,
          maxHeight: '300px',
          overflowY: 'auto'
        }"
      >
        <div
          v-for="(customer, index) in filteredCustomers"
          :key="customer.id"
          class="dropdown-item"
          :class="{ 'active': index === selectedIndex }"
          @mousedown.prevent="selectCustomer(customer)"
          @mouseenter="selectedIndex = index"
        >
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-medium">{{ customer.name }}</div>
              <div v-if="customer.email || customer.phone" class="text-muted small mt-1">
                <span v-if="customer.email">{{ customer.email }}</span>
                <span v-if="customer.email && customer.phone"> • </span>
                <span v-if="customer.phone">{{ customer.phone }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- Message si aucun résultat -->
      <div
        v-if="showDropdown && searchQuery && filteredCustomers.length === 0"
        class="customer-dropdown-menu"
        :style="{
          position: 'absolute',
          top: dropdownPosition.top + 'px',
          left: dropdownPosition.left + 'px',
          width: dropdownPosition.width + 'px',
          zIndex: 1050
        }"
      >
        <div class="dropdown-item text-muted">
          Aucun client trouvé
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

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
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Rechercher un client...',
  disabled: false,
  isInvalid: false,
  isValid: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | null): void
  (e: 'selected', customer: Customer): void
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const containerRef = ref<HTMLElement | null>(null)
const searchQuery = ref('')
const showDropdown = ref(false)
const selectedIndex = ref(-1)
const selectedCustomer = ref<Customer | null>(null)
const dropdownPosition = ref({ top: 0, left: 0, width: 0 })

// Clients filtrés selon la recherche
const filteredCustomers = computed(() => {
  if (!searchQuery.value) {
    return props.customers.slice(0, 10)
  }
  
  const query = searchQuery.value.toLowerCase()
  return props.customers.filter(customer => {
    const matchesName = customer.name.toLowerCase().includes(query)
    const matchesEmail = customer.email?.toLowerCase().includes(query) || false
    const matchesPhone = customer.phone?.toLowerCase().includes(query) || false
    return matchesName || matchesEmail || matchesPhone
  }).slice(0, 10)
})

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

// Sélectionner un client
const selectCustomer = (customer: Customer) => {
  selectedCustomer.value = customer
  searchQuery.value = customer.name
  emit('update:modelValue', customer.id)
  emit('selected', customer)
  showDropdown.value = false
}

// Sélectionner le premier client correspondant
const selectFirstMatch = () => {
  if (filteredCustomers.value.length > 0 && selectedIndex.value >= 0) {
    selectCustomer(filteredCustomers.value[selectedIndex.value])
  } else if (filteredCustomers.value.length > 0) {
    selectCustomer(filteredCustomers.value[0])
  }
}

// Navigation au clavier
const navigateDown = () => {
  if (selectedIndex.value < filteredCustomers.value.length - 1) {
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
  selectedCustomer.value = null
  searchQuery.value = ''
  emit('update:modelValue', null)
  showDropdown.value = false
}

// Synchroniser avec la valeur externe
watch(() => props.modelValue, (newValue) => {
  if (newValue && newValue > 0) {
    const customer = props.customers.find(c => c.id === newValue)
    if (customer) {
      selectedCustomer.value = customer
      searchQuery.value = customer.name
    } else {
      selectedCustomer.value = null
      searchQuery.value = ''
    }
  } else {
    selectedCustomer.value = null
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
    const dropdown = document.querySelector('.customer-dropdown-menu')
    if (dropdown && !dropdown.contains(target)) {
      showDropdown.value = false
    } else if (!dropdown) {
      showDropdown.value = false
    }
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

defineExpose({
  focus: () => inputRef.value?.focus(),
  clear: clearSelection,
})
</script>

<style scoped>
.customer-autocomplete {
  position: relative;
  z-index: 1;
}

.customer-dropdown-menu {
  background-color: white;
  border: 1px solid rgba(0, 0, 0, 0.15);
  border-radius: 0.375rem;
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
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

