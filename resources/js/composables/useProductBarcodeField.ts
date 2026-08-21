import { onBeforeUnmount, ref, type Ref } from 'vue'
import { route } from '@/lib/routes'
import { isValidBarcode, normalizeBarcode } from '@/utils/barcodeNormalizer'

export type ProductBarcodeFeedbackState = 'idle' | 'checking' | 'available' | 'duplicate' | 'invalid'

const DUPLICATE_MESSAGE = 'Ce code-barres est déjà associé à un autre produit.'
const INVALID_MESSAGE = 'Code-barres invalide.'
const AVAILABLE_MESSAGE = 'Code-barres disponible.'

interface UseProductBarcodeFieldOptions {
  barcode: Ref<string>
  excludeProductId?: Ref<number | undefined>
}

export function useProductBarcodeField(options: UseProductBarcodeFieldOptions) {
  const feedbackState = ref<ProductBarcodeFeedbackState>('idle')
  const feedbackMessage = ref('')
  const clientError = ref('')

  let checkToken = 0
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  function clearScheduledCheck(): void {
    if (debounceTimer) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
  }

  function validateFormat(value: string): string | null {
    const normalized = normalizeBarcode(value)

    if (!normalized) {
      return null
    }

    if (normalized.length > 255) {
      return 'Le code-barres ne peut pas dépasser 255 caractères'
    }

    if (!isValidBarcode(normalized)) {
      return INVALID_MESSAGE
    }

    return null
  }

  async function checkAvailability(rawValue: string): Promise<void> {
    const normalized = normalizeBarcode(rawValue)

    if (!normalized) {
      feedbackState.value = 'idle'
      feedbackMessage.value = ''
      clientError.value = ''
      return
    }

    const formatError = validateFormat(normalized)

    if (formatError) {
      feedbackState.value = 'invalid'
      feedbackMessage.value = formatError
      clientError.value = formatError
      return
    }

    const token = ++checkToken
    feedbackState.value = 'checking'
    feedbackMessage.value = 'Vérification…'
    clientError.value = ''

    try {
      const params: Record<string, string | number> = { barcode: normalized }

      if (options.excludeProductId?.value) {
        params.exclude = options.excludeProductId.value
      }

      const response = await fetch(route('products.barcode.availability', params), {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      })

      if (token !== checkToken) {
        return
      }

      if (response.status === 422) {
        feedbackState.value = 'invalid'
        feedbackMessage.value = INVALID_MESSAGE
        clientError.value = INVALID_MESSAGE
        return
      }

      if (!response.ok) {
        feedbackState.value = 'idle'
        feedbackMessage.value = ''
        return
      }

      const payload = (await response.json()) as { available: boolean; barcode: string }

      if (payload.available) {
        feedbackState.value = 'available'
        feedbackMessage.value = AVAILABLE_MESSAGE
        clientError.value = ''
        return
      }

      feedbackState.value = 'duplicate'
      feedbackMessage.value = DUPLICATE_MESSAGE
      clientError.value = DUPLICATE_MESSAGE
    } catch {
      if (token === checkToken) {
        feedbackState.value = 'idle'
        feedbackMessage.value = ''
      }
    }
  }

  function scheduleAvailabilityCheck(value: string): void {
    clearScheduledCheck()
    debounceTimer = setTimeout(() => {
      void checkAvailability(value)
    }, 350)
  }

  function handleBarcodeScanned(barcode: string): void {
    options.barcode.value = barcode
    void checkAvailability(barcode)
  }

  function handleBarcodeInput(value: string): void {
    const formatError = validateFormat(value)

    if (formatError) {
      feedbackState.value = 'invalid'
      feedbackMessage.value = formatError
      clientError.value = formatError
      return
    }

    clientError.value = ''
    scheduleAvailabilityCheck(value)
  }

  function validateForSubmit(value: string): string | null {
    const formatError = validateFormat(value)

    if (formatError) {
      return formatError
    }

    if (clientError.value) {
      return clientError.value
    }

    return null
  }

  onBeforeUnmount(() => {
    clearScheduledCheck()
    checkToken += 1
  })

  return {
    feedbackState,
    feedbackMessage,
    clientError,
    handleBarcodeScanned,
    handleBarcodeInput,
    validateForSubmit,
    checkAvailability,
    validateFormat,
  }
}
