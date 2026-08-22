export type InventoryCountingItem = {
  id: number
  product_id: number
  stock_snapshot: number
  quantity_counted: number | null
  difference?: number | null
  difference_from_snapshot: number | null
  is_counted: boolean
  variance_status?: 'uncounted' | 'conforme' | 'surplus' | 'manque'
  product: {
    id: number
    name: string
    barcode: string | null
    sku?: string | null
    image_url?: string | null
  }
}

export type InventoryCountProgress = {
  counted: number
  total: number
  uncounted: number
  percentage: number
}

export type InventorySessionSummary = {
  total_units: number
  positive_variances: number
  negative_variances: number
  zero_variances: number
  total_variance: number
}

export type InventoryItemFilter =
  | 'all'
  | 'uncounted'
  | 'counted'
  | 'with_variance'
  | 'without_variance'

export type InventoryScanItemPayload = Pick<
  InventoryCountingItem,
  'id' | 'product_id' | 'stock_snapshot' | 'quantity_counted' | 'difference_from_snapshot' | 'is_counted'
> & {
  difference?: number | null
  variance_status?: InventoryCountingItem['variance_status']
}

export function isInventoryItemCounted(quantityCounted: number | null): boolean {
  return quantityCounted !== null
}

export function inventoryCountProgress(items: InventoryCountingItem[]): InventoryCountProgress {
  const total = items.length
  const counted = items.filter((item) => isInventoryItemCounted(item.quantity_counted)).length
  const uncounted = total - counted

  return {
    counted,
    total,
    uncounted,
    percentage: total > 0 ? Math.round((counted / total) * 1000) / 10 : 0,
  }
}

export function inventorySessionSummary(items: InventoryCountingItem[]): InventorySessionSummary {
  let totalUnits = 0
  let positiveVariances = 0
  let negativeVariances = 0
  let zeroVariances = 0
  let totalVariance = 0

  for (const item of items) {
    if (item.quantity_counted === null) {
      continue
    }

    totalUnits += item.quantity_counted
    const difference = item.difference ?? item.difference_from_snapshot ?? 0
    totalVariance += difference

    if (difference > 0) {
      positiveVariances++
    } else if (difference < 0) {
      negativeVariances++
    } else {
      zeroVariances++
    }
  }

  return {
    total_units: totalUnits,
    positive_variances: positiveVariances,
    negative_variances: negativeVariances,
    zero_variances: zeroVariances,
    total_variance: totalVariance,
  }
}

export function applyScanToItems(
  items: InventoryCountingItem[],
  scannedItem: InventoryScanItemPayload,
): InventoryCountingItem[] {
  return items.map((item) => {
    if (item.id !== scannedItem.id) {
      return item
    }

    const difference = scannedItem.difference ?? scannedItem.difference_from_snapshot

    return {
      ...item,
      quantity_counted: scannedItem.quantity_counted,
      difference,
      difference_from_snapshot: difference,
      is_counted: scannedItem.is_counted,
      variance_status: scannedItem.variance_status
        ?? resolveVarianceStatus(scannedItem.quantity_counted, difference),
    }
  })
}

export function resolveVarianceStatus(
  quantityCounted: number | null,
  difference: number | null,
): InventoryCountingItem['variance_status'] {
  if (quantityCounted === null) {
    return 'uncounted'
  }

  if (difference === 0) {
    return 'conforme'
  }

  return (difference ?? 0) > 0 ? 'surplus' : 'manque'
}

export function filterInventoryItems(
  items: InventoryCountingItem[],
  query: string,
  statusFilter: InventoryItemFilter = 'all',
): InventoryCountingItem[] {
  const normalized = query.trim().toLowerCase()

  return items.filter((item) => {
    if (statusFilter === 'uncounted' && item.quantity_counted !== null) {
      return false
    }

    if (statusFilter === 'counted' && item.quantity_counted === null) {
      return false
    }

    const difference = item.difference ?? item.difference_from_snapshot

    if (statusFilter === 'with_variance' && (difference === null || difference === 0)) {
      return false
    }

    if (statusFilter === 'without_variance' && difference !== 0) {
      return false
    }

    if (normalized === '') {
      return true
    }

    const { name, barcode, sku } = item.product

    return [name, barcode ?? '', sku ?? ''].some((value) => value.toLowerCase().includes(normalized))
  })
}

export function getVariancePresentation(
  varianceStatus: InventoryCountingItem['variance_status'],
  difference: number | null,
): { label: string; badgeClass: string; textClass: string } {
  switch (varianceStatus) {
    case 'surplus':
      return {
        label: difference !== null && difference > 0 ? `+${difference}` : 'Surplus',
        badgeClass: 'inventory-variance-badge inventory-variance-badge--surplus',
        textClass: 'inventory-variance-text inventory-variance-text--surplus',
      }
    case 'manque':
      return {
        label: difference !== null ? `${difference}` : 'Manque',
        badgeClass: 'inventory-variance-badge inventory-variance-badge--manque',
        textClass: 'inventory-variance-text inventory-variance-text--manque',
      }
    case 'conforme':
      return {
        label: 'Conforme',
        badgeClass: 'inventory-variance-badge inventory-variance-badge--conforme',
        textClass: 'inventory-variance-text inventory-variance-text--conforme',
      }
    default:
      return {
        label: 'Non compté',
        badgeClass: 'inventory-variance-badge inventory-variance-badge--pending',
        textClass: 'inventory-variance-text inventory-variance-text--pending',
      }
  }
}

export function formatScanSuccessMessage(productName: string, quantityCounted: number): string {
  return `${productName} — compté : ${quantityCounted}`
}

export function formatCountedQuantityLabel(quantityCounted: number | null): string {
  if (quantityCounted === null) {
    return 'Non compté'
  }

  if (quantityCounted === 0) {
    return 'Compté : 0'
  }

  return `Compté : ${quantityCounted}`
}

export type InventoryScannerRefocusContext = {
  status: string
  canCount: boolean
  quantityModalOpen: boolean
  searchInputFocused: boolean
  workflowLoading: boolean
  scanning: boolean
}

/** Indique si le scanner HID peut reprendre le focus sans gêner l'utilisateur. */
export function shouldRefocusInventoryScanner(context: InventoryScannerRefocusContext): boolean {
  return context.status === 'counting'
    && context.canCount
    && !context.quantityModalOpen
    && !context.searchInputFocused
    && !context.workflowLoading
    && !context.scanning
}

export type InventoryProductRowState = 'default' | 'counted' | 'highlight'

export function getInventoryProductRowState(
  item: Pick<InventoryCountingItem, 'id' | 'is_counted'>,
  highlightedItemId?: number | null,
): InventoryProductRowState {
  if (highlightedItemId != null && item.id === highlightedItemId) {
    return 'highlight'
  }

  if (item.is_counted) {
    return 'counted'
  }

  return 'default'
}
