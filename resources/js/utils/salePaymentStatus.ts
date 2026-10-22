export type SalePaymentStatus = 'pending' | 'partial' | 'paid'

export type SaleCheckoutPaymentType = 'without_deposit' | 'with_deposit'

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
    pending: 'bi-clock-fill',
    partial: 'bi-circle-half',
    paid: 'bi-check-circle-fill',
  }

  return icons[status]
}

export function getCheckoutPaymentTypeIcon(type: SaleCheckoutPaymentType): string {
  const icons: Record<SaleCheckoutPaymentType, string> = {
    without_deposit: 'bi-receipt',
    with_deposit: 'bi-piggy-bank',
  }

  return icons[type]
}

const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: 'Espèces',
  card: 'Carte',
  bank_transfer: 'Virement bancaire',
  check: 'Chèque',
  orange_money: 'Orange Money',
  wave: 'Wave',
}

export function getPaymentMethodLabel(method: string): string {
  if (!method) {
    return 'Non renseigné'
  }

  return PAYMENT_METHOD_LABELS[method] ?? method
}

export function getPaymentMethodIcon(method: string): string {
  const icons: Record<string, string> = {
    cash: 'bi-cash-coin',
    card: 'bi-credit-card-2-front',
    bank_transfer: 'bi-bank',
    check: 'bi-journal-check',
    orange_money: 'bi-phone',
    wave: 'bi-phone',
  }

  return icons[method] || 'bi-wallet2'
}

export function isPaymentMethodRequired(downPaymentAmount: number): boolean {
  return downPaymentAmount > 0
}

/**
 * Mode de paiement obligatoire à la caisse (création de vente).
 */
export function isCheckoutPaymentMethodRequired(options: {
  paymentType: SaleCheckoutPaymentType
  totalAmount: number
  downPaymentAmount: number
}): boolean {
  const total = Math.max(0, options.totalAmount)
  if (total <= 0) {
    return false
  }
  if (options.paymentType === 'without_deposit') {
    return true
  }
  return Math.max(0, options.downPaymentAmount) > 0
}

/**
 * Montant minimal que le client doit remettre (espèces) pour valider la saisie caisse.
 */
export function resolveCashAmountDue(options: {
  totalAmount: number
  downPaymentAmount: number
  paymentType: SaleCheckoutPaymentType
}): number {
  const total = Math.max(0, options.totalAmount)

  if (options.paymentType === 'without_deposit') {
    return total
  }

  return Math.min(Math.max(0, options.downPaymentAmount), total)
}

/**
 * Monnaie à rendre (jamais négative).
 */
export function calculateCashChangeAmount(options: {
  totalAmount: number
  downPaymentAmount: number
  cashReceivedAmount: number
  paymentType: SaleCheckoutPaymentType
}): number {
  const received = Math.max(0, options.cashReceivedAmount)

  if (options.paymentType === 'without_deposit') {
    const total = Math.max(0, options.totalAmount)
    if (received < total) {
      return 0
    }
    return received - total
  }

  const deposit = Math.min(Math.max(0, options.downPaymentAmount), Math.max(0, options.totalAmount))
  if (received < deposit) {
    return 0
  }
  return received - deposit
}

/**
 * Montant réellement encaissé pour la vente (acompte conservé ou total payé).
 */
export function resolveEffectiveDownPaymentAmount(options: {
  totalAmount: number
  downPaymentAmount: number
  paymentMethod: string
  cashReceivedAmount: number
  paymentType: SaleCheckoutPaymentType
}): number {
  const total = Math.max(0, options.totalAmount)
  const deposit = Math.max(0, options.downPaymentAmount)
  const received = Math.max(0, options.cashReceivedAmount)

  if (options.paymentType === 'without_deposit') {
    if (total <= 0 || !options.paymentMethod) {
      return 0
    }
    if (options.paymentMethod === 'cash') {
      if (received >= total) {
        return total
      }
      return 0
    }
    return total
  }

  const cappedDeposit = Math.min(deposit, total)

  if (options.paymentMethod === 'cash') {
    return cappedDeposit
  }

  return cappedDeposit
}
