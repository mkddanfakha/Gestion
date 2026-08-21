<script setup lang="ts">
import BarcodeInput from '@/components/BarcodeInput.vue'
import { useProductBarcodeField } from '@/composables/useProductBarcodeField'
import { computed, onMounted, toRef, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue?: string
    productId?: number
    serverError?: string
    disabled?: boolean
  }>(),
  {
    modelValue: '',
    productId: undefined,
    serverError: '',
    disabled: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'update:clientError': [value: string]
}>()

const barcode = computed({
  get: () => props.modelValue,
  set: (value: string) => emit('update:modelValue', value),
})

const excludeProductId = toRef(() => props.productId)

const {
  feedbackState,
  feedbackMessage,
  clientError,
  handleBarcodeScanned,
  handleBarcodeInput,
  validateForSubmit,
  checkAvailability,
} = useProductBarcodeField({
  barcode,
  excludeProductId,
})

watch(clientError, (value) => {
  emit('update:clientError', value)
})

watch(
  () => props.modelValue,
  (value) => {
    handleBarcodeInput(value)
  },
)

onMounted(() => {
  if (props.modelValue) {
    void checkAvailability(props.modelValue)
  }
})

const inputFeedbackState = computed(() => {
  if (props.serverError || clientError.value) {
    return 'error'
  }

  switch (feedbackState.value) {
    case 'available':
      return 'success'
    case 'checking':
      return 'loading'
    case 'duplicate':
    case 'invalid':
      return 'error'
    default:
      return 'idle'
  }
})

const inputFeedbackMessage = computed(() => {
  if (props.serverError || clientError.value) {
    return ''
  }

  return feedbackMessage.value
})

const displayError = computed(() => props.serverError || clientError.value)

defineExpose({
  validateForSubmit,
  checkAvailability,
})
</script>

<template>
  <div class="product-barcode-field">
    <BarcodeInput
      v-model="barcode"
      :disabled="disabled"
      :autofocus="false"
      retain-on-scan
      :show-submit-button="false"
      :feedback-state="inputFeedbackState"
      :feedback-message="inputFeedbackMessage"
      label=""
      placeholder="Scanner ou saisir un code-barres…"
      hint="Saisie manuelle ou douchette USB/Bluetooth (mode clavier)."
      :input-class="displayError ? 'is-invalid' : undefined"
      @scanned="handleBarcodeScanned"
    />
    <div v-if="displayError" class="invalid-feedback d-block">
      {{ displayError }}
    </div>
  </div>
</template>
