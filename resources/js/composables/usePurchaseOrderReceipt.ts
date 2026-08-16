import { route } from '@/lib/routes'

export interface PurchaseOrderReceiptLine {
  purchase_order_item_id: number
  product_id: number
  product_name?: string | null
  ordered_quantity: number
  delivered_quantity: number
  pending_quantity: number
  remaining_quantity: number
  available_quantity: number
  unit_price: number
}

export interface PurchaseOrderReceiptSummary {
  items: PurchaseOrderReceiptLine[]
  totals: {
    ordered: number
    delivered: number
    pending: number
    remaining: number
  }
  progress_percent: number
  can_create_delivery: boolean
  is_fully_delivered: boolean
}

export function getReceiptLineForProduct(
  summary: PurchaseOrderReceiptSummary | null | undefined,
  productId: number,
): PurchaseOrderReceiptLine | null {
  if (!summary) {
    return null
  }

  return summary.items.find((line) => line.product_id === productId) ?? null
}

export function validateDeliveryQuantityAgainstReceipt(
  summary: PurchaseOrderReceiptSummary | null | undefined,
  productId: number,
  quantity: number,
): string {
  if (!summary || !productId || quantity <= 0) {
    return ''
  }

  const line = getReceiptLineForProduct(summary, productId)

  if (!line) {
    return 'Ce produit ne fait pas partie du bon de commande.'
  }

  if (quantity > line.available_quantity) {
    return `Impossible de livrer ${quantity} unité(s) : seulement ${line.available_quantity} unité(s) restent à livrer.`
  }

  return ''
}

export async function fetchPurchaseOrderReceiptSummary(
  purchaseOrderId: number,
): Promise<PurchaseOrderReceiptSummary> {
  const response = await fetch(route('purchase-orders.receipt-summary', { purchaseOrder: purchaseOrderId }), {
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  })

  if (!response.ok) {
    throw new Error('Impossible de charger les quantités restantes du bon de commande.')
  }

  return response.json()
}
