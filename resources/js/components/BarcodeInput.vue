<script setup lang="ts">
import {
  attachBarcodeKeyboardScannerToInput,
  refocusBarcodeInput,
  type BarcodeKeyboardScanSource,
} from '@/services/barcodeKeyboardScanner'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

type FeedbackState = 'idle' | 'loading' | 'success' | 'not-found' | 'error'

const props = withDefaults(
  defineProps<{
    modelValue?: string
    disabled?: boolean
    loading?: boolean
    label?: string
    placeholder?: string
    hint?: string
    autofocus?: boolean
    clearable?: boolean
    retainOnScan?: boolean
    showSubmitButton?: boolean
    inputClass?: string
    prependIconClass?: string
    inputAriaLabel?: string
    feedbackState?: FeedbackState
    feedbackMessage?: string
    inputId?: string
  }>(),
  {
    modelValue: '',
    disabled: false,
    loading: false,
    label: 'Code-barres',
    placeholder: 'Scanner ou saisir un code-barres…',
    hint: 'Douchette USB/Bluetooth (mode clavier) ou saisie manuelle + Entrée',
    autofocus: true,
    clearable: true,
    retainOnScan: false,
    showSubmitButton: true,
    prependIconClass: 'bi-upc-scan',
    inputAriaLabel: 'Code-barres',
    feedbackState: 'idle',
    feedbackMessage: '',
    inputId: undefined,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  scanned: [barcode: string, source: BarcodeKeyboardScanSource]
  clear: []
  submit: [barcode: string]
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const localValue = ref(props.modelValue)
const generatedId = `barcode-input-${Math.random().toString(36).slice(2, 9)}`
const inputId = computed(() => props.inputId ?? generatedId)

let scannerSession: ReturnType<typeof attachBarcodeKeyboardScannerToInput> | null = null

watch(
  () => props.modelValue,
  (value) => {
    if (value !== localValue.value) {
      localValue.value = value
    }
  },
)

watch(localValue, (value) => {
  emit('update:modelValue', value)
})

const statusClass = computed(() => {
  switch (props.feedbackState) {
    case 'success':
      return 'barcode-input__status--success'
    case 'not-found':
      return 'barcode-input__status--warning'
    case 'error':
      return 'barcode-input__status--danger'
    case 'loading':
      return 'barcode-input__status--info'
    default:
      return ''
  }
})

function handleScan(barcode: string, source: BarcodeKeyboardScanSource): void {
  if (props.retainOnScan) {
    localValue.value = barcode
  } else {
    localValue.value = ''
  }

  emit('scanned', barcode, source)
  emit('submit', barcode)
}

function submitManual(): void {
  if (!localValue.value.trim() || props.disabled || props.loading) {
    return
  }

  scannerSession?.submitManual(localValue.value)

  if (!props.retainOnScan) {
    localValue.value = ''
  }
}

function clearInput(): void {
  localValue.value = ''
  emit('clear')
  refocusBarcodeInput(inputRef.value)
}

function focusInput(): void {
  refocusBarcodeInput(inputRef.value)
}

defineExpose({
  focus: focusInput,
  clear: clearInput,
})

onMounted(() => {
  if (!inputRef.value) {
    return
  }

  scannerSession = attachBarcodeKeyboardScannerToInput({
    input: inputRef.value,
    autofocus: props.autofocus,
    onScan: handleScan,
  })
})

onBeforeUnmount(() => {
  scannerSession?.destroy()
  scannerSession = null
})
</script>

<template>
  <div class="barcode-input">
    <label v-if="label" :for="inputId" class="form-label barcode-input__label">
      <i class="bi bi-upc-scan me-1 text-muted" aria-hidden="true"></i>
      {{ label }}
    </label>

    <div class="input-group barcode-input__group">
      <span class="input-group-text" aria-hidden="true">
        <i class="bi" :class="prependIconClass"></i>
      </span>
      <input
        :id="inputId"
        ref="inputRef"
        v-model="localValue"
        type="text"
        class="form-control barcode-input__field font-monospace"
        :class="inputClass"
        :placeholder="placeholder"
        :disabled="disabled || loading"
        inputmode="search"
        autocomplete="off"
        autocapitalize="off"
        autocorrect="off"
        spellcheck="false"
        enterkeyhint="search"
        :aria-label="inputAriaLabel"
      />
      <button
        v-if="showSubmitButton"
        type="button"
        class="btn btn-outline-primary"
        :disabled="disabled || loading || !localValue.trim()"
        aria-label="Valider le code-barres"
        @click="submitManual"
      >
        <i class="bi bi-check2" aria-hidden="true"></i>
      </button>
      <button
        v-if="clearable"
        type="button"
        class="btn btn-outline-secondary"
        :disabled="disabled || loading || !localValue"
        aria-label="Effacer"
        @click="clearInput"
      >
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <p v-if="hint" class="form-text barcode-input__hint mb-0 mt-2">{{ hint }}</p>

    <div
      v-if="feedbackMessage"
      class="barcode-input__status mt-2"
      :class="statusClass"
      role="status"
      aria-live="polite"
    >
      <span v-if="feedbackState === 'loading'" class="spinner-border spinner-border-sm me-2" role="status"></span>
      <i
        v-else-if="feedbackState === 'success'"
        class="bi bi-check-circle-fill me-1"
        aria-hidden="true"
      ></i>
      <i
        v-else-if="feedbackState === 'not-found'"
        class="bi bi-exclamation-triangle-fill me-1"
        aria-hidden="true"
      ></i>
      {{ feedbackMessage }}
    </div>
  </div>
</template>

<style scoped>
.barcode-input__status {
  font-size: 0.925rem;
}

.barcode-input__status--success {
  color: var(--bs-success);
}

.barcode-input__status--warning {
  color: var(--bs-warning-text-emphasis, #997404);
}

.barcode-input__status--danger {
  color: var(--bs-danger);
}

.barcode-input__status--info {
  color: var(--bs-info-text-emphasis, #055160);
}

.barcode-input__hint {
  color: var(--bs-secondary-color);
}
</style>
