import { describe, expect, it } from 'vitest'
import {
  applyScanToItems,
  filterInventoryItems,
  formatCountedQuantityLabel,
  formatScanSuccessMessage,
  getVariancePresentation,
  getInventoryProductRowState,
  inventoryCountProgress,
  inventorySessionSummary,
  isInventoryItemCounted,
  resolveVarianceStatus,
  shouldRefocusInventoryScanner,
  type InventoryCountingItem,
} from './inventoryCounting'

const sampleItems: InventoryCountingItem[] = [
  {
    id: 1,
    product_id: 10,
    stock_snapshot: 10,
    quantity_counted: null,
    difference: null,
    difference_from_snapshot: null,
    is_counted: false,
    variance_status: 'uncounted',
    product: { id: 10, name: 'Riz 25 kg', barcode: '111', sku: 'RIZ25' },
  },
  {
    id: 2,
    product_id: 11,
    stock_snapshot: 8,
    quantity_counted: 0,
    difference: -8,
    difference_from_snapshot: -8,
    is_counted: true,
    variance_status: 'manque',
    product: { id: 11, name: 'Huile 5L', barcode: '222', sku: 'HUILE5' },
  },
  {
    id: 3,
    product_id: 12,
    stock_snapshot: 5,
    quantity_counted: 8,
    difference: 3,
    difference_from_snapshot: 3,
    is_counted: true,
    variance_status: 'surplus',
    product: { id: 12, name: 'Sucre 1kg', barcode: '333', sku: 'SUCRE1' },
  },
]

describe('inventoryCounting', () => {
  it('considers zero as counted and null as not counted', () => {
    expect(isInventoryItemCounted(0)).toBe(true)
    expect(isInventoryItemCounted(null)).toBe(false)
    expect(formatCountedQuantityLabel(null)).toBe('Non compté')
    expect(formatCountedQuantityLabel(0)).toBe('Compté : 0')
  })

  it('computes progress with percentage and uncounted count', () => {
    expect(inventoryCountProgress(sampleItems)).toEqual({
      counted: 2,
      total: 3,
      uncounted: 1,
      percentage: 66.7,
    })
  })

  it('computes review summary from counted items only', () => {
    expect(inventorySessionSummary(sampleItems)).toEqual({
      total_units: 8,
      positive_variances: 1,
      negative_variances: 1,
      zero_variances: 0,
      total_variance: -5,
    })
  })

  it('updates only scanned item and recalculates variance', () => {
    const updated = applyScanToItems(sampleItems, {
      id: 1,
      product_id: 10,
      stock_snapshot: 10,
      quantity_counted: 1,
      difference: -9,
      difference_from_snapshot: -9,
      is_counted: true,
      variance_status: 'manque',
    })

    expect(updated[0].quantity_counted).toBe(1)
    expect(updated[0].variance_status).toBe('manque')
    expect(inventoryCountProgress(updated).counted).toBe(3)
  })

  it('filters uncounted items using null check', () => {
    expect(filterInventoryItems(sampleItems, '', 'uncounted')).toHaveLength(1)
    expect(filterInventoryItems(sampleItems, '', 'counted')).toHaveLength(2)
    expect(filterInventoryItems(sampleItems, '', 'with_variance')).toHaveLength(2)
    expect(filterInventoryItems(sampleItems, '', 'without_variance')).toHaveLength(0)
  })

  it('provides variance labels for review states', () => {
    expect(getVariancePresentation('conforme', 0).label).toBe('Conforme')
    expect(getVariancePresentation('surplus', 3).label).toBe('+3')
    expect(getVariancePresentation('manque', -2).label).toBe('-2')
    expect(resolveVarianceStatus(null, null)).toBe('uncounted')
  })

  it('formats scan success message', () => {
    expect(formatScanSuccessMessage('Riz 25 kg', 8)).toBe('Riz 25 kg — compté : 8')
  })

  it('allows scanner refocus only in safe counting context', () => {
    const base = {
      status: 'counting',
      canCount: true,
      quantityModalOpen: false,
      searchInputFocused: false,
      workflowLoading: false,
      scanning: false,
    }

    expect(shouldRefocusInventoryScanner(base)).toBe(true)
    expect(shouldRefocusInventoryScanner({ ...base, quantityModalOpen: true })).toBe(false)
    expect(shouldRefocusInventoryScanner({ ...base, searchInputFocused: true })).toBe(false)
    expect(shouldRefocusInventoryScanner({ ...base, status: 'review' })).toBe(false)
  })

  it('determines product row visual states for counted and highlighted items', () => {
    const item = sampleItems[1]
    const defaultItem = sampleItems[0]

    expect(getInventoryProductRowState(defaultItem, null)).toBe('default')
    expect(getInventoryProductRowState(item, null)).toBe('counted')
    expect(getInventoryProductRowState(item, item.id)).toBe('highlight')
    expect(getInventoryProductRowState(defaultItem, defaultItem.id)).toBe('highlight')
  })

  it('returns theme-aware variance presentation classes for all row states', () => {
    expect(getVariancePresentation('conforme', 0)).toEqual({
      label: 'Conforme',
      badgeClass: 'inventory-variance-badge inventory-variance-badge--conforme',
      textClass: 'inventory-variance-text inventory-variance-text--conforme',
    })

    expect(getVariancePresentation('manque', -2)).toEqual({
      label: '-2',
      badgeClass: 'inventory-variance-badge inventory-variance-badge--manque',
      textClass: 'inventory-variance-text inventory-variance-text--manque',
    })

    expect(getVariancePresentation('surplus', 3)).toEqual({
      label: '+3',
      badgeClass: 'inventory-variance-badge inventory-variance-badge--surplus',
      textClass: 'inventory-variance-text inventory-variance-text--surplus',
    })

    expect(getVariancePresentation('uncounted', null)).toEqual({
      label: 'Non compté',
      badgeClass: 'inventory-variance-badge inventory-variance-badge--pending',
      textClass: 'inventory-variance-text inventory-variance-text--pending',
    })
  })
})
