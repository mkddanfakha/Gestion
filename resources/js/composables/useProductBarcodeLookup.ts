import { route } from '@/lib/routes'
import type { ScannableProduct } from '@/types/product'
import { ProductBarcodeLookupError } from '@/types/product'
import { isValidBarcode, normalizeBarcode } from '@/utils/barcodeNormalizer'

export function useProductBarcodeLookup() {
  const lookupProductByBarcode = async (rawBarcode: string): Promise<ScannableProduct | null> => {
    const barcode = normalizeBarcode(rawBarcode)

    if (!isValidBarcode(barcode)) {
      throw new ProductBarcodeLookupError('Code-barres invalide.', 422, barcode)
    }

    const response = await fetch(route('products.barcode', { barcode }), {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
    })

    if (response.status === 404) {
      return null
    }

    if (!response.ok) {
      let message = 'Impossible de rechercher le produit.'
      let barcodeFromApi = barcode

      try {
        const payload = (await response.json()) as { message?: string; barcode?: string }
        if (payload.message) {
          message = payload.message
        }
        if (payload.barcode) {
          barcodeFromApi = payload.barcode
        }
      } catch {
        // Ignore malformed error payloads.
      }

      throw new ProductBarcodeLookupError(message, response.status, barcodeFromApi)
    }

    return (await response.json()) as ScannableProduct
  }

  return {
    lookupProductByBarcode,
  }
}
