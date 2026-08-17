export interface ScannableProduct {
  id: number
  name: string
  sku?: string | null
  barcode?: string | null
  price: number
  cost_price?: number
  stock_quantity: number
  unit: string
  category?: {
    id: number
    name: string
    color: string
  } | null
  image_url?: string | null
}

export interface ProductBarcodeNotFoundError {
  message: string
  barcode: string
}

export class ProductBarcodeLookupError extends Error {
  status: number
  barcode?: string

  constructor(message: string, status: number, barcode?: string) {
    super(message)
    this.name = 'ProductBarcodeLookupError'
    this.status = status
    this.barcode = barcode
  }
}
