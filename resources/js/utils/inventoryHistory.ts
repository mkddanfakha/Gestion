import type { InventorySessionSummary } from './inventoryCounting'
import { getInventoryStatusLabel } from './inventoryUi'

export const INVENTORY_HISTORY_STATUSES = ['applied', 'closed', 'cancelled'] as const

export const INVENTORY_ACTIVE_STATUSES = ['draft', 'counting', 'review', 'validated'] as const

export type InventoryListView = '' | 'active' | 'history'

export const INVENTORY_LIST_VIEW_OPTIONS = [
  { value: '', label: 'Tous' },
  { value: 'active', label: 'En cours' },
  { value: 'history', label: 'Historique' },
] as const

export type InventoryHistoryKpi = {
  products: number
  counted: number
  conforme: number
  surplus: number
  manquants: number
  net_variance: number
  net_variance_label?: string
}

export type InventoryMovementRow = {
  id?: number
  product_name: string
  barcode?: string | null
  type?: string
  type_label?: string
  quantity?: number
  quantity_label?: string
  created_at?: string
  user_name?: string
  reason?: string | null
}

export type InventoryVarianceRow = {
  product_name: string
  barcode?: string | null
  stock_snapshot: number
  quantity_counted: number | null
  difference?: number | null
  difference_label?: string
  variance_type?: string
  variance_label?: string
}

const VARIANCE_TYPE_LABELS: Record<string, string> = {
  conforme: 'Conforme',
  surplus: 'Surplus',
  manque: 'Manque',
  uncounted: 'Non compté',
}

export function isInventoryHistoryStatus(status: string): boolean {
  return (INVENTORY_HISTORY_STATUSES as readonly string[]).includes(status)
}

export function isInventoryActiveStatus(status: string): boolean {
  return (INVENTORY_ACTIVE_STATUSES as readonly string[]).includes(status)
}

export function canExportInventorySession(status: string, canExport = false): boolean {
  return canExport && isInventoryHistoryStatus(status)
}

export function formatInventoryVarianceType(type?: string | null): string {
  if (!type) {
    return '—'
  }

  return VARIANCE_TYPE_LABELS[type] ?? type
}

export function formatInventorySignedQuantity(quantity: number | null | undefined): string {
  if (quantity === null || quantity === undefined) {
    return '—'
  }

  if (quantity > 0) {
    return `+${quantity}`
  }

  return String(quantity)
}

export function formatInventoryHistoryKpi(kpi: InventoryHistoryKpi | null | undefined): InventoryHistoryKpi {
  const products = kpi?.products ?? 0
  const counted = kpi?.counted ?? 0
  const conforme = kpi?.conforme ?? 0
  const surplus = kpi?.surplus ?? 0
  const manquants = kpi?.manquants ?? 0
  const netVariance = kpi?.net_variance ?? 0

  return {
    products,
    counted,
    conforme,
    surplus,
    manquants,
    net_variance: netVariance,
    net_variance_label: kpi?.net_variance_label ?? formatInventorySignedQuantity(netVariance),
  }
}

export function formatInventoryHistoryKpiFromSummary(
  progress: { total?: number; counted?: number },
  summary: InventorySessionSummary,
): InventoryHistoryKpi {
  return formatInventoryHistoryKpi({
    products: progress.total ?? 0,
    counted: progress.counted ?? 0,
    conforme: summary.zero_variances ?? 0,
    surplus: summary.positive_variances ?? 0,
    manquants: summary.negative_variances ?? 0,
    net_variance: summary.total_variance ?? 0,
  })
}

export function getInventoryListVarianceLabel(session: {
  status?: string
  application_summary?: {
    adjusted_items?: number
    net_adjustment?: number
  } | null
  summary?: InventorySessionSummary
}): string | null {
  if (session.status && isInventoryHistoryStatus(session.status)) {
    const net = session.application_summary?.net_adjustment ?? session.summary?.total_variance

    if (net === undefined || net === null) {
      return null
    }

    return `Écart net ${formatInventorySignedQuantity(net)}`
  }

  return null
}

export function inventoryHistoryStatusBanner(status: string): { title: string; text: string; className: string } | null {
  switch (status) {
    case 'applied':
      return {
        className: 'alert-success',
        title: 'Inventaire appliqué',
        text: 'Le stock réel a été mis à jour. Consultation et export disponibles.',
      }
    case 'closed':
      return {
        className: 'alert-secondary',
        title: 'Inventaire clôturé',
        text: 'Consultation en lecture seule. Export PDF / Excel disponible.',
      }
    case 'cancelled':
      return {
        className: 'alert-warning',
        title: 'Inventaire annulé',
        text: 'Aucun mouvement de stock n\'a été généré.',
      }
    default:
      return null
  }
}

export function inventoryHistoryDateLabel(status: string): string {
  switch (status) {
    case 'closed':
      return 'Clôturé le'
    case 'applied':
      return 'Appliqué le'
    case 'cancelled':
      return 'Annulé le'
    default:
      return 'Date'
  }
}

export function buildInventoryExportPdfUrl(sessionId: number): string {
  return `/inventory/${sessionId}/export/pdf`
}

export function buildInventoryExportExcelUrl(sessionId: number): string {
  return `/inventory/${sessionId}/export/excel`
}

export function inventoryListViewLabel(listView: InventoryListView): string {
  const option = INVENTORY_LIST_VIEW_OPTIONS.find((item) => item.value === listView)

  return option?.label ?? 'Tous'
}

export function inventoryHistoryTabLabel(count: number): string {
  return count > 0 ? `Historique (${count})` : 'Historique'
}

export function inventoryActiveTabLabel(count: number): string {
  return count > 0 ? `En cours (${count})` : 'En cours'
}

export function getInventoryHistoryStatusLabel(status: string): string {
  return getInventoryStatusLabel(status)
}
