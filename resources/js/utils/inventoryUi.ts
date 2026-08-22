import type { InventoryCountingItem } from './inventoryCounting'

export type InventoryReviewFilter = 'all' | 'conforme' | 'manque' | 'surplus'

export type InventoryListStats = {
  active_count: number
  counting_count: number
  to_validate_count: number
  last_reference?: string | null
  last_date?: string | null
}

const STATUS_LABELS: Record<string, string> = {
  draft: 'Brouillon',
  counting: 'Comptage en cours',
  review: 'À vérifier',
  validated: 'Validé',
  applied: 'Appliqué',
  closed: 'Clôturé',
  cancelled: 'Annulé',
}

const STATUS_BADGE_CLASSES: Record<string, string> = {
  draft: 'bg-secondary',
  counting: 'bg-primary',
  review: 'bg-warning text-dark',
  validated: 'bg-success',
  applied: 'bg-info text-dark',
  closed: 'bg-dark',
  cancelled: 'bg-secondary',
}

const SCOPE_LABELS: Record<string, string> = {
  complete: 'Complet',
  category: 'Catégorie',
  stock_positive: 'Stock positif',
}

export function getInventoryStatusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status
}

export function getInventoryStatusBadgeClass(status: string): string {
  return STATUS_BADGE_CLASSES[status] ?? 'bg-secondary'
}

export function getInventoryScopeLabel(scopeType?: string | null): string {
  if (!scopeType) {
    return 'Complet'
  }

  return SCOPE_LABELS[scopeType] ?? scopeType
}

export function formatInventoryListProgress(counted: number, total: number): string {
  if (total <= 0) {
    return '0 %'
  }

  return `${Math.round((counted / total) * 1000) / 10} %`
}

export function getInventoryListActionLabel(status: string): string {
  switch (status) {
    case 'draft':
      return 'Préparer'
    case 'counting':
      return 'Compter'
    case 'review':
      return 'Vérifier'
    case 'validated':
      return 'Appliquer'
    case 'applied':
      return 'Clôturer'
    default:
      return 'Voir'
  }
}

export function mapInventoryScanError(payload: {
  message?: string
  errors?: Record<string, string[]>
}): string {
  const barcodeError = payload.errors?.barcode?.[0]
  const messageError = payload.errors?.message?.[0] ?? payload.message

  if (barcodeError?.toLowerCase().includes('inconnu')) {
    return 'Produit introuvable.'
  }

  if (messageError?.toLowerCase().includes('ne fait pas partie')) {
    return 'Ce produit ne fait pas partie de cet inventaire.'
  }

  if (messageError?.toLowerCase().includes('statut')) {
    return 'Le comptage n\'est plus disponible pour cet inventaire.'
  }

  return messageError ?? barcodeError ?? 'Impossible de compter ce produit.'
}

export function mapInventoryNetworkScanError(): string {
  return 'Le comptage n\'a pas pu être enregistré. Vérifiez votre connexion.'
}

export function filterInventoryReviewItems(
  items: InventoryCountingItem[],
  query: string,
  reviewFilter: InventoryReviewFilter,
): InventoryCountingItem[] {
  const normalized = query.trim().toLowerCase()

  return items.filter((item) => {
    if (reviewFilter === 'conforme' && item.variance_status !== 'conforme') {
      return false
    }

    if (reviewFilter === 'manque' && item.variance_status !== 'manque') {
      return false
    }

    if (reviewFilter === 'surplus' && item.variance_status !== 'surplus') {
      return false
    }

    if (normalized === '') {
      return true
    }

    const { name, barcode, sku } = item.product

    return [name, barcode ?? '', sku ?? ''].some((value) => value.toLowerCase().includes(normalized))
  })
}

export function formatInventoryDate(value?: string | null): string {
  if (!value) {
    return '—'
  }

  return new Date(value).toLocaleString('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function formatInventoryShortDate(value?: string | null): string {
  if (!value) {
    return '—'
  }

  return new Date(value).toLocaleDateString('fr-FR')
}

export function getScannerReadyMessage(scanning: boolean, loading: boolean): string {
  if (scanning || loading) {
    return 'Enregistrement…'
  }

  return 'Scanner prêt'
}

export function buildValidateConfirmDetails(summary: {
  total: number
  zero_variances: number
  negative_variances: number
  positive_variances: number
}): string {
  return [
    `${summary.total} produit(s)`,
    `${summary.zero_variances} conforme(s)`,
    `${summary.negative_variances} manque(s)`,
    `${summary.positive_variances} surplus`,
  ].join(' · ')
}

export function buildApplyConfirmDetails(preview: {
  adjusted_items: number
  total_positive_quantity: number
  total_negative_quantity: number
}): string {
  return [
    `${preview.adjusted_items} produit(s) concerné(s)`,
    `+${preview.total_positive_quantity} unités`,
    `−${preview.total_negative_quantity} unités`,
  ].join(' · ')
}

export function canShowManualQuantityEdit(status: string, canCount: boolean): boolean {
  return status === 'counting' && canCount
}

export function normalizeInventoryDescription(value?: string | null): string | null {
  const trimmed = value?.trim()

  return trimmed ? trimmed : null
}

export function formatInventorySessionTitle(name?: string | null, reference?: string | null): string {
  const trimmedName = name?.trim()

  if (trimmedName) {
    return trimmedName
  }

  const trimmedReference = reference?.trim()

  if (trimmedReference) {
    return `Inventaire ${trimmedReference}`
  }

  return 'Inventaire'
}

export function formatInventorySessionHeaderSubtitle(
  reference?: string | null,
  storeName?: string | null,
  scopeLabel?: string | null,
): string {
  const parts: string[] = []
  const trimmedReference = reference?.trim()

  if (trimmedReference) {
    parts.push(trimmedReference)
  }

  const trimmedStoreName = storeName?.trim()

  if (trimmedStoreName) {
    parts.push(trimmedStoreName)
  }

  const trimmedScopeLabel = scopeLabel?.trim()

  if (trimmedScopeLabel) {
    parts.push(trimmedScopeLabel)
  }

  return parts.length > 0 ? parts.join(' · ') : '—'
}

export function scrollInventoryViewToTop(behavior: ScrollBehavior = 'smooth'): void {
  if (typeof window === 'undefined') {
    return
  }

  window.scrollTo({ top: 0, behavior })
}

export type InventoryInertiaFlash = {
  success?: string | null
  error?: string | null
  warning?: string | null
  info?: string | null
}

/** Consomme le flash Inertia après redirect (ex. création inventaire). */
export function resolveInventoryInertiaFlashMessage(
  flash?: InventoryInertiaFlash | null,
): { message: string; className: string } | null {
  const success = flash?.success?.trim()

  if (success) {
    return { message: success, className: 'alert-success' }
  }

  const error = flash?.error?.trim()

  if (error) {
    return { message: error, className: 'alert-danger' }
  }

  return null
}
