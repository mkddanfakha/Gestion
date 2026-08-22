import { describe, expect, it, vi } from 'vitest'
import {
  buildApplyConfirmDetails,
  buildValidateConfirmDetails,
  filterInventoryReviewItems,
  formatInventoryListProgress,
  formatInventorySessionHeaderSubtitle,
  formatInventorySessionTitle,
  getInventoryListActionLabel,
  getInventoryScopeLabel,
  getInventoryStatusBadgeClass,
  getInventoryStatusLabel,
  mapInventoryNetworkScanError,
  mapInventoryScanError,
  normalizeInventoryDescription,
  resolveInventoryInertiaFlashMessage,
  scrollInventoryViewToTop,
} from './inventoryUi'
import type { InventoryCountingItem } from './inventoryCounting'

const sampleItem = (overrides: Partial<InventoryCountingItem> = {}): InventoryCountingItem => ({
  id: 1,
  product_id: 10,
  stock_snapshot: 10,
  quantity_counted: 8,
  difference: -2,
  difference_from_snapshot: -2,
  is_counted: true,
  variance_status: 'manque',
  product: { id: 10, name: 'Riz 25 kg', barcode: '123', sku: 'RIZ' },
  ...overrides,
})

describe('inventoryUi', () => {
  it('translates inventory statuses for users', () => {
    expect(getInventoryStatusLabel('counting')).toBe('Comptage en cours')
    expect(getInventoryStatusLabel('review')).toBe('À vérifier')
    expect(getInventoryStatusBadgeClass('validated')).toBe('bg-success')
  })

  it('formats list progress and scope labels', () => {
    expect(formatInventoryListProgress(41, 50)).toBe('82 %')
    expect(getInventoryScopeLabel('stock_positive')).toBe('Stock positif')
    expect(getInventoryListActionLabel('counting')).toBe('Compter')
  })

  it('maps scan errors to user friendly messages', () => {
    expect(mapInventoryScanError({ errors: { barcode: ['Code-barres inconnu.'] } })).toBe('Produit introuvable.')
    expect(mapInventoryScanError({ errors: { message: ['Ce produit ne fait pas partie de cet inventaire.'] } }))
      .toBe('Ce produit ne fait pas partie de cet inventaire.')
    expect(mapInventoryNetworkScanError()).toContain('connexion')
  })

  it('filters review items by variance status', () => {
    const items = [
      sampleItem({ id: 1, variance_status: 'conforme', difference: 0, quantity_counted: 10, product: { id: 10, name: 'Riz 25 kg', barcode: '123', sku: 'RIZ' } }),
      sampleItem({ id: 2, variance_status: 'manque', product: { id: 11, name: 'Sucre 1 kg', barcode: '456', sku: 'SUC' } }),
      sampleItem({ id: 3, variance_status: 'surplus', difference: 2, quantity_counted: 12, product: { id: 12, name: 'Huile 5 L', barcode: '789', sku: 'HUI' } }),
    ]

    expect(filterInventoryReviewItems(items, '', 'manque')).toHaveLength(1)
    expect(filterInventoryReviewItems(items, 'riz', 'all')).toHaveLength(1)
  })

  it('builds confirmation details for validate and apply', () => {
    expect(buildValidateConfirmDetails({
      total: 50,
      zero_variances: 41,
      negative_variances: 6,
      positive_variances: 3,
    })).toContain('41 conforme(s)')

    expect(buildApplyConfirmDetails({
      adjusted_items: 27,
      total_positive_quantity: 35,
      total_negative_quantity: 59,
    })).toContain('27 produit(s) concerné(s)')
  })

  it('normalizes inventory descriptions for display', () => {
    expect(normalizeInventoryDescription('  Contrôle mensuel  ')).toBe('Contrôle mensuel')
    expect(normalizeInventoryDescription('')).toBeNull()
    expect(normalizeInventoryDescription(null)).toBeNull()
  })

  it('formats inventory session header title and subtitle without undefined fallbacks', () => {
    expect(formatInventorySessionTitle('Inventaire mensuel', 'INV-2026-001')).toBe('Inventaire mensuel')
    expect(formatInventorySessionTitle(null, 'INV-2026-001')).toBe('Inventaire INV-2026-001')
    expect(formatInventorySessionTitle('', '')).toBe('Inventaire')
    expect(formatInventorySessionHeaderSubtitle('INV-2026-001', 'Magasin central', 'Stock positif'))
      .toBe('INV-2026-001 · Magasin central · Stock positif')
    expect(formatInventorySessionHeaderSubtitle(null, null, null)).toBe('—')
  })

  it('scrolls inventory view to top when window is available', () => {
    const scrollTo = vi.fn()
    vi.stubGlobal('window', { scrollTo })

    scrollInventoryViewToTop('smooth')

    expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })

    vi.unstubAllGlobals()
  })

  it('resolves inertia flash messages for inventory detail mount', () => {
    expect(resolveInventoryInertiaFlashMessage({ success: ' Session créée. ' })).toEqual({
      message: 'Session créée.',
      className: 'alert-success',
    })
    expect(resolveInventoryInertiaFlashMessage({ success: '  ' })).toBeNull()
    expect(resolveInventoryInertiaFlashMessage(null)).toBeNull()
  })
})
