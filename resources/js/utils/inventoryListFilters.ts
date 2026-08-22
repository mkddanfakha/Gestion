import { getInventoryScopeLabel, getInventoryStatusLabel } from './inventoryUi'

export type InventoryListFilters = {
  search?: string | null
  status?: string | null
  scope_type?: string | null
  category_id?: string | null
  date_from?: string | null
  date_to?: string | null
}

export const INVENTORY_LIST_FILTER_KEYS = [
  'search',
  'status',
  'scope_type',
  'category_id',
  'date_from',
  'date_to',
] as const satisfies ReadonlyArray<keyof InventoryListFilters>

export const INVENTORY_LIST_STATUS_FILTER_OPTIONS = [
  { value: '', label: 'Tous' },
  { value: 'draft', label: 'Brouillon' },
  { value: 'counting', label: 'Comptage' },
  { value: 'review', label: 'Review' },
  { value: 'validated', label: 'Validé' },
  { value: 'applied', label: 'Appliqué' },
  { value: 'closed', label: 'Clôturé' },
] as const

export const INVENTORY_LIST_SCOPE_FILTER_OPTIONS = [
  { value: '', label: 'Tous' },
  { value: 'complete', label: 'Inventaire complet' },
  { value: 'category', label: 'Catégorie' },
  { value: 'stock_positive', label: 'Stock positif' },
] as const

export function normalizeInventoryListFilters(
  filters: Partial<InventoryListFilters> | null | undefined,
): InventoryListFilters {
  const normalized: InventoryListFilters = {}

  for (const key of INVENTORY_LIST_FILTER_KEYS) {
    const rawValue = filters?.[key]
    const trimmed = typeof rawValue === 'string' ? rawValue.trim() : ''

    if (trimmed !== '') {
      normalized[key] = trimmed
    }
  }

  return normalized
}

export function buildInventoryListQueryParams(
  filters: Partial<InventoryListFilters> | null | undefined,
): Record<string, string> {
  const normalized = normalizeInventoryListFilters(filters)
  const params: Record<string, string> = {}

  for (const [key, value] of Object.entries(normalized)) {
    if (value) {
      params[key] = value
    }
  }

  return params
}

export function hasActiveInventoryListFilters(
  filters: Partial<InventoryListFilters> | null | undefined,
): boolean {
  return Object.keys(buildInventoryListQueryParams(filters)).length > 0
}

export type InventoryListFilterChip = {
  key: keyof InventoryListFilters
  label: string
  value: string
}

export function getInventoryListFilterChips(
  filters: Partial<InventoryListFilters> | null | undefined,
  categories: Array<{ id: number; name: string }> = [],
): InventoryListFilterChip[] {
  const normalized = normalizeInventoryListFilters(filters)
  const chips: InventoryListFilterChip[] = []

  if (normalized.search) {
    chips.push({ key: 'search', label: 'Recherche', value: normalized.search })
  }

  if (normalized.status) {
    chips.push({
      key: 'status',
      label: 'Statut',
      value: getInventoryStatusLabel(normalized.status),
    })
  }

  if (normalized.scope_type) {
    chips.push({
      key: 'scope_type',
      label: 'Périmètre',
      value: getInventoryScopeLabel(normalized.scope_type),
    })
  }

  if (normalized.category_id) {
    const category = categories.find((item) => String(item.id) === normalized.category_id)
    chips.push({
      key: 'category_id',
      label: 'Catégorie',
      value: category?.name ?? normalized.category_id,
    })
  }

  if (normalized.date_from) {
    chips.push({ key: 'date_from', label: 'Du', value: normalized.date_from })
  }

  if (normalized.date_to) {
    chips.push({ key: 'date_to', label: 'Au', value: normalized.date_to })
  }

  return chips
}

export function removeInventoryListFilter(
  filters: Partial<InventoryListFilters> | null | undefined,
  key: keyof InventoryListFilters,
): InventoryListFilters {
  const next = { ...normalizeInventoryListFilters(filters) }
  delete next[key]

  return next
}

export function formatInventoryListResultsLabel(
  total: number,
  filtersActive: boolean,
): string {
  const countLabel = total <= 1 ? `${total} inventaire` : `${total} inventaires`

  return filtersActive ? `${countLabel} trouvé${total > 1 ? 's' : ''}` : countLabel
}

export const INVENTORY_LIST_SEARCH_DEBOUNCE_MS = 400
