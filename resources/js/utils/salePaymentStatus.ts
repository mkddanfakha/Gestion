export type SalePaymentStatus = 'pending' | 'partial' | 'paid'

export interface SalePaymentState {
  downPaymentAmount: number
  remainingAmount: number
  paymentStatus: SalePaymentStatus
}

export function calculateSalePaymentState(
  totalAmount: number,
  downPaymentAmount: number,
): SalePaymentState {
  const total = Math.max(0, totalAmount)
  const paid = Math.max(0, downPaymentAmount)

  if (paid >= total && total > 0) {
    return {
      downPaymentAmount: total,
      remainingAmount: 0,
      paymentStatus: 'paid',
    }
  }

  const remaining = total - paid

  if (paid > 0 && remaining > 0) {
    return {
      downPaymentAmount: paid,
      remainingAmount: remaining,
      paymentStatus: 'partial',
    }
  }

  if (paid === 0 && total > 0) {
    return {
      downPaymentAmount: 0,
      remainingAmount: total,
      paymentStatus: 'pending',
    }
  }

  return {
    downPaymentAmount: paid,
    remainingAmount: Math.max(0, remaining),
    paymentStatus: 'paid',
  }
}

export function getPaymentStatusLabel(status: SalePaymentStatus): string {
  const labels: Record<SalePaymentStatus, string> = {
    pending: 'En attente',
    partial: 'Partiel',
    paid: 'Payé',
  }

  return labels[status]
}

export function getPaymentStatusBadgeClass(status: SalePaymentStatus): string {
  const classes: Record<SalePaymentStatus, string> = {
    pending: 'sale-payment-status sale-payment-status--pending',
    partial: 'sale-payment-status sale-payment-status--partial',
    paid: 'sale-payment-status sale-payment-status--paid',
  }

  return classes[status]
}

export function getPaymentStatusIcon(status: SalePaymentStatus): string {
  const icons: Record<SalePaymentStatus, string> = {
    pending: 'bi-clock-history',
    partial: 'bi-pie-chart',
    paid: 'bi-check-circle-fill',
  }

  return icons[status]
}

/**
 * Détermine le montant réellement enregistré comme paiement,
 * en tenant compte de la saisie espèces (montant reçu).
 */
export function isPaymentMethodRequired(downPaymentAmount: number): boolean {
  return downPaymentAmount > 0
}

export function resolveEffectiveDownPaymentAmount(options: {
  totalAmount: number
  downPaymentAmount: number
  paymentMethod: string
  cashReceivedAmount: number
}): number {
  const total = Math.max(0, options.totalAmount)
  let paid = Math.max(0, options.downPaymentAmount)

  if (options.paymentMethod !== 'cash') {
    return Math.min(paid, total)
  }

  const remaining = Math.max(0, total - paid)

  if (paid === 0 && options.cashReceivedAmount >= total && total > 0) {
    return total
  }

  if (paid > 0 && paid < total && options.cashReceivedAmount >= remaining) {
    return total
  }

  return Math.min(paid, total)
}
