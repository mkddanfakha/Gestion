import { describe, expect, it } from 'vitest'
import {
  buildInventoryExportExcelUrl,
  buildInventoryExportPdfUrl,
  canExportInventorySession,
  formatInventoryHistoryKpi,
  formatInventoryHistoryKpiFromSummary,
  formatInventorySignedQuantity,
  formatInventoryVarianceType,
  getInventoryListVarianceLabel,
  inventoryHistoryStatusBanner,
  isInventoryHistoryStatus,
} from './inventoryHistory'

describe('inventoryHistory', () => {
  it('detects history statuses', () => {
    expect(isInventoryHistoryStatus('applied')).toBe(true)
    expect(isInventoryHistoryStatus('closed')).toBe(true)
    expect(isInventoryHistoryStatus('cancelled')).toBe(true)
    expect(isInventoryHistoryStatus('counting')).toBe(false)
  })

  it('controls export availability', () => {
    expect(canExportInventorySession('closed', true)).toBe(true)
    expect(canExportInventorySession('closed', false)).toBe(false)
    expect(canExportInventorySession('counting', true)).toBe(false)
  })

  it('formats variance types and signed quantities', () => {
    expect(formatInventoryVarianceType('surplus')).toBe('Surplus')
    expect(formatInventoryVarianceType('manque')).toBe('Manque')
    expect(formatInventorySignedQuantity(3)).toBe('+3')
    expect(formatInventorySignedQuantity(-2)).toBe('-2')
    expect(formatInventorySignedQuantity(null)).toBe('—')
  })

  it('builds KPI from summary data', () => {
    expect(formatInventoryHistoryKpiFromSummary(
      { total: 10, counted: 10 },
      {
        total_units: 100,
        positive_variances: 2,
        negative_variances: 1,
        zero_variances: 7,
        total_variance: 3,
      },
    )).toEqual({
      products: 10,
      counted: 10,
      conforme: 7,
      surplus: 2,
      manquants: 1,
      net_variance: 3,
      net_variance_label: '+3',
    })
  })

  it('normalizes KPI payload with defaults', () => {
    expect(formatInventoryHistoryKpi(null)).toEqual({
      products: 0,
      counted: 0,
      conforme: 0,
      surplus: 0,
      manquants: 0,
      net_variance: 0,
      net_variance_label: '0',
    })
  })

  it('builds list variance labels for history sessions', () => {
    expect(getInventoryListVarianceLabel({
      status: 'closed',
      application_summary: { net_adjustment: -4 },
    })).toBe('Écart net -4')
    expect(getInventoryListVarianceLabel({ status: 'counting' })).toBeNull()
  })

  it('returns history banners by status', () => {
    expect(inventoryHistoryStatusBanner('cancelled')?.title).toBe('Inventaire annulé')
    expect(inventoryHistoryStatusBanner('draft')).toBeNull()
  })

  it('builds export urls', () => {
    expect(buildInventoryExportPdfUrl(12)).toBe('/inventory/12/export/pdf')
    expect(buildInventoryExportExcelUrl(12)).toBe('/inventory/12/export/excel')
  })
})
