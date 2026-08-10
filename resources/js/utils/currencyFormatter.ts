const CURRENCY_SUFFIX = 'FCFA'

function normalizeGrouping(value: string): string {
  return value.replace(/[\u00a0\u202f]/g, ' ')
}

/**
 * Formate un montant sans suffixe (ex. 75 000).
 */
export function formatCurrencyNumber(amount: number | string | null | undefined): string {
  const value = Number(amount)

  if (!Number.isFinite(value)) {
    return '0'
  }

  const rounded = Math.round(value)
  const formatted = normalizeGrouping(
    new Intl.NumberFormat('fr-FR', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
      useGrouping: true,
    }).format(Math.abs(rounded)),
  )

  return rounded < 0 ? `- ${formatted}` : formatted
}

/**
 * Formate un montant FCFA (ex. 75 000 FCFA).
 */
export function formatCurrency(amount: number | string | null | undefined): string {
  const value = Number(amount)

  if (!Number.isFinite(value)) {
    return `0 ${CURRENCY_SUFFIX}`
  }

  return `${formatCurrencyNumber(value)} ${CURRENCY_SUFFIX}`
}

/** Alias historique pour les prix produits. */
export const formatPrice = formatCurrency
