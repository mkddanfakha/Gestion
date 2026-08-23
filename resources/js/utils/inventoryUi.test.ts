import { describe, expect, it, vi } from 'vitest'
import {
  buildApplyConfirmDetails,
  buildValidateConfirmDetails,
  filterInventoryReviewItems,
  formatInventoryListProgress,
  formatInventorySessionHeaderSubtitle,
  formatInventorySessionTitle,
  formatInventoryShortDate,
  getInventoryListActionLabel,
  getInventoryScopeLabel,
  getInventoryListVarianceDisplay,
  getInventoryScopeBadgeClass,
  getInventoryStatusBadgeClass,
  getInventoryStatusLabel,
  getInventoryStatusListLabel,
  resolveInventoryListScopeDisplay,
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
    expect(getInventoryStatusListLabel('counting')).toBe('Comptage')
    expect(getInventoryStatusListLabel('review')).toBe('Révision')
    expect(getInventoryStatusListLabel('cancelled')).toBe('Annulé')
    expect(getInventoryStatusBadgeClass('validated')).toBe('inventory-list-badge--status-validated')
    expect(getInventoryStatusBadgeClass('cancelled')).toBe('inventory-list-badge--status-cancelled')
  })

  it('maps scope and variance presentation for the list table', () => {
    expect(getInventoryScopeBadgeClass('complete')).toBe('inventory-list-badge--scope-complete')
    expect(getInventoryScopeBadgeClass('category')).toBe('inventory-list-badge--scope-category')

    expect(resolveInventoryListScopeDisplay('category', { category_id: 7 }, [{ id: 7, name: 'Riz' }])).toEqual({
      label: 'Catégorie',
      badgeClass: 'inventory-list-badge--scope-category',
      categoryName: 'Riz',
    })

    expect(getInventoryListVarianceDisplay({ status: 'counting', application_summary: { net_adjustment: 3 } })).toBeNull()
    expect(getInventoryListVarianceDisplay({ status: 'closed', application_summary: { net_adjustment: 0 } })).toEqual({
      label: 'Conforme',
      badgeClass: 'inventory-variance-badge inventory-variance-badge--conforme',
    })
    expect(getInventoryListVarianceDisplay({ status: 'applied', application_summary: { net_adjustment: 4 } })).toEqual({
      label: 'Écart net +4',
      badgeClass: 'inventory-variance-badge inventory-variance-badge--surplus',
    })
    expect(getInventoryListVarianceDisplay({ status: 'applied', application_summary: { net_adjustment: -2 } })).toEqual({
      label: 'Écart net -2',
      badgeClass: 'inventory-variance-badge inventory-variance-badge--manque',
    })
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

  it('formats inventory short dates for display', () => {
    expect(formatInventoryShortDate('2026-08-22T14:30:00+02:00')).toBe('22/08/2026')
    expect(formatInventoryShortDate('2026-08-22T14:30:00')).toMatch(/22\/08\/2026/)
    expect(formatInventoryShortDate(null)).toBe('—')
    expect(formatInventoryShortDate(undefined)).toBe('—')
    expect(formatInventoryShortDate('not-a-date')).toBe('—')
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
