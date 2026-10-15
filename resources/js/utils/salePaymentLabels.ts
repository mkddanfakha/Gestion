export const SALE_PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: 'Espèces',
  card: 'Carte',
  bank_transfer: 'Virement bancaire',
  check: 'Chèque',
  orange_money: 'Orange Money',
  wave: 'Wave',
}

export function getSalePaymentMethodLabel(method?: string | null): string {
  if (!method) {
    return 'Non renseigné'
  }

  return SALE_PAYMENT_METHOD_LABELS[method] ?? method
}

export const SALE_PAYMENT_STATUS_LABELS: Record<string, string> = {
  paid: 'Payé',
  partial: 'Paiement partiel',
  pending: 'En attente',
}

export function getSalePaymentStatusLabel(status: string): string {
  return SALE_PAYMENT_STATUS_LABELS[status] ?? status
}

export function getSalePaymentOriginLabel(origin: string): string {
  if (origin === 'historical_backfill') {
    return 'Reprise historique'
  }

  return 'Encaissement'
}
