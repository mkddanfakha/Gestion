export type SupplierInvoiceDocumentStatus = 'draft' | 'open' | 'cancelled'

const DOCUMENT_STATUS_LABELS: Record<SupplierInvoiceDocumentStatus, string> = {
  draft: 'Brouillon',
  open: 'Ouverte',
  cancelled: 'Annulée',
}

const DOCUMENT_STATUS_BADGE: Record<SupplierInvoiceDocumentStatus, string> = {
  draft: 'bg-secondary',
  open: 'bg-primary',
  cancelled: 'bg-danger',
}

export function normalizeDocumentStatus(status: string | null | undefined): SupplierInvoiceDocumentStatus {
  if (status === 'open' || status === 'cancelled') {
    return status
  }

  return 'draft'
}

export function getDocumentStatusLabel(status: string | null | undefined): string {
  return DOCUMENT_STATUS_LABELS[normalizeDocumentStatus(status)]
}

export function getDocumentStatusBadgeClass(status: string | null | undefined): string {
  return DOCUMENT_STATUS_BADGE[normalizeDocumentStatus(status)]
}

export function isActiveSupplierDebt(
  documentStatus: string | null | undefined,
  remainingAmount: number | string | null | undefined,
): boolean {
  if (normalizeDocumentStatus(documentStatus) !== 'open') {
    return false
  }

  const remaining = Number(remainingAmount)

  return Number.isFinite(remaining) && remaining > 0.009
}

/**
 * Retard : facture ouverte, solde dû positif, échéance strictement avant aujourd'hui (date locale).
 */
export function isSupplierInvoiceOverdue(
  documentStatus: string | null | undefined,
  remainingAmount: number | string | null | undefined,
  dueDate: string | null | undefined,
): boolean {
  if (!isActiveSupplierDebt(documentStatus, remainingAmount) || !dueDate) {
    return false
  }

  const dueDay = dueDate.slice(0, 10)
  const today = new Date()
  const todayLocal = [
    today.getFullYear(),
    String(today.getMonth() + 1).padStart(2, '0'),
    String(today.getDate()).padStart(2, '0'),
  ].join('-')

  return dueDay < todayLocal
}
