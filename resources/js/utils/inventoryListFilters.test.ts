import { describe, expect, it } from 'vitest'
import {
  buildInventoryListQueryParams,
  formatInventoryListResultsLabel,
  getInventoryListFilterChips,
  hasActiveInventoryListFilters,
  INVENTORY_LIST_SEARCH_DEBOUNCE_MS,
  normalizeInventoryListFilters,
  removeInventoryListFilter,
} from './inventoryListFilters'

describe('inventoryListFilters', () => {
  it('builds query params from active filters only', () => {
    expect(buildInventoryListQueryParams({
      search: '  riz ',
      status: 'counting',
      scope_type: '',
      category_id: '12',
      date_from: '2026-08-01',
      date_to: undefined,
    })).toEqual({
      search: 'riz',
      status: 'counting',
      category_id: '12',
      date_from: '2026-08-01',
    })
  })

  it('detects active filters and supports reset state', () => {
    const filters = {
      search: 'INV2608003',
      status: 'draft',
    }

    expect(hasActiveInventoryListFilters(filters)).toBe(true)
    expect(hasActiveInventoryListFilters({})).toBe(false)
    expect(buildInventoryListQueryParams({})).toEqual({})
  })

  it('removes a single filter while preserving the others', () => {
    const next = removeInventoryListFilter({
      search: 'riz',
      status: 'counting',
      category_id: '3',
    }, 'status')

    expect(next).toEqual({
      search: 'riz',
      category_id: '3',
    })
  })

  it('builds readable filter chips for active criteria', () => {
    expect(getInventoryListFilterChips({
      search: 'riz',
      status: 'counting',
      scope_type: 'category',
      category_id: '7',
      date_from: '2026-08-01',
    }, [{ id: 7, name: 'Riz' }])).toEqual([
      { key: 'search', label: 'Recherche', value: 'riz' },
      { key: 'status', label: 'Statut', value: 'Comptage en cours' },
      { key: 'scope_type', label: 'Périmètre', value: 'Catégorie' },
      { key: 'category_id', label: 'Catégorie', value: 'Riz' },
      { key: 'date_from', label: 'Du', value: '2026-08-01' },
    ])
  })

  it('formats results labels for filtered and default states', () => {
    expect(formatInventoryListResultsLabel(20, false)).toBe('20 inventaires')
    expect(formatInventoryListResultsLabel(1, true)).toBe('1 inventaire trouvé')
    expect(formatInventoryListResultsLabel(8, true)).toBe('8 inventaires trouvés')
  })

  it('uses a debounce delay within the expected range', () => {
    expect(INVENTORY_LIST_SEARCH_DEBOUNCE_MS).toBeGreaterThanOrEqual(300)
    expect(INVENTORY_LIST_SEARCH_DEBOUNCE_MS).toBeLessThanOrEqual(500)
  })

  it('normalizes empty strings to an empty filter object', () => {
    expect(normalizeInventoryListFilters({
      search: '   ',
      status: null,
      category_id: undefined,
    })).toEqual({})
  })
})
