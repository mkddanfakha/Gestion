import { ref, type Ref } from 'vue'
import { useProductBarcodeLookup } from '@/composables/useProductBarcodeLookup'
import { ProductBarcodeLookupError } from '@/types/product'
import { normalizeBarcode } from '@/utils/barcodeNormalizer'

export type DocumentProductBarcodeAction = 'added' | 'incremented'

interface DocumentProductBarcodeItem {
  product_id: number
}

interface UseDocumentProductBarcodeOptions<TProduct extends { id: number }, TItem extends DocumentProductBarcodeItem> {
  items: Ref<TItem[]>
  getProduct: (productId: number) => TProduct | undefined
  addItem: () => void
  handleProductSelected: (product: TProduct, index: number) => void
  incrementQuantity: (index: number) => void
  canIncrementQuantity: (index: number) => boolean
  validateProduct?: (product: TProduct) => string | null
  onNotFound?: (barcode: string) => void
  onError?: (message: string) => void
  onSuccess?: (product: TProduct, index: number, action: DocumentProductBarcodeAction) => void
}

const DUPLICATE_EVENT_WINDOW_MS = 150

export function useDocumentProductBarcode<TProduct extends { id: number; name?: string; barcode?: string | null }, TItem extends DocumentProductBarcodeItem>(
  options: UseDocumentProductBarcodeOptions<TProduct, TItem>,
) {
  const { lookupProductByBarcode } = useProductBarcodeLookup()
  const isProcessing = ref(false)
  const lastProcessedScan = ref<{ barcode: string; at: number } | null>(null)

  const handleBarcodeDetected = async (rawBarcode: string) => {
    const barcode = normalizeBarcode(rawBarcode)

    if (!barcode || isProcessing.value) {
      return
    }

    const now = Date.now()
    if (
      lastProcessedScan.value?.barcode === barcode
      && now - lastProcessedScan.value.at < DUPLICATE_EVENT_WINDOW_MS
    ) {
      return
    }

    isProcessing.value = true

    try {
      const product = await lookupProductByBarcode(barcode)

      if (!product) {
        options.onNotFound?.(barcode)
        return
      }

      const fullProduct = (options.getProduct(product.id) ?? product) as TProduct
      const validationError = options.validateProduct?.(fullProduct)

      if (validationError) {
        options.onError?.(validationError)
        return
      }

      const existingIndex = options.items.value.findIndex((item) => item.product_id === fullProduct.id)

      if (existingIndex >= 0) {
        if (!options.canIncrementQuantity(existingIndex)) {
          options.onError?.('Quantité maximale atteinte pour ce produit.')
          return
        }

        options.incrementQuantity(existingIndex)
        lastProcessedScan.value = { barcode, at: Date.now() }
        options.onSuccess?.(fullProduct, existingIndex, 'incremented')
        return
      }

      const emptyIndex = options.items.value.findIndex((item) => !item.product_id || item.product_id <= 0)

      if (emptyIndex >= 0) {
        options.handleProductSelected(fullProduct, emptyIndex)
        lastProcessedScan.value = { barcode, at: Date.now() }
        options.onSuccess?.(fullProduct, emptyIndex, 'added')
        return
      }

      options.addItem()
      const newIndex = options.items.value.length - 1
      options.handleProductSelected(fullProduct, newIndex)
      lastProcessedScan.value = { barcode, at: Date.now() }
      options.onSuccess?.(fullProduct, newIndex, 'added')
    } catch (error) {
      if (error instanceof ProductBarcodeLookupError) {
        options.onError?.(error.message)
        return
      }

      options.onError?.('Impossible de rechercher le produit scanné.')
    } finally {
      isProcessing.value = false
    }
  }

  return {
    handleBarcodeDetected,
    isProcessing,
  }
}
